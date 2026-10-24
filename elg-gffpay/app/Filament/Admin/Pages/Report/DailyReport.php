<?php

namespace App\Filament\Admin\Pages\Report;

use App\Enums\Db\OrderStatusEnum;
use App\Models\Currency;
use App\Models\Merchant;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class DailyReport extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected string $view = 'filament.ton.pages.table';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = null;

    protected static string|UnitEnum|null $navigationGroup = null;

    public function getTitle(): string
    {
        return __('filament.pages.daily_report.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.pages.daily_report.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.reports');
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record->date . '_' . $record->merchant_code . '_' . $record->currency_id;
    }

    protected function getTableQuery()
    {
        return Order::query()
            ->join('merchants', 'orders.merchant_id', '=', 'merchants.id')
            ->join('currencies', 'orders.currency_id', '=', 'currencies.id')
            ->selectRaw('
                DATE(orders.created_at) as date,
                merchants.name as merchant_name,
                merchants.code as merchant_code,
                currencies.name as currency_name,
                currencies.id as currency_id,
                SUM(orders.amount) as total_amount,
                COUNT(*) as total_orders
            ')
            ->where('orders.status', OrderStatusEnum::SUCCESS->value)
            ->groupBy(DB::raw('DATE(orders.created_at)'), 'merchants.id', 'merchants.name', 'merchants.code', 'currencies.id', 'currencies.name')
            ->orderByDesc('date');
    }

    protected function getDefaultTableFilters(): array
    {
        return [
            'date_range' => [
                'date_from' => Carbon::now()->startOfMonth()->format('Y-m-d'),
                'date_to' => Carbon::now()->endOfMonth()->format('Y-m-d'),
            ],
        ];
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('date')
                ->label(__('filament.pages.daily_report.date'))
                ->sortable(),

            TextColumn::make('merchant_name')
                ->label(__('filament.pages.daily_report.merchant_name'))
                ->sortable()
                ->searchable(query: function ($query, string $search) {
                    $query->where('merchants.name', 'like', "%{$search}%");
                }),

            TextColumn::make('merchant_code')
                ->label(__('filament.pages.daily_report.merchant_code'))
                ->searchable(query: function ($query, string $search) {
                    $query->where('merchants.code', 'like', "%{$search}%");
                }),

            TextColumn::make('currency_name')
                ->label(__('filament.pages.daily_report.currency_name'))
                ->searchable(query: function ($query, string $search) {
                    $query->where('currencies.name', 'like', "%{$search}%");
                }),

            TextColumn::make('total_orders')
                ->label(__('filament.pages.daily_report.total_orders'))
                ->numeric()
                ->alignRight()
                ->summarize([
                    Sum::make()->numeric(),
                ]),

            TextColumn::make('total_amount')
                ->label(__('filament.pages.daily_report.total_amount'))
                ->numeric(9)
                ->alignRight()
                ->summarize([
                    Sum::make()->numeric(9),
                ]),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Filter::make('date_range')
                ->schema([
                    DatePicker::make('date_from')
                        ->label(__('filament.pages.daily_report.date_from'))
                        ->displayFormat('Y-m-d')
                        ->default(Carbon::now()->startOfMonth()),
                    DatePicker::make('date_to')
                        ->label(__('filament.pages.daily_report.date_to'))
                        ->displayFormat('Y-m-d')
                        ->default(Carbon::now()->endOfMonth()),
                ])
                ->query(function ($query, array $data) {
                    $dateFrom = $data['date_from'] ?? null;
                    $dateTo = $data['date_to'] ?? null;

                    return $query
                        ->when($dateFrom, fn ($query) => $query->where('orders.created_at', '>=', $dateFrom . ' 00:00:00'))
                        ->when($dateTo, fn ($query) => $query->where('orders.created_at', '<=', $dateTo . ' 23:59:59'));
                })
                ->indicateUsing(function (array $data): ?string {
                    if (empty($data['date_from']) && empty($data['date_to'])) {
                        return null;
                    }

                    $dateFrom = $data['date_from'] ?? null;
                    $dateTo = $data['date_to'] ?? null;

                    $indicator = __('filament.pages.daily_report.date_range') . ': ';
                    if ($dateFrom) {
                        $indicator .= __('filament.pages.daily_report.from') . ' ' . $dateFrom;
                    }
                    if ($dateTo) {
                        $indicator .= ' ' . __('filament.pages.daily_report.to') . ' ' . $dateTo;
                    }

                    return $indicator;
                }),

            SelectFilter::make('merchant_id')
                ->label(__('filament.pages.daily_report.merchant_name'))
                ->options(Merchant::pluck('name', 'id'))
                ->searchable()
                ->preload(),

            SelectFilter::make('currency_id')
                ->label(__('filament.pages.daily_report.currency_name'))
                ->options(Currency::pluck('name', 'id'))
                ->searchable()
                ->preload(),
        ];
    }

    protected function getTableHeaderActions(): array
    {
        $totals = $this->calculateTotals();

        return [
            Action::make('showTotals')
                ->label(__('filament.pages.daily_report.show_totals'))
                ->icon('heroicon-o-calculator')
                ->schema([
                    TextEntry::make('total_orders')
                        ->label(__('filament.pages.daily_report.total_orders'))
                        ->state($totals['total_orders']),
                    TextEntry::make('total_amount')
                        ->label(__('filament.pages.daily_report.total_amount'))
                        ->numeric(9)
                        ->state($totals['total_amount']),
                ])
                ->modalHeading(__('filament.pages.daily_report.all_data_totals'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('filament.pages.daily_report.close')),
        ];
    }

    protected function calculateTotals(): array
    {
        $query = Order::query()
            ->join('merchants', 'orders.merchant_id', '=', 'merchants.id')
            ->join('currencies', 'orders.currency_id', '=', 'currencies.id');

        $dateRangeFilter = $this->getTableFilterState('date_range');
        $merchantIdFilter = $this->getTableFilterState('merchant_id');
        $currencyIdFilter = $this->getTableFilterState('currency_id');

        $dateFrom = $dateRangeFilter['date_from'] ?? Carbon::now()->startOfMonth()->format('Y-m-d');
        $dateTo = $dateRangeFilter['date_to'] ?? Carbon::now()->endOfMonth()->format('Y-m-d');

        $query->where('orders.created_at', '>=', $dateFrom . ' 00:00:00');
        $query->where('orders.created_at', '<=', $dateTo . ' 23:59:59');

        if ($merchantIdFilter['value'] ?? null) {
            $query->where('orders.merchant_id', $merchantIdFilter['value']);
        }
        if ($currencyIdFilter['value'] ?? null) {
            $query->where('orders.currency_id', $currencyIdFilter['value']);
        }

        $result = $query->selectRaw('
            SUM(orders.amount) as total_amount,
            COUNT(*) as total_orders
        ')->first();

        return [
            'total_orders' => $result->total_orders ?? 0,
            'total_amount' => $result->total_amount ?? 0,
        ];
    }
}
