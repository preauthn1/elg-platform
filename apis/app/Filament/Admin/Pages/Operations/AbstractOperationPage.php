<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner;
use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Filament\Admin\Pages\Concerns\AuthorizesByUserRole;
use App\Filament\Admin\Pages\Concerns\RemembersFormState;
use App\Models\AdminOperationLog;
use App\Models\User;
use App\Models\Vendor;
use App\Providers\Filament\AdminPanelProvider;
use App\Services\AdminOperationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 單一系統操作頁的共用基底：參數表單 + 執行按鈕 + 最近執行結果輪詢顯示。
 *
 * 子類別只需定義 operation()、formComponents()、buildParams()；
 * 派發（含防重複）走 AdminOperationService，執行本體見 RunAdminOperationJob。
 *
 * 表單選項在送出後記進 session，回到頁面時沿用；接續按鈕帶來的網址參數優先於記憶。
 */
abstract class AbstractOperationPage extends Page
{
    use AuthorizesByUserRole;
    use RemembersFormState;

    /** 時間類參數可選的輸入時區（與補帳查詢頁的時差選項對齊） */
    public const array TIMEZONES = [
        '-04:00' => 'UTC-4',
        'UTC' => 'UTC',
        '+07:00' => 'UTC+7',
        '+08:00' => 'UTC+8（台北）',
    ];

    public const string DEFAULT_TIMEZONE = AdminPanelProvider::DISPLAY_TIMEZONE;

    /**
     * 心跳容忍秒數：執行列 updated_at 在這個秒數內動過就當作「可能還活著」，取消前多問一次。
     *
     * 心跳來源是 {@see \App\Console\Output\QueueJobRunStreamOutput} 每 5 秒把輸出寫回
     * queue_job_runs（該表 updated_at 為 ON UPDATE CURRENT_TIMESTAMP），但那個 flush 只在
     * 指令有輸出時才發生——指令卡在三方請求（HTTP timeout 最長 30 秒、逐筆可能連打三次）
     * 或 DB 鎖等待時會合法地安靜好一陣子，所以門檻取遠大於 5 秒的 120 秒，
     * 避免把還活著的執行判成已死。
     */
    private const int HEARTBEAT_ALIVE_SECONDS = 120;

    /**
     * 運維的日期欄位可回溯的最大天數：更舊的日期在選擇器上就選不到，送出也會被表單驗證擋下。
     *
     * 管理員改用 ADMIN_MAX_DAYS_AGO（見 maxDaysAgo()）。
     */
    protected const int MAX_DAYS_AGO = 30;

    /**
     * 管理員的日期欄位可回溯的最大天數：與三方注單保留期（約 60 天）一致，
     * 更早的資料三方已不回、重跑成本也高。
     */
    protected const int ADMIN_MAX_DAYS_AGO = 60;

    /**
     * 重跑舊日期要先確認的門檻：表單日期在幾天前（含）以上，執行前先跳確認視窗。
     *
     * 只比日期天數、不看時分秒；本頁沒有 date 欄位時不適用（直接執行）。
     */
    private const int CONFIRM_DAYS_AGO = 4;

    protected static string|\UnitEnum|null $navigationGroup = '系統操作';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play';

    protected string $view = 'filament.admin.pages.operations.operation-page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $currentLogId = null;

    abstract public static function operation(): AdminOperationEnum;

    /**
     * @return array<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> 操作參數表單元件
     */
    abstract protected function formComponents(): array;

    /**
     * @param  array<string, mixed>  $data  表單狀態
     * @return array<string, mixed> artisan 指令參數
     */
    abstract protected function buildParams(array $data): array;

    /**
     * 允許由網址 query string 帶入表單的欄位（供其他頁「接續」按鈕預填）；預設不接受。
     *
     * @return list<string>
     */
    protected function prefillableKeys(): array
    {
        return [];
    }

