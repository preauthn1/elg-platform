<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Collection;

/**
 * 掃描 Redis 記憶體佔用，找出「誰把空間吃掉了」（redis:analyze-memory 的後台版）。
 *
 * 2026-09-23 TPower 的 1GB Memorystore 從 20% 漲到 99.5%，全站 Redis 寫入被拒約一分鐘
 * （含會員轉帳）；當時只能從 GCP 指標看到「key 數 +625、記憶體 +500MB」，看不出是哪一類 key
 * 佔走的。這頁就是為了不必再猜（target/tickets#4417）。
 *
 * 唯讀：只取 key 名與 MEMORY USAGE，不讀 value、不刪不改。
 */
class RedisMemoryAnalyze extends AbstractOperationPage
{
    /** 一次掃描的 key 數沒有上限，但 TOP N 過大只會讓輸出難讀 */
    private const int MAX_TOP = 200;

    /** 前綴層數：超過 5 層等於逐 key 列出，失去聚合意義 */
    private const int MAX_DEPTH = 5;

    protected static ?int $navigationSort = 6;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::RedisMemoryAnalyze;
    }

    public function getSubheading(): ?string
    {
        return '掃描指定連線的所有 key，統計哪一類前綴佔走最多空間，並列出最大的幾個 key。'
            . '全程唯讀（只取 key 名與大小，不讀取 value、不刪改），掃描走 SCAN 游標分批、分段讓出，不會阻塞 Redis。'
            . '送出的命令數等於 key 總數，會讓 Redis 負載略為上升；記憶體超過 2GB 建議改以 RDB 匯出後離線分析。';
    }

    protected function formComponents(): array
    {
        return [
            Select::make('connection')
                ->label('掃描哪個 DB')
                ->helperText('一個 DB 可能對應多個連線設定（括號內），掃的是整個 DB，選哪一個連線名結果都一樣')
                ->options(self::connectionOptions())
                ->default('default')
                ->required(),
            TextInput::make('top')
                ->label('列出最大的幾個 key')
                ->numeric()
                ->default(30)
                ->minValue(1)
                ->maxValue(self::MAX_TOP),
            TextInput::make('depth')
                ->label('前綴取幾層')
                ->helperText('以冒號分段，例如取 2 層時 vendor_account_bind:12:906965 會歸到 vendor_account_bind:12:*')
                ->numeric()
                ->default(2)
                ->minValue(1)
                ->maxValue(self::MAX_DEPTH),
        ];
    }

    /**
     * 可選的掃描對象：**以 database 為單位**，不是以連線名。
     *
     * config 裡 7 個連線只對應 4 個 DB（default／cache／json 同為 DB 0，queue／horizon 同為 DB 3），
     * 逐個連線列出來會讓操作者以為有 7 種掃法，實際上選 default 與選 cache 掃到的是同一批 key。
     * 每個 DB 只留一個選項，括號內標出共用同一個 DB 的連線名。
     *
     * 排除 client／options 這兩個非連線設定；沒有 database 的設定不列。
     *
     * @return array<string, string>
     */
    public static function connectionOptions(): array
    {
        return collect(config('database.redis', []))
            ->except(['client', 'options'])
            ->filter(fn (mixed $config): bool => is_array($config) && isset($config['database']))
            // 保留連線名當 key：分組後還要用它當實際要連的連線
            ->groupBy(fn (array $config): string => (string) $config['database'], preserveKeys: true)
            ->sortKeys(SORT_NUMERIC)
            ->mapWithKeys(function (Collection $sameDb, string $database): array {
                $names = $sameDb->keys();

                return [$names->first() => sprintf('DB %s（%s）', $database, $names->implode('、'))];
            })
            ->all();
    }

    protected function buildParams(array $data): array
    {
        return [
            '--connection' => (string) $data['connection'],
            '--top' => (string) ($data['top'] ?? 30),
            '--depth' => (string) ($data['depth'] ?? 2),
        ];
    }
}
