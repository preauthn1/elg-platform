<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Models\AdminOperationLog;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Services\AdminOperationService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/**
 * 把指定代理線下、在某遊戲商內的所有玩家餘額轉回平台錢包（vendor:transfer_money_back 的後台版）。
 *
 * 用於換商戶或遊戲商掛維護前抽分（target/op_tickets#3166）。逐人轉帳走三方 API，
 * 幾萬人一次跑不完 RunAdminOperationJob 的 3600 秒上限，所以：
 * - 每次執行帶時間預算（MAX_SECONDS），指令在預算內自行收尾並印出「續跑起點 beforeId: X」；
 * - 續跑起點從「同一個遊戲商＋代理」最近一筆執行的輸出取回（有收尾標記就取標記；被硬砍沒摘要就取
 *   最後一行進度的帳號 id），填進表單的 resume_before_id 欄位供操作者過目，可手動填別的值覆寫。
 *   超過 RESUME_MAX_AGE_HOURS 的不帶，只在副標題提示。
 *
 * 不同遊戲商可並行（concurrencyScopeKeys 只擋同商），所以續跑起點、頁面顯示的「最近執行」與三方
 * 每秒配額三者都以遊戲商為界：查最近一筆、按中斷、扣配額都不會跨到別的遊戲商身上。
 *
 * 送出當下一律以欄位值為準：填了正整數就用它，否則（空字串、0、空白）重查一次最新的中斷點。
 * 這兩段歷史都指向同一件事——不能用「進頁面那一刻的快照」或「操作者有沒有動過欄位」來決定：
 * 頁面開著看進度的期間上一輪可能才剛收尾（target 2026-09-23 執行 #348），而執行中每 5 秒的輪詢
 * 會把空欄位的 '' 送回後端、被當成操作者改過欄位，於是收尾後按執行整批退回從頭
 * （TPower 2026-10-16 執行 #399，392 已寫下 beforeId 3563012 卻沒被帶上）。
 *
 * 中斷點放在 queue_job_runs.output（MySQL），不放 Redis：cache 庫會被 cache:clear 整庫清掉。
 */
class TransferMoneyBack extends AbstractOperationPage
{
    /** 單次執行的時間預算（秒）：job 硬上限 3600，留 5 分鐘給收尾與最後一位玩家的三方往返 */
    public const int MAX_SECONDS = 3300;

    /** 指令收尾印的續跑標記（見 \App\Console\Commands\Vendor\TransferMoneyBack::RESUME_MARKER，兩邊要同步改） */
    private const string RESUME_MARKER_PATTERN = '/續跑起點 beforeId: (\d+)/u';

    /** 每行進度都帶的帳號 id，被硬砍時的備援中斷點 */
    private const string PROGRESS_ACCOUNT_ID_PATTERN = '/帳號id: (\d+)/u';

    /** 指令正常收尾時的摘要開頭；有它但沒有續跑標記＝全部跑完，不需接續 */
    private const string SUMMARY_PREFIX = '處理完成：';

    /**
     * 中斷點自動帶入的時效（小時）。超過就只提示不帶：隔一個月再抽分是新的一輪，
     * 每個人都可能有新餘額，沿用舊起點會把這段期間新開的帳號整批漏掉
     */
    public const int RESUME_MAX_AGE_HOURS = 24;

    protected static ?int $navigationSort = 5;

    /**
     * 自動帶入表單的續跑起點，供頁面顯示；真正送出用的值由 resolveBeforeId 當下決定。
     * null = 這個範圍目前沒有可接續的起點
     */
    public ?int $autoResumeCursor = null;