    /**
     * 遊戲商時區（時差小時數）對應的 TIMEZONES 選項鍵；
     * 查無遊戲商或時差不在選項內時回 null（時區欄位清空、由操作者自選）。
     *
     * 列在 config/summary_bet_record.php 的遊戲商以對帳時區 `reconcile_timezone`（未設 +8）為準
     * （target/tickets#4357，與每日對帳 SQL 口徑一致），其餘沿用 vendors.calc_time_zone。
     */
    protected static function vendorTimezoneOption(mixed $vendorId): ?string
    {
        $config = config('summary_bet_record.' . (int) $vendorId);

        $offset = is_array($config)
            ? SummaryBetRecordRunner::reconcileTimezone($config)
            : Vendor::query()->find($vendorId)?->calc_time_zone;

        if ($offset === null) {
            return null;
        }

        $key = SummaryBetRecordRunner::timezoneOption((int) $offset);

        return array_key_exists($key, static::TIMEZONES) ? $key : null;
    }

    /** 登入者的日期欄位是否要套 MAX_DAYS_AGO 下限；未登入或非 User 一律視為受限（fail-closed） */
    protected static function isDateRangeLimited(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user->isLimitedToRecentOperationDates() : true;
    }

    /** 登入者的日期欄位可回溯天數：運維 MAX_DAYS_AGO、管理員 ADMIN_MAX_DAYS_AGO；未登入一律套較嚴的 MAX_DAYS_AGO */
    protected static function maxDaysAgo(): int
    {
        return static::isDateRangeLimited() ? static::MAX_DAYS_AGO : static::ADMIN_MAX_DAYS_AGO;
    }

    public static function getNavigationLabel(): string
    {
        return static::operation()->label();
    }

    public function getTitle(): string
    {
        return static::operation()->label();
    }

    public function mount(): void
    {
        $this->fillFormWithMemory($this->requestPrefill($this->prefillableKeys()));

        // 回到頁面時接續顯示此操作最近一筆執行（含進行中的）
        $this->currentLogId = $this->resolveCurrentLogId();
    }

    /**
     * 頁面上方要顯示（並可中斷）的是哪一筆執行。
     *
     * 預設本操作最近一筆。可並行的操作要覆寫成只看自己那個範圍的最近一筆——
     * 否則多個範圍同時在跑時，「中斷」會按到別人那一筆。
     */
    protected function resolveCurrentLogId(): ?int
    {
        return AdminOperationLog::where('operation', static::operation()->value)
            ->latest('id')
            ->value('id');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->statePath('data');
    }

    public function execute(AdminOperationService $service): void
    {
        $data = $this->getSchema('form')?->getState() ?? [];
        $this->rememberFormState($data);

        $params = $this->buildParams($data);

        $result = $service->dispatch(
            operation: static::operation(),
            params: $params,
            userId: (int) Filament::auth()->id(),
            scopeParams: Arr::only($params, $this->concurrencyScopeKeys()),
        );

        if ($result['blocking'] !== null) {
            $this->currentLogId = $result['blocking']->id;

            Notification::make()
                ->warning()
                ->title('相同作業執行中')
                ->body('「' . static::operation()->label() . '」' . $this->blockingScopeLabel($params)
                    . "已有未完成的執行（#{$result['blocking']->id}），請等待其結束後再試。")
                ->send();

            return;
        }

        $this->currentLogId = $result['log']->id;

        Notification::make()
            ->success()
            ->title('已排入佇列')
            ->body('「' . static::operation()->label() . "」已建立執行紀錄 #{$result['log']->id}。")
            ->send();
    }

    /**
     * 「執行」按鈕：日期離今天夠遠時先跳確認視窗，避免手滑重跑很舊的日期。
     *
     * 表單沒有 date 欄位、或日期未達 CONFIRM_DAYS_AGO 門檻時不出視窗，按下去直接執行。
     */
    public function executeAction(): Action
    {
        return Action::make('execute')
            ->label('執行')
            ->icon('heroicon-o-play')
            ->requiresConfirmation()
            // 開不開視窗的唯一開關：modal() 回 false 時 requiresConfirmation 與下面的文案都不生效，
            // 按下去直接執行（不可改用 requiresConfirmation(closure)——設了 modalHeading 就一律開視窗）
            ->modal(fn (): bool => $this->backfillDaysAgo() !== null)
            ->modalHeading('確認重跑舊日期')
            ->modalDescription(fn (): string => sprintf(
                '選定日期 %s 是 %d 天前，重跑會覆蓋該日既有資料。確定要執行嗎？',
                Carbon::parse((string) ($this->data['date'] ?? ''))->format('Y-m-d'),
                $this->backfillDaysAgo() ?? 0,
            ))
            ->modalSubmitActionLabel('確定執行')
            ->action(fn (AdminOperationService $service) => $this->execute($service));
    }

