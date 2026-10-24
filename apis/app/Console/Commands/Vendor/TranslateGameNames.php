<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Ai\Agents\GameNameTranslatorAgent;
use App\Helpers\Common;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslateGameNames extends Command
{
    protected $signature = 'vendor:translate-game-names
                            {vendorId : 遊戲商 ID（對應 games.vendor_id）}
                            {--language= : 只翻譯指定的 language_id}';

    protected $description = '使用 AI 將指定遊戲商的遊戲名稱批次翻譯成各語言並寫入 game_names';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendorId');

        $games = Game::where('vendor_id', $vendorId)->where('status', 1)->get(['id', 'name']);

        if ($games->isEmpty()) {
            $this->error("找不到 vendor_id={$vendorId} 的遊戲，請確認 vendorId 是否正確。");

            return self::FAILURE;
        }

        $languagesQuery = Language::where('id', '!=', 1);
        if ($this->option('language')) {
            $languagesQuery->where('id', (int) $this->option('language'));
        }
        $languages = $languagesQuery->get();

        $gameIds = $games->pluck('id')->toArray();
        $nameToIdMap = $games->pluck('id', 'name')->toArray();

        $this->output->progressStart($languages->count());

        foreach ($languages as $language) {
            $translatedGameIds = GameName::where('language_id', $language->id)
                ->whereIn('game_id', $gameIds)
                ->pluck('game_id')
                ->toArray();

            $untranslatedGames = $games->whereNotIn('id', $translatedGameIds);

            if ($untranslatedGames->isEmpty()) {
                $this->line("  [{$language->name}] 全部遊戲已翻譯，跳過。");
                $this->output->progressAdvance();

                continue;
            }

            $names = $untranslatedGames->pluck('name')->values()->toArray();

            try {
                $response = GameNameTranslatorAgent::make()
                    ->prompt("Translate these game names to {$language->name}:\n" . json_encode($names, JSON_UNESCAPED_UNICODE));

                $translations = $response['translations'] ?? [];

                if (count($translations) < count($names)) {
                    Log::warning('GameNameTranslator: AI 回傳翻譯數量少於送出數量', [
                        'vendor_id' => $vendorId,
                        'language' => $language->name,
                        'sent' => count($names),
                        'received' => count($translations),
                    ]);
                }

                $rows = [];
                foreach ($translations as $translation) {
                    $original = $translation['original'] ?? '';
                    $translated = $translation['translated'] ?? '';

                    if (!$original || !$translated || !isset($nameToIdMap[$original])) {
                        continue;
                    }

                    $rows[] = [
                        'language_id' => $language->id,
                        'game_id' => $nameToIdMap[$original],
                        'name' => $translated,
                        'admin' => 0,
                    ];
                }

                if (!empty($rows)) {
                    DB::beginTransaction();
                    try {
                        GameName::insert($rows);
                        DB::commit();
                    } catch (Throwable $e) {
                        DB::rollBack();
                        throw $e;
                    }
                    $this->line("  [{$language->name}] 已翻譯 " . count($rows) . ' 筆遊戲。');
                }
            } catch (Throwable $e) {
                Log::error('GameNameTranslator: 翻譯失敗', [
                    'vendor_id' => $vendorId,
                    'language' => $language->name,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
                $this->error("  [{$language->name}] 翻譯失敗：{$e->getMessage()}");
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info('完成。');

        return self::SUCCESS;
    }
}