    /** 中斷點的來源說明，顯示在頁面副標題 */
    public ?string $resumeHint = null;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::TransferMoneyBack;
    }

    /** 指令每幾秒讀一次中斷旗標（見 \App\Console\Commands\Vendor\TransferMoneyBack::CANCEL_POLL_SECONDS） */
    protected function supportsCancelSignal(): bool
    {
        return true;
    }

    public function getSubheading(): ?string
    {
        $base = sprintf(
            '單次執行上限 %d 秒（約 %d 分鐘），人數多會分多次：到點會自行收尾並記下中斷點；若被超時強制中止而標為失敗，回到本頁也會自動接續，再按「執行」即可。執行中按「中斷」會請它做完手上這筆就停，一樣記下中斷點。換遊戲商或代理即從頭開始。',
            self::MAX_SECONDS,
            intdiv(self::MAX_SECONDS, 60),
        );

        return $this->resumeHint === null ? $base : $base . ' ' . $this->resumeHint;
    }

    /**
     * 送出後把續跑起點欄位交還給自動接續。
     *
     * 手填的值只對這一次有效：留在欄位裡的話，這一輪跑完再按執行會從同一個位置重跑一次，
     * 剛寫下的新中斷點反而被忽略。這裡重算而不是單純清空，順便更新副標題的來源說明。
     */
    public function execute(AdminOperationService $service): void
    {
        parent::execute($service);

        $this->refreshResumeCursor();
    }

    public function mount(): void
    {
        parent::mount();

        // 表單此時已由記憶／網址帶入遊戲商與代理；兩者皆空時退回最近一筆執行的範圍，
        // 讓「跑完一輪、回到頁面、直接按執行」這條單商動線維持原樣
        $this->prefillScopeFromLatestRun();
        $this->refreshResumeCursor();
    }

    /**
     * 表單還沒有遊戲商／代理時，以本操作最近一筆執行的參數帶入。
     *
     * 多商並行時帶回的是「最近動過的那一商」；操作者改選別商，換商事件會重查該商自己的續跑起點。
     */
    private function prefillScopeFromLatestRun(): void
    {
        if (filled($this->data['vendor_id'] ?? null) && filled($this->data['agent_id'] ?? null)) {
            return;
        }

        $params = AdminOperationLog::query()
            ->where('operation', static::operation()->value)
            ->latest('id')
            ->value('params');

        if (!is_array($params) || !isset($params['--vendorId'], $params['--agentId'])) {
            return;
        }

        $this->getSchema('form')?->fill([
            ...($this->data ?? []),
            'vendor_id' => (int) $params['--vendorId'],
            'agent_id' => (int) $params['--agentId'],
            'event_v2' => ($params['--eventV2'] ?? '0') === '1',
        ]);
    }

    /** 只擋同一個遊戲商：不同遊戲商的三方配額互不相干，可以並行 */
    protected function concurrencyScopeKeys(): array
    {
        return ['--vendorId'];
    }

    protected function blockingScopeLabel(array $params): string
    {
        return sprintf('（遊戲商 %s）', $params['--vendorId'] ?? '?');
    }

    /**
     * 頁面顯示與「中斷」的對象限縮在目前選定的遊戲商，否則多商並行時會按到別商那一筆。
     */
    protected function resolveCurrentLogId(): ?int
    {
        $vendorId = $this->data['vendor_id'] ?? null;

        if (blank($vendorId)) {
            return parent::resolveCurrentLogId();
        }

        return self::scopedLogQuery((int) $vendorId)->value('id');
    }

    protected function formComponents(): array
    {
        return [
            Select::make('vendor_id')
                ->label('遊戲商')
                ->helperText('只列轉帳錢包遊戲商：單一錢包的餘額本來就在平台，沒有可轉回的錢')
                ->options(
                    Vendor::query()
                        // 口徑同 FetchBetRecord::isSingleWallet()：vendors.is_single_wallet = 1 為單一錢包
                        ->where('is_single_wallet', 0)
                        ->where('name', '!=', '')
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable()
                // Filament Select 預設 optionsLimit=50，會截斷完整遊戲商清單
                ->optionsLimit(500)
                ->live()
                // 換商後代理若仍開通就留著（同一條代理線常要在多個遊戲商各抽一次），沒開通才歸零
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                    $agentId = $get('agent_id');

                    if (filled($agentId) && !self::vendorHasAgent((int) $state, (int) $agentId)) {
                        $set('agent_id', null);
                    }

                    $this->currentLogId = $this->resolveCurrentLogId();
                    $this->refreshResumeCursor((int) $state, $get('agent_id'));
                })
                ->required(),
            Select::make('agent_id')
                ->label('代理')
                ->helperText('只列有開通該遊戲商（agent_vendors）的代理；指令只撈並處理該代理整條下線的玩家')
                ->options(fn (Get $get): array => self::agentOptions($get('vendor_id')))
                ->searchable()
                ->optionsLimit(500)
                ->live()
                ->afterStateUpdated(fn (Get $get, mixed $state) => $this->refreshResumeCursor($get('vendor_id'), $state))
                ->required(),
            TextInput::make('member_username')
                ->label('會員帳號')
                ->helperText('只抽這一位（members.username，仍須在所選代理下線）；留空 = 該代理全部下線。指定單一會員時不接續中斷點')
                ->maxLength(32),
            Toggle::make('event_v2')
                ->label('活動 V2 商戶（vendor_infos.type = 2）')
                ->live()
                ->afterStateUpdated(fn (Get $get) => $this->refreshResumeCursor($get('vendor_id'), $get('agent_id'))),
            TextInput::make('resume_before_id')
                ->label('續跑起點（帳號 id）')
                ->helperText('自動帶入這個遊戲商＋代理上次停下的位置，只處理 id 小於它的帳號；留空或填 0 = 送出當下再查一次最新的中斷點。要從最新帳號重頭跑就填一個大於最大帳號 id 的值。')
                ->numeric()
                ->minValue(0),
        ];
    }

    /**
     * 重算續跑起點並填進表單欄位；遊戲商或代理沒選齊就清空（不同範圍的起點不可互用）。
     *
     * 參數省略時取表單現值；由 afterStateUpdated 呼叫時要明確傳入，該時點 $this->data 不一定已同步。
     */
    private function refreshResumeCursor(mixed $vendorId = null, mixed $agentId = null): void
    {
        $vendorId = $vendorId ?? ($this->data['vendor_id'] ?? null);
        $agentId = $agentId ?? ($this->data['agent_id'] ?? null);
        $eventV2 = (bool) ($this->data['event_v2'] ?? false);

        $resolved = blank($vendorId) || blank($agentId)
            ? ['cursor' => null, 'hint' => null]
            : self::resolveResumeCursor((int) $vendorId, (int) $agentId, $eventV2);

        $this->autoResumeCursor = $resolved['cursor'];
        $this->resumeHint = $resolved['hint'];

        $this->getSchema('form')?->fill([
            ...($this->data ?? []),
            'resume_before_id' => $resolved['cursor'],
        ]);
    }

    /**
     * 取「這個遊戲商＋代理＋活動 V2」最近一筆執行的續跑起點。
     *
     * 全部跑完的執行（有摘要、無續跑標記）不帶；超過 RESUME_MAX_AGE_HOURS 的只回提示、不帶：
     * 隔一個月再抽分是新的一輪，每個人都可能有新餘額，沿用舊起點會把這段期間新開的帳號整批漏掉。
     *
     * @return array{cursor: ?int, hint: ?string}
     */
    private static function resolveResumeCursor(int $vendorId, int $agentId, bool $eventV2): array
    {
        $log = self::scopedLogQuery($vendorId, $agentId, $eventV2)
            ->with('queueJobRun')
            ->first();

        $output = $log?->queueJobRun?->output;
        if ($log === null || blank($output)) {
            return ['cursor' => null, 'hint' => null];
        }

        $cursor = self::resumeCursorFromOutput($output);
        if ($cursor === null) {
            return ['cursor' => null, 'hint' => null];
        }

        $endedAt = $log->finished_at ?? $log->created_at;
        if ($endedAt->lt(now()->subHours(self::RESUME_MAX_AGE_HOURS))) {
            return [
                'cursor' => null,
                'hint' => sprintf(
                    '上次執行 #%d（%s）停在帳號 id %d，已超過 %d 小時視為新的一輪，本次從頭開始。',
                    $log->id,
                    $endedAt->diffForHumans(),
                    $cursor,
                    self::RESUME_MAX_AGE_HOURS,
                ),
            ];
        }

        return [
            'cursor' => $cursor,
            'hint' => sprintf('本次將接續執行 #%d，從帳號 id %d 之後繼續。', $log->id, $cursor),
        ];
    }

    /**
     * 限縮在同一個範圍的執行紀錄查詢（最新的排前面）。
     *
     * 代理與活動 V2 省略時只以遊戲商為界——頁面顯示與「中斷」看的是整個遊戲商，
     * 因為同一個遊戲商同時只會有一筆在跑（見 concurrencyScopeKeys）。
     *
     * @return Builder<AdminOperationLog>
     */
    private static function scopedLogQuery(int $vendorId, ?int $agentId = null, ?bool $eventV2 = null): Builder
    {
        return AdminOperationLog::query()
            ->where('operation', static::operation()->value)
            ->where('params->--vendorId', (string) $vendorId)
            ->when($agentId !== null, fn (Builder $query) => $query->where('params->--agentId', (string) $agentId))
            ->when($eventV2 !== null, fn (Builder $query) => $query->where('params->--eventV2', $eventV2 ? '1' : '0'))
            ->latest('id');
    }

    /**
     * 這個遊戲商有沒有開通這個代理（換商時用來決定代理要不要保留）
     */
    private static function vendorHasAgent(int $vendorId, int $agentId): bool
    {
        return AgentVendor::query()
            ->where('vendor_id', $vendorId)
            ->where('agent_id', $agentId)
            ->exists();
    }

    /**
     * 由執行輸出判斷續跑起點：
     * - 有「續跑起點 beforeId: X」標記 → X（預算用盡或 --limit 截滿的正常收尾）
     * - 有收尾摘要但無標記 → 全部跑完，回 null
     * - 沒有收尾摘要（超時被硬砍、例外中止）→ 最後一行進度的帳號 id
     */
    public static function resumeCursorFromOutput(string $output): ?int
    {
        if (preg_match(self::RESUME_MARKER_PATTERN, $output, $matches) === 1) {
            return (int) $matches[1];
        }

        if (str_contains($output, self::SUMMARY_PREFIX)) {
            return null;
        }

        if (preg_match_all(self::PROGRESS_ACCOUNT_ID_PATTERN, $output, $matches) > 0) {
            // 最後一行進度的那位可能只做了一半就被砍；把它含進下次範圍（<= 語意）——
            // 命令是 id < beforeId，所以 +1
            return (int) end($matches[1]) + 1;
        }

        return null;
    }

    /**
     * 有開通指定遊戲商的代理清單：(id) username
     *
     * @return array<int, string>
     */
    private static function agentOptions(mixed $vendorId): array
    {
        if (blank($vendorId)) {
            return [];
        }

        $agentIds = AgentVendor::query()
            ->where('vendor_id', (int) $vendorId)
            ->distinct()
            ->pluck('agent_id');

        return Agent::query()
            ->whereIn('id', $agentIds)
            ->orderBy('id')
            ->pluck('username', 'id')
            ->map(fn (string $username, int $id): string => sprintf('(%d) %s', $id, $username))
            ->all();
    }

    protected function buildParams(array $data): array
    {
        $vendorId = (int) $data['vendor_id'];
        $agentId = (int) $data['agent_id'];
        $eventV2 = (bool) ($data['event_v2'] ?? false);

        $params = [
            '--vendorId' => (string) $vendorId,
            '--agentId' => (string) $agentId,
            '--eventV2' => $eventV2 ? '1' : '0',
            '--maxSeconds' => (string) self::MAX_SECONDS,
        ];

        $memberUsername = trim((string) ($data['member_username'] ?? ''));
        if ($memberUsername !== '') {
            $params['--memberUsername'] = $memberUsername;

            // 指定單一會員是獨立的小動作，不接續整批抽分的中斷點
            return $params;
        }

        $beforeId = $this->resolveBeforeId($data, $vendorId, $agentId, $eventV2);
        if ($beforeId !== null) {
            $params['--beforeId'] = (string) $beforeId;
        }

        return $params;
    }

    /**
     * 送出當下決定要帶的續跑起點：欄位填了正整數就照填的走，否則重查一次最新的中斷點。
     *
     * 空字串、0、空白都算「沒指定」而不是「要從頭跑」——沒指定時回到自動接續是安全的那一邊：
     * 漏帶起點會把上一輪抽過的幾千人再抽一次、真正沒抽到的那段又輪不到，而多帶一次起點只是少跑
     * 已經抽完的人。要從最新帳號重頭跑就填一個大於最大帳號 id 的值。
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveBeforeId(array $data, int $vendorId, int $agentId, bool $eventV2): ?int
    {
        $field = (int) trim((string) ($data['resume_before_id'] ?? ''));
        if ($field > 0) {
            return $field;
        }

        return self::resolveResumeCursor($vendorId, $agentId, $eventV2)['cursor'];
    }
}
