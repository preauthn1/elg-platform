<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Services\SchemaOperationService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * 資料表加索引／加欄位（schema_operation）。
 *
 * 加索引＝一律建 {table}_mirror 鏡像表（trigger 同步＋EVENT 分段回填），不分表大小、不直接 ALTER（理由見 SchemaOperationService）；
 * 加欄位＝ALGORITHM=INSTANT（metadata 瞬間，實測大 buffer pool 站台仍有 20 秒級全庫停頓，離峰執行）；換表轉正＝全量回填後原子 RENAME 並整包收尾。
 * 乾跑預設開啟：先送出看產出的 SQL，確認無誤再關掉乾跑正式執行。
 */
class SchemaOperation extends AbstractOperationPage
{
    /** 加欄位型別選項；長度／精度另欄填，組合後須通過 SchemaOperationService::COLUMN_TYPE_PATTERN 白名單 */
    public const array COLUMN_TYPES = ['VARCHAR', 'CHAR', 'DECIMAL', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DATETIME', 'DATE', 'TIMESTAMP', 'TIME', 'JSON', 'TEXT'];

    /** 需要填長度（VARCHAR／CHAR）或精度,小數位（DECIMAL）的型別 */
    public const array SIZED_TYPES = ['VARCHAR', 'CHAR', 'DECIMAL'];

    /** 可加 UNSIGNED 的整數型別 */
    public const array INT_TYPES = ['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'];

    protected static ?int $navigationSort = 4;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::SchemaOperation;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            '本工具適用於<strong>大表的線上 DDL</strong>，專案的 migration 不會自動跟上——<strong>操作完成後仍需補一支對應 migration</strong>'
            . '（用 hasIndex／hasColumn 判斷、已存在則跳過），測試環境與新站台的 schema 才會一致。',
        );
    }

    protected function formComponents(): array
    {
        return [
            Select::make('action')
                ->label('動作')
                ->options([
                    'index' => '加索引（建鏡像表＋EVENT 回填；不分表大小，直接 ALTER 會讓 replica 整句重跑、落後分鐘級）',
                    'column' => '加欄位（INSTANT；metadata 瞬間，但 TPower 實測 MySQL 端 24 秒且全庫停頓，一律離峰執行）',
                    'status' => '查看回填進度',
                    'rename' => '換表轉正（回填完成後鏡像→原表原子 RENAME；部分回填時 id ≤ min_id 的舊資料只留在退場表，乾跑會列出筆數）',
                    'cancel' => '取消回填（拆除 EVENT／trigger／鏡像表，已回填資料一併刪除）',
                    'drop_retired' => '刪除退場表（換表轉正後留下的 {原表}_YYYYMMDD 舊原表，觀察穩定後釋放空間）',
                ])
                ->default('index')
                ->live()
                ->required(),
            Select::make('table')
                ->label('資料表')
                ->helperText('查看回填進度可留空＝列出全部')
                // cancel／rename／status 的對象只可能是有鏡像建置痕跡的表、drop_retired 只可能是退場表，
                // 依動作切換清單，不逼操作者在全部業務表裡撈
                ->options(fn (Get $get): array => match (true) {
                    $get('action') === 'drop_retired' => app(SchemaOperationService::class)->listRetiredTables(),
                    in_array($get('action'), ['cancel', 'rename', 'status'], true) => app(SchemaOperationService::class)->listMirroredTables(),
                    default => app(SchemaOperationService::class)->listTables(),
                })
                ->searchable()
                // Filament Select 預設 optionsLimit=50，會截斷完整資料表清單
                ->optionsLimit(1000)
                ->live()
                ->required(fn (Get $get): bool => $get('action') !== 'status'),

            // ── 加索引 ──────────────────────────────
            Select::make('column')
                ->label('索引欄位')
                ->options(fn (Get $get): array => filled($get('table'))
                    ? app(SchemaOperationService::class)->listColumns((string) $get('table'))
                    : [])
                ->searchable()
                ->optionsLimit(200)
                ->visible(fn (Get $get): bool => $get('action') === 'index')
                ->required(fn (Get $get): bool => $get('action') === 'index'),
            TextInput::make('min_id')
                ->label('回填下限 id（不含）')
                ->helperText('0＝整張表都補。對帳實務只回看 3 個月，可填 90 天前的邊界 id（找法見 docs/reconcile/payout-time-鏡像表建置.md）')
                ->numeric()
                ->default(0)
                ->visible(fn (Get $get): bool => $get('action') === 'index'),
            TextInput::make('chunk')
                ->label('每段回填筆數')
                ->helperText('一般 20000；每列較胖（大 JSON 欄位）建議 8000')
                ->numeric()
                ->default(20000)
                ->visible(fn (Get $get): bool => $get('action') === 'index'),

            // ── 加欄位 ──────────────────────────────
            TextInput::make('name')
                ->label('欄位名稱')
                ->maxLength(64)
                ->visible(fn (Get $get): bool => $get('action') === 'column')
                ->required(fn (Get $get): bool => $get('action') === 'column'),
            Select::make('type')
                ->label('欄位型別')
                ->options(array_combine(self::COLUMN_TYPES, self::COLUMN_TYPES))
                ->live()
                ->visible(fn (Get $get): bool => $get('action') === 'column')
                ->required(fn (Get $get): bool => $get('action') === 'column'),
            TextInput::make('length')
                ->label(fn (Get $get): string => $get('type') === 'DECIMAL' ? '精度,小數位' : '長度')
                ->placeholder(fn (Get $get): string => $get('type') === 'DECIMAL' ? '18,4' : '100')
                ->maxLength(6)
                ->regex(fn (Get $get): string => $get('type') === 'DECIMAL' ? '/^\d{1,2},\d{1,2}$/' : '/^\d{1,4}$/')
                ->visible(fn (Get $get): bool => $get('action') === 'column' && in_array($get('type'), self::SIZED_TYPES, true))
                ->required(fn (Get $get): bool => $get('action') === 'column' && in_array($get('type'), self::SIZED_TYPES, true)),
            Toggle::make('unsigned')
                ->label('UNSIGNED')
                ->visible(fn (Get $get): bool => $get('action') === 'column' && in_array($get('type'), self::INT_TYPES, true)),
            Toggle::make('nullable')
                ->label('允許 NULL')
                ->visible(fn (Get $get): bool => $get('action') === 'column'),
            TextInput::make('default')
                ->label('預設值（可留空）')
                ->helperText('字面值；JSON／TEXT 不支援')
                ->maxLength(100)
                ->visible(fn (Get $get): bool => $get('action') === 'column'),
            TextInput::make('comment')
                ->label('欄位註解（可留空）')
                ->maxLength(100)
                ->visible(fn (Get $get): bool => $get('action') === 'column'),

            Toggle::make('dry_run')
                ->label('乾跑（只列 SQL 不執行）')
                ->helperText('生產 DDL 防呆：先乾跑看 SQL，執行紀錄確認無誤後再關掉本開關正式執行')
                ->default(true)
                // 查看進度是唯讀動作，沒有乾跑的概念
                ->visible(fn (Get $get): bool => $get('action') !== 'status'),
        ];
    }

    protected function buildParams(array $data): array
    {
        $action = (string) $data['action'];

        $params = ['--action' => $action];

        if ($action !== 'status' || filled($data['table'] ?? null)) {
            $params['--table'] = (string) ($data['table'] ?? '');
        }

        if ($action === 'index') {
            $params['--column'] = (string) ($data['column'] ?? '');
            $params['--min-id'] = (string) ($data['min_id'] ?? '0');
            $params['--chunk'] = (string) ($data['chunk'] ?? '20000');
        }

        if ($action === 'column') {
            $params['--name'] = (string) ($data['name'] ?? '');
            $params['--type'] = self::composeType($data);

            if ($data['nullable'] ?? false) {
                $params['--nullable'] = true;
            }

            if (filled($data['default'] ?? null)) {
                $params['--default'] = (string) $data['default'];
            }

            if (filled($data['comment'] ?? null)) {
                $params['--comment'] = (string) $data['comment'];
            }
        }

        if ($action !== 'status' && ($data['dry_run'] ?? false)) {
            $params['--dry-run'] = true;
        }

        return $params;
    }

    /**
     * 把表單的「型別／長度／UNSIGNED」組成 SchemaOperationService 白名單接受的型別字串，
     * 如 VARCHAR(100)、DECIMAL(18,4)、INT UNSIGNED、DATETIME。
     *
     * @param  array<string, mixed>  $data
     */
    public static function composeType(array $data): string
    {
        $type = strtoupper(trim((string) ($data['type'] ?? '')));

        if (in_array($type, self::SIZED_TYPES, true)) {
            $type .= sprintf('(%s)', str_replace(' ', '', (string) ($data['length'] ?? '')));
        }

        if (in_array($type, self::INT_TYPES, true) && ($data['unsigned'] ?? false)) {
            $type .= ' UNSIGNED';
        }

        return $type;
    }
}
