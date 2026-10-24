<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Queries;

use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Filament\Admin\Pages\Concerns\AuthorizesByUserRole;
use App\Filament\Admin\Pages\Concerns\RemembersFormState;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordCorrectionQueryService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * 補帳查詢：同一個「遊戲商 × 商戶 × 日期」拉出 SBR 調帳明細、SBR / TBR 區間合計
 * 與遊戲商注單合計四份對照，判斷帳是否對得上、以及要補多少。
 *
 * 純查詢頁，不寫入任何資料。日界依 vendors.calc_time_zone。
 *
 * 查詢條件在送出後記進 session，回到頁面時沿用；「重跑注單彙總」頁接續帶來的網址參數優先於記憶。
 */
class BetRecordCorrection extends Page
{
    use AuthorizesByUserRole;
    use RemembersFormState;

    /** 對帳可選的時區時差（小時 => 顯示） */
    private const array TIMEZONE_OFFSETS = [
        -4 => 'UTC-4',
        0 => 'UTC+0',
        7 => 'UTC+7',
        8 => 'UTC+8',
    ];

    protected static string|\UnitEnum|null $navigationGroup = '查詢';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $title = '補帳查詢';

    protected string $view = 'filament.admin.pages.queries.bet-record-correction';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    /** @var array<string, string>|null */
    public ?array $context = null;

    /**
     * 該遊戲商啟用中的一般商戶選項（活動商戶另有帳，不列）。
     *
     * @return array<int, string>
     */
    private static function vendorInfoOptions(mixed $vendorId): array
    {
        if (!$vendorId) {
            return [];
        }

        return VendorInfo::query()
            ->where('vendor_id', (int) $vendorId)
            ->where('status', 1)
            ->where('type', '!=', VendorInfoTypeEnum::EVENT_V2->value)
            ->orderBy('id')
            ->get(['id', 'name', 'currency'])
            ->mapWithKeys(fn (VendorInfo $info): array => [
                $info->id => sprintf('(%d) %s %s', $info->id, $info->name, $info->currency),
            ])
            ->all();
    }

    public function mount(): void
    {
        $this->fillFormWithMemory($this->requestPrefill(['vendor_id', 'vendor_info_id', 'timezone', 'date']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('遊戲商')
                    ->options(
                        Vendor::query()
                            // 只列有 Driver 的遊戲商：注單合計需要 driver 的 betRecordModel
                            ->whereIn('id', array_keys(config('vendor.driver', [])))
                            ->where('name', '!=', '')
                            ->orderBy('id')
                            ->pluck('name', 'id')
                            ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                    )
                    ->searchable()
                    ->optionsLimit(500)
                    // 換遊戲商時把時區帶成該商的 calc_time_zone
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('timezone', Vendor::find($state)?->calc_time_zone);
                        // 商戶隸屬於遊戲商，換商就把已選商戶歸零
                        $set('vendor_info_id', null);
                    })
                    ->required(),
                Select::make('vendor_info_id')
                    ->label('商戶')
                    ->placeholder('全部商戶')
                    ->helperText('三方對帳以商戶為單位；留空 = 該遊戲商全部啟用中商戶')
                    ->options(fn (Get $get): array => self::vendorInfoOptions($get('vendor_id'))),
                Select::make('timezone')
                    ->label('時區')
                    ->helperText('預設帶該遊戲商的 calc_time_zone，可改成其他時區重算日界')
                    ->options(self::TIMEZONE_OFFSETS)
                    ->selectablePlaceholder(false)
                    ->required(),
                DatePicker::make('date')
                    ->label('日期')
                    // 原生 date input 只有右側圖示能開月曆，改用 Filament 自己的 picker 讓點欄位就跳出
                    ->native(false)
                    // 預設顯示 'M j, Y'（Aug 17, 2026），改成 Y-m-d 比較直觀
                    ->displayFormat('Y-m-d')
                    ->helperText('以上方時區的日界換算成 UTC 區間')
                    ->default(fn (): string => Carbon::now('+08:00')->subDay()->format('Y-m-d'))
                    ->required(),
            ])
            ->statePath('data');
    }

    public function search(BetRecordCorrectionQueryService $service): void
    {
        $data = $this->getSchema('form')?->getState() ?? [];
        $this->rememberFormState($data);

        $vendor = Vendor::find($data['vendor_id']);

        if (!$vendor) {
            Notification::make()->danger()->title('遊戲商不存在')->send();

            return;
        }

        $vendorInfo = null;

        if (!empty($data['vendor_info_id'])) {
            $vendorInfo = VendorInfo::query()
                ->where('vendor_id', $vendor->id)
                ->find($data['vendor_info_id']);

            if (!$vendorInfo) {
                Notification::make()->danger()->title('商戶不存在或不屬於此遊戲商')->send();

                return;
            }
        }

        $this->results = $service->query(
            $vendor,
            $vendorInfo,
            $data['date'],
            isset($data['timezone']) ? (int) $data['timezone'] : null,
        );
        $this->context = [
            'vendor' => sprintf('(%d) %s', $vendor->id, $vendor->name),
            'vendor_info' => $vendorInfo
                ? sprintf('(%d) %s %s', $vendorInfo->id, $vendorInfo->name, $vendorInfo->currency)
                : '全部商戶',
            'date' => $data['date'],
        ];
    }
}