    /**
     * 表單選的日期是幾天前；未達確認門檻、或本頁沒有日期欄位則回 null。
     *
     * 兩邊都取當日 00:00 相減，比的是日曆天數，與填表當下的時分秒無關。
     */
    private function backfillDaysAgo(): ?int
    {
        $date = $this->data['date'] ?? null;

        if (blank($date)) {
            return null;
        }

        $daysAgo = (int) Carbon::parse($date)->startOfDay()->diffInDays(Carbon::now()->startOfDay());

        return $daysAgo >= self::CONFIRM_DAYS_AGO ? $daysAgo : null;
    }

    /**
     * 防重複的範圍鍵：列出哪些 artisan 參數要納入「同一件事」的判定。
     *
     * 預設空陣列＝同一個操作同時只能有一筆未完成（舊行為）。轉回遊戲商餘額回 ['--vendorId']，
     * 於是不同遊戲商可以並行——三方的每秒配額本來就是各商獨立的（RateLimiter key 帶 vendor id）。
     *
     * @return list<string>
     */
    protected function concurrencyScopeKeys(): array
    {
        return [];
    }

    /**
     * 擋下時要在通知裡補的範圍描述，例如「（遊戲商 3）」；無範圍鍵時回空字串。
     *
     * @param  array<string, mixed>  $params
     */
    protected function blockingScopeLabel(array $params): string
    {
        return '';
    }

    /**
     * 本頁的指令是否會輪詢中斷旗標（admin_operation_logs.cancel_requested_at）自行收尾。
     *
     * 回 true 的頁面，「中斷」對執行中的進程才真的停得下來，按鈕因此不必等心跳斷掉才給按。
     * 預設 false：多數指令收不到訊號，對活著的進程按下去只會標紅紀錄、進程照跑。
     */
    protected function supportsCancelSignal(): bool
    {
        return false;
    }

    /**
     * 「中斷」：請執行中的指令停下，或把卡住的執行收尾為失敗。
     *
     * 指令支援中斷旗標且心跳還在 → 只寫旗標，指令跑完當下那筆自行收尾並記下續跑起點。
     * 否則（不支援、或心跳已斷）→ 沿用舊行為直接標為失敗：worker 被硬砍（k8s rollout、OOM 等）
     * 時紀錄會停在執行中，要等 queue 的 retry_after 到期才會被判失敗，期間新的執行一直被防重複擋住。
     */
    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('中斷')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->size('sm')
            ->visible(fn (): bool => $this->canInterrupt())
            ->requiresConfirmation()
            ->modalHeading(fn (): string => '中斷執行 #' . $this->currentLogId . '？')
            ->modalDescription(fn (): string => $this->cancelWarning())
            ->modalSubmitActionLabel('確定中斷')
            ->action(function (AdminOperationService $service): void {
                $log = $this->getCurrentLog();
                $graceful = $this->shouldRequestGracefulStop();

                if ($log === null || !$service->cancel($log, (int) Filament::auth()->id(), graceful: $graceful)) {
                    Notification::make()
                        ->warning()
                        ->title('沒有可中斷的執行')
                        ->body('該筆執行已經結束，狀態未變更。')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($graceful ? '已送出中斷要求' : '已中斷')
                    ->body($graceful
                        ? "執行 #{$log->id} 會在當前這筆處理完後停下（最多數秒），停下時會記下續跑起點。"
                        : "執行 #{$log->id} 已中斷（標記為失敗），現在可以重新執行。")
                    ->send();
            });
    }

    /**
     * 「中斷」按鈕的顯示條件：紀錄未收尾。指令收得到中斷旗標時就一直給按（停得下來）；
     * 收不到的話沿用舊條件——執行列超過 HEARTBEAT_ALIVE_SECONDS 沒有寫入（或根本沒有執行列可看心跳）
     * 才給按，心跳還在跳代表進程可能活著，按了只會標紅紀錄、進程照跑。
     */
    private function canInterrupt(): bool
    {
        $unfinished = in_array(
            $this->getCurrentLog()?->status,
            [AdminOperationStatusEnum::PENDING, AdminOperationStatusEnum::RUNNING],
            true,
        );

        if (!$unfinished) {
            return false;
        }

        if ($this->supportsCancelSignal()) {
            return true;
        }

        $seconds = $this->heartbeatSecondsAgo();

        return $seconds === null || $seconds > self::HEARTBEAT_ALIVE_SECONDS;
    }

