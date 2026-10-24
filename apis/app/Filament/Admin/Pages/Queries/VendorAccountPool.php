<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Queries;

use App\Filament\Admin\Pages\Concerns\AuthorizesByUserRole;
use App\Filament\Admin\Pages\Concerns\RemembersFormState;
use App\Models\Vendor;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\SupportsAccountCreation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * 帳號庫存查詢：逐商戶列出未綁定帳號數、目標水位（prepared_account_number）與缺口，
 * 判斷帳號池庫存是否足夠、排程有沒有跟上。
 *
 * 純查詢頁，不寫入任何資料；水位計算與補建排程共用 VendorAccountPoolService 同一口徑。
 * 缺口要立即補建時走「系統操作 → 補建遊戲帳號」。
 *
 * @knowledge rules/vendor-account-pool
 */
class VendorAccountPool extends Page
{
    use AuthorizesByUserRole;
    use RemembersFormState;

    protected static string|\UnitEnum|null $navigationGroup = '查詢';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $title = '帳號庫存查詢';

    protected string $view = 'filament.admin.pages.queries.vendor-account-pool';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array{rows: list<array<string, mixed>>, errors: list<array<string, mixed>>}|null */
    public ?array $results = null;

    /** @var array<string, string>|null */
    public ?array $context = null;

    public function mount(): void
    {
        $this->fillFormWithMemory($this->requestPrefill(['vendor_id']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vendor_id')
                    ->label('遊戲商')
                    ->placeholder('全部遊戲商')
                    ->helperText('只列 driver 支援建帳號的遊戲商；留空 = 全部逐商戶盤點')
                    ->options(
                        Vendor::query()
                            ->whereIn('id', self::supportedVendorIds())
                            ->where('status', 1)
                            ->orderBy('id')
                            ->pluck('name', 'id')
                            ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                    )
                    ->searchable()
                    ->optionsLimit(500),
                Toggle::make('only_lacking')
                    ->label('只顯示庫存不足的商戶')
                    ->default(false),
            ])
            ->statePath('data');
    }

    public function search(VendorAccountPoolService $service): void
    {
        $data = $this->getSchema('form')?->getState() ?? [];
        $this->rememberFormState($data);

        $vendorId = !empty($data['vendor_id']) ? (int) $data['vendor_id'] : null;
        $results = $service->inventory($vendorId);

        if (!empty($data['only_lacking'])) {
            $results['rows'] = array_values(array_filter(
                $results['rows'],
                fn (array $row): bool => $row['lack'] > 0,
            ));
        }

        $this->results = $results;
        $this->context = [
            'vendor' => $vendorId !== null
                ? sprintf('(%d) %s', $vendorId, Vendor::find($vendorId)?->name ?? '')
                : '全部遊戲商',
            'queried_at' => now()->setTimezone(AdminPanelProvider::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * driver 有實作 SupportsAccountCreation 的 vendor id（可手動補建即可查庫存）
     *
     * @return list<int>
     */
    private static function supportedVendorIds(): array
    {
        return array_keys(array_filter(
            config('vendor.driver', []),
            fn (string $driverClass): bool => is_subclass_of($driverClass, SupportsAccountCreation::class),
        ));
    }
}
