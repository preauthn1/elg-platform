<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LanguageEnum;
use App\Models\Language;
use RuntimeException;

/**
 * php artisan db:seed --class=SyncLanguagesSeeder
 *
 * 依 database/data/languages.php 補上缺少的語系；已存在的 code 會跳過，不會覆蓋既有資料。
 * 執行後需清快取（active_languages），DetectLanguage middleware 才會認得新語系。
 */
class SyncLanguagesSeeder extends AbstractSyncSeeder
{
    protected string $dataFile = 'languages.php';

    protected string $modelClass = Language::class;

    protected string $uniqueKey = 'code';

    protected bool $isTest = false;

    protected array $default = [
        'name' => '',
        'code' => '',
        'status' => 1,
        'admin' => 0,
    ];

    protected function isValid(array $data, $key): bool
    {
        return !empty($data['id']) && !empty($data['name']) && !empty($data['code']);
    }

    protected function getLogMessage(array $data, $key): string
    {
        return "新增語系 {$data['name']} ({$data['code']}) id={$data['id']}";
    }

    protected function prepareItems(array $toInsert): array
    {
        $this->assertEnumInSync($toInsert);
        $this->assertIdsAvailable($toInsert);

        return parent::prepareItems($this->appendSort($toInsert));
    }

    /**
     * 新語系排在既有語系之後，避免插隊改動使用者已熟悉的語系順序。
     */
    private function appendSort(array $toInsert): array
    {
        $sort = (int) Language::max('sort');

        foreach ($toInsert as $index => $item) {
            $toInsert[$index]['data']['sort'] = ++$sort;
        }

        return $toInsert;
    }

    /**
     * id 必須在 LanguageEnum 有對應 case，否則該語系的會員一開遊戲就會踩到
     * LanguageEnum::from() 的 ValueError。
     */
    private function assertEnumInSync(array $toInsert): void
    {
        foreach ($toInsert as $item) {
            $id = (int) ($item['data']['id'] ?? 0);

            if (LanguageEnum::tryFrom($id) === null) {
                throw new RuntimeException(
                    "LanguageEnum 缺少 id={$id}（{$item['data']['code']}）的 case，請先補 App\\Enums\\LanguageEnum 再執行。"
                );
            }
        }
    }

    /**
     * id 在資料檔中寫死，若已被其他語系佔用就中止，
     * 以免 enum 的 case 對到資料庫裡另一個語系。
     */
    private function assertIdsAvailable(array $toInsert): void
    {
        $ids = array_column(array_column($toInsert, 'data'), 'id');

        $taken = Language::whereIn('id', $ids)->pluck('code', 'id');

        if ($taken->isNotEmpty()) {
            throw new RuntimeException(
                'languages.id 已被佔用：' . $taken->map(
                    fn ($code, $id) => "{$id}={$code}"
                )->implode('、') . '，請確認 LanguageEnum 的 id 規劃。'
            );
        }
    }
}