    /**
     * 這次按下中斷要走「請指令自己停」還是「直接標失敗」。
     *
     * 指令收得到訊號、而且心跳顯示它還活著才走前者；心跳已斷代表沒人會來讀旗標，
     * 寫了只會讓紀錄一直卡在執行中，那正是這顆按鈕原本要解的問題。
     */
    private function shouldRequestGracefulStop(): bool
    {
        if (!$this->supportsCancelSignal()) {
            return false;
        }

        $seconds = $this->heartbeatSecondsAgo();

        return $seconds !== null && $seconds <= self::HEARTBEAT_ALIVE_SECONDS;
    }

    /**
     * 中斷確認視窗的說明：依心跳判斷這筆執行看起來是死是活，把判斷依據攤給操作者看。
     *
     * 心跳＝執行列（queue_job_runs）最後一次被寫入的時間，見 HEARTBEAT_ALIVE_SECONDS 註解。
     * 「還活著」那段照理按不到（canInterrupt 已隱藏按鈕），留著擋「按鈕渲染後心跳才恢復」的時間差。
     */
    private function cancelWarning(): string
    {
        $seconds = $this->heartbeatSecondsAgo();

        if ($this->shouldRequestGracefulStop()) {
            return sprintf(
                '這筆執行在 %d 秒前還有輸出，仍在執行中。中斷會請它把手上這筆做完後停下（最多數秒），'
                . '已處理的進度保留，停下時會記下續跑起點，回到本頁再按「執行」即可接續。',
                $seconds ?? 0,
            );
        }

        if ($seconds === null) {
            return '這筆執行沒有執行列可看心跳，可能尚未被 worker 取走。中斷後即可重新執行。';
        }

        if ($seconds <= self::HEARTBEAT_ALIVE_SECONDS) {
            return sprintf(
                '⚠️ 這筆執行在 %d 秒前還有輸出，很可能仍在執行中。'
                . '中斷只會把紀錄標為失敗、不會停掉正在跑的進程——若它還活著，重新執行會有兩個進程同時作業。'
                . '請先確認它確實已經停了（例如 pod 被重啟）再繼續。',
                $seconds,
            );
        }

        return sprintf(
            '這筆執行已經 %d 分鐘沒有任何輸出（超過 %d 秒心跳門檻），研判進程已經停了。'
            . '中斷會把紀錄標為失敗，之後即可重新執行，已回寫的進度會保留供接續。',
            intdiv($seconds, 60),
            self::HEARTBEAT_ALIVE_SECONDS,
        );
    }

    /** 執行列最後一次被寫入距今幾秒；沒有執行列或從未寫入則回 null */
    private function heartbeatSecondsAgo(): ?int
    {
        $updatedAt = $this->getCurrentLog()?->queueJobRun?->updated_at;

        return $updatedAt === null ? null : (int) $updatedAt->diffInSeconds(now(), absolute: true);
    }

    /**
     * Blade 直印用：DB 的 UTC 時間換成後台顯示時區。{{ $log->created_at }} 直接印 Carbon 會是 UTC，
     * 表格欄位有 FilamentTimezone 統一換算、Blade 沒有，所以要經這裡。
     */
    public function displayTime(?Carbon $time): string
    {
        return $time?->copy()->setTimezone(AdminPanelProvider::DISPLAY_TIMEZONE)->format('Y-m-d H:i:s') ?? '-';
    }

    public function getCurrentLog(): ?AdminOperationLog
    {
        return $this->currentLogId !== null
            ? AdminOperationLog::with(['user', 'queueJobRun'])->find($this->currentLogId)
            : null;
    }

    /** 尚未跑完時頁面持續輪詢刷新（操作紀錄或其執行列任一未定案都要繼續刷） */
    public function isPolling(): bool
    {
        $log = $this->getCurrentLog();

        if ($log === null) {
            return false;
        }

        if (in_array($log->status, [AdminOperationStatusEnum::PENDING, AdminOperationStatusEnum::RUNNING], true)) {
            return true;
        }

        return $log->queueJobRun?->status === QueueJobRunStatusEnum::PENDING;
    }
}
