<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Filament\Admin\Pages\Concerns\AuthorizesByUserRole;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * 系統操作的統一執行紀錄檢視，支援以時間、執行者、操作項目過濾。
 */
class OperationLogs extends Page implements HasTable
{
    use AuthorizesByUserRole;
    use InteractsWithTable;

    /** 時間欄位顯示格式（與 AbstractOperationPage::displayTime 一致；測試引用同一份避免 drift） */
    public const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    protected static string|\UnitEnum|null $navigationGroup = '系統操作';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $title = '操作紀錄';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.admin.pages.operations.operation-logs';

    public function table(Table $table): Table
    {
        return $table
            ->query(AdminOperationLog::query()->with('queueJobRun')->latest('id'))
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('operation')
                    ->label('操作')
                    ->formatStateUsing(fn (string $state): string => AdminOperationEnum::tryFrom($state)?->label() ?? $state),
                TextColumn::make('user.username')
                    ->label('執行者'),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (AdminOperationStatusEnum $state): string => $state->label())
                    ->color(fn (AdminOperationStatusEnum $state): string => $state->color()),
                TextColumn::make('params')
                    ->label('參數')
                    ->state(fn (AdminOperationLog $record): string => $record->params
                        ? json_encode($record->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : '-'),
                // 格式對齊頁面其他地方的時間顯示（AbstractOperationPage::displayTime）：
                // Filament 預設的 'Sep 23, 2026 14:48:03' 與本專案其餘欄位不一致，排查時也難跟 log 對照
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime(self::DATETIME_FORMAT),
                TextColumn::make('started_at')
                    ->label('開始')
                    ->dateTime(self::DATETIME_FORMAT),
                TextColumn::make('finished_at')
                    ->label('結束')
                    ->dateTime(self::DATETIME_FORMAT),
            ])
            ->filters([
                SelectFilter::make('operation')
                    ->label('操作')
                    ->options(
                        collect(AdminOperationEnum::cases())
                            ->mapWithKeys(fn (AdminOperationEnum $operation): array => [$operation->value => $operation->label()])
                            ->all(),
                    ),
                SelectFilter::make('user_id')
                    ->label('執行者')
                    ->options(
                        fn (): array => User::query()
                            ->whereIn('id', AdminOperationLog::query()->select('user_id')->distinct())
                            ->orderBy('username')
                            ->pluck('username', 'id')
                            ->all(),
                    )
                    ->searchable(),
                Filter::make('created_at')
                    ->label('時間')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('起始日期'),
                        DatePicker::make('created_until')
                            ->label('結束日期'),
                    ])
                    // 欄位顯示的是 +08:00，過濾的日界也要照 +08:00 算再換回 UTC 比對，否則深夜的紀錄會落到前一天
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '>=', Carbon::parse($date, AdminPanelProvider::DISPLAY_TIMEZONE)->startOfDay()->utc()))
                        ->when($data['created_until'] ?? null, fn (Builder $query, string $date): Builder => $query
                            ->where('created_at', '<', Carbon::parse($date, AdminPanelProvider::DISPLAY_TIMEZONE)->addDay()->startOfDay()->utc()))),
            ])
            ->recordActions([
                Action::make('output')
                    ->label('輸出')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (AdminOperationLog $record): bool => filled($record->queueJobRun?->output))
                    ->modalHeading(fn (AdminOperationLog $record): string => "執行輸出 #{$record->id}")
                    ->modalContent(fn (AdminOperationLog $record): HtmlString => new HtmlString(
                        '<pre style="white-space: pre-wrap; word-break: break-all; max-height: 60vh; overflow-y: auto;">'
                        . e($record->queueJobRun->output)
                        . '</pre>',
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('關閉'),
            ])
            ->poll('10s')
            ->paginated([10, 25, 50]);
    }
}
