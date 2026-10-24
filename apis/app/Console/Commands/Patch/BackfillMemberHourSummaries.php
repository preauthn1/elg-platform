<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Services\MemberHourlyStatisticsRecomputeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 回填 member_hourly_statistics：歷史 orders(成功) / withdrawal_orders(成功) 與手動開分／洗分依整點分桶聚合。
 *
 * 來源（2026-09-11 擴充，target/tickets#4333）：除金流訂單外，加入 transaction_logs 中沒有訂單的開分(4)／洗分(5)
 * （order_id = 0）——客服後台與代理後台的人工上下分不建訂單，過去完全進不了這張表，儀表板與數據分析因此對不上。
 * 分桶與加總邏輯集中在 MemberHourlyStatisticsRecomputeService，與每小時校正排程共用同一份。
 * `--purge-agent-rows` 另可先清掉被灌入的 member_id = 0 列（代理自助洗分／上分，不屬會員統計）。
 *
 * 金額口徑（2026-09-07 修正，target/tickets#4299）：`IF(point > 0, point, amount * rate)`。
 * 代付建單流程原先未寫入 point（2026-07-20 修正上線前的歷史單 point 結構性為 0，
 * 金額只在 amount；TPower 實測 2024-01 該月 36,151 筆成功提款全數 point=0），
 * 直接 SUM(point) 會讓歷史提款金額整段記成 0。換算公式與 patch:backfill-withdrawal-point
 * 相同（amount × rate），差別在**本補丁不改動 orders / withdrawal_orders 任何欄位**，
 * 只寫匯總表——來源表維持原樣，避免動到已對帳的金流資料。
 * 代收側 point 全期有值，同樣套用只是保險（IF 條件不會成立）。
 *
 * ⚠️ 每小時校正排程 `member:correct-hourly-statistics` 刻意維持 SUM(point)：
 * 它只回溯近 2 小時，該區間 point 皆為正常值，不會回頭覆蓋本補丁修好的歷史。
 *
 * 本補丁不再寫 member_statistics（2026-09-07 移除）：六站的 last_deposit_at /
 * last_withdraw_at / last_activity_at 已由即時管道累積兩個月，不需要也不應由歷史回填介入。
 * 未來若有新站需要補這些欄位，另行處理。
 *
 * 分桶時間欄位（須與即時管道 Observer / 校正命令同基準）：
 *  - 代收：orders.pay_time（成功當下寫入、之後不變；吃現成 idx_orders_pay_time，避免每 chunk 掃全部 status=成功）。
 *  - 代付：withdrawal_orders.payment_time（實際打款時間，成功當下寫入、之後不變；吃 idx_withdrawal_orders_status_payment_time）。
 *    ⚠️ 不可用 updated_at——該欄位為 `ON UPDATE CURRENT_TIMESTAMP`，任何後續 UPDATE（含批次回填本身）都會
 *    使其失真、不具備穩定分桶意義。
 *
 * 資料來源：掃 orders / withdrawal_orders（分區塊，本來就要掃來建匯總）。
 *
 * 冪等性：每個 chunk 先把窗口內的桶歸零、再以「全量重算覆蓋」語意重算（ON DUPLICATE KEY UPDATE = VALUES(...)），
 * 兩步同一個 transaction；金額走 DB 端 decimal 運算（精確）。可重跑，未被 chunk 掃到的桶不受影響——
 * 因此以 --start/--end 精準框住待補區間即可。窗口內沒有來源的桶會被歸零（那正是要清的殘留值）。
 *
 * 升級用法：
 *   php artisan patch:backfill-member-hour-summaries --dry-run
 *   php artisan patch:backfill-member-hour-summaries --start="2022-11-01 00:00:00" --end="2026-07-20 00:00:00"
 *   # 只修代付（正式站代收筆數遠多於代付，只有代付需修時可省大量掃描）：
 *   php artisan patch:backfill-member-hour-summaries --only=withdrawal --start="..." --end="..."
 *
 * target/tickets#3967、#4299（金額口徑修正與 member_statistics 段落移除）
 */
class BackfillMemberHourSummaries extends Command
{
    protected $signature = 'patch:backfill-member-hour-summaries
        {--start= : 起始時間（預設取最早成功訂單時間）}
        {--end= : 結束時間（預設 now）}
        {--chunk-days=30 : 每批處理天數}
        {--only= : 只重算單側：deposit（代收）或 withdrawal（代付）；預設兩側都跑}
        {--purge-agent-rows : 先清掉被灌入的 member_id=0 列（代理自助洗分／上分，不屬會員統計）}
        {--dry-run : 只預覽來源筆數與範圍，不寫入}';

    protected $description = '回填會員每小時存提匯總 member_hourly_statistics（訂單＋手動開分洗分；歷史零值單以 amount × rate 換算）';

    public function __construct(private readonly MemberHourlyStatisticsRecomputeService $recompute)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ((bool) $this->option('purge-agent-rows')) {
            $this->purgeAgentRows($dryRun);
        }

        // 單側重算：代收與代付各自只寫自己的欄位（deposit_* / withdrawal_*），互不干擾，
        // 故只有一側需要修時可跳過另一側——正式站代收筆數遠多於代付，跳過可省大量掃描。
        $only = (string) $this->option('only');
        if ($only !== '' && !in_array($only, ['deposit', 'withdrawal'], true)) {
            $this->error('--only 只接受 deposit 或 withdrawal');

            return self::FAILURE;
        }
        $runDeposit = $only !== 'withdrawal';
        $runWithdrawal = $only !== 'deposit';

        $depositStatus = OrderStatusEnum::DEPOSIT_FINISHED->value;
        // 出款成功＝三方代付 或 自助洗分，兩者都要計入
        $withdrawalStatuses = [
            WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
            WithdrawalOrderStatusEnum::TRANSFERRED->value,
        ];

        $start = (string) ($this->option('start') ?: $this->earliestSuccessTime($depositStatus, $withdrawalStatuses));
        $end = (string) ($this->option('end') ?: now()->toDateTimeString());

        if ($start === '') {
            $this->info('沒有成功訂單可回填，略過。');

            return self::SUCCESS;
        }

        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $cursor = Carbon::parse($start)->startOfDay();
        $endAt = Carbon::parse($end);

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN：不寫入資料庫');
        }
        $this->info(sprintf(
            '回填範圍：%s ~ %s（每批 %d 天；重算 %s）',
            $cursor->toDateTimeString(),
            $endAt->toDateTimeString(),
            $chunkDays,
            $only === '' ? '代收＋代付' : ($only === 'deposit' ? '僅代收' : '僅代付'),
        ));

        $totalDeposit = 0;
        $totalWithdrawal = 0;

        while ($cursor->lt($endAt)) {
            $chunkStart = $cursor->toDateTimeString();
            $chunkEndAt = $cursor->copy()->addDays($chunkDays);
            if ($chunkEndAt->gt($endAt)) {
                $chunkEndAt = $endAt->copy();
            }
            $chunkEnd = $chunkEndAt->toDateTimeString();

            // 來源筆數：金流訂單 ＋ 手動開分/洗分（transaction_logs，order_id=0），與校正排程同一套 Service
            $deposit = $runDeposit ? $this->recompute->countDepositSources($chunkStart, $chunkEnd) : ['orders' => 0, 'manual' => 0];
            $withdrawal = $runWithdrawal ? $this->recompute->countWithdrawalSources($chunkStart, $chunkEnd) : ['orders' => 0, 'manual' => 0];

            $depositRows = $deposit['orders'] + $deposit['manual'];
            $withdrawalRows = $withdrawal['orders'] + $withdrawal['manual'];
            $totalDeposit += $depositRows;
            $totalWithdrawal += $withdrawalRows;

            if (!$dryRun) {
                // 先把窗口內的桶歸零、再整桶重算，兩步同一個 transaction：來源消失或分桶基準改變後殘留的舊值
                // 才會被清掉（整桶覆蓋只寫「現在有來源」的桶）。來源為 0 也要跑，因為要清的正是沒來源的桶。
                // 歷史單 point 結構性為 0（2026-07-20 前代付未補寫 point），回填改以 amount × rate 換算
                if ($runDeposit) {
                    DB::transaction(function () use ($chunkStart, $chunkEnd): void {
                        $this->recompute->resetDepositWindow($chunkStart, $chunkEnd);
                        $this->recompute->recomputeDeposit($chunkStart, $chunkEnd, historicalAmountFallback: true);
                    });
                }

                if ($runWithdrawal) {
                    DB::transaction(function () use ($chunkStart, $chunkEnd): void {
                        $this->recompute->resetWithdrawalWindow($chunkStart, $chunkEnd);
                        $this->recompute->recomputeWithdrawal($chunkStart, $chunkEnd, historicalAmountFallback: true);
                    });
                }
            }

            $this->line(sprintf(
                '  %s ~ %s：代收 %d（手動開分 %d）、代付 %d（手動洗分 %d）',
                $chunkStart,
                $chunkEnd,
                $depositRows,
                $deposit['manual'],
                $withdrawalRows,
                $withdrawal['manual'],
            ));
            $cursor->addDays($chunkDays);
        }

        $this->info(sprintf(
            '%s：代收來源 %d 筆、代付來源 %d 筆。',
            $dryRun ? 'DRY RUN 完成' : '完成',
            $totalDeposit,
            $totalWithdrawal,
        ));

        return self::SUCCESS;
    }

    /**
     * 清掉被灌入的 member_id = 0 列：代理自助洗分／上分的訂單 member_id 寫 0、agent_id 帶代理，
     * 過去四條寫入路徑都沒排除，六站皆有（TPower／yes2win 各約 8 千列）。只動 member_id = 0，不碰真實會員。
     */
    private function purgeAgentRows(bool $dryRun): void
    {
        $counts = $this->recompute->countAgentRows();
        $this->info(sprintf(
            'member_id=0 待清理：member_hourly_statistics %d 列、member_statistics %d 列',
            $counts['hourly'],
            $counts['statistics'],
        ));

        if ($dryRun) {
            $this->comment('[dry-run] 不刪除');

            return;
        }

        $deleted = $this->recompute->purgeAgentRows();
        $this->info(sprintf('已刪除：member_hourly_statistics %d 列、member_statistics %d 列', $deleted['hourly'], $deleted['statistics']));
    }

    /**
     * 最早的成功來源時間：金流訂單與手動帳變（transaction_logs type 4/5、order_id=0）取最小者。
     *
     * @param  array<int, int>  $withdrawalStatuses
     */
    private function earliestSuccessTime(int $depositStatus, array $withdrawalStatuses): ?string
    {
        $deposit = DB::table('orders')->where('status', $depositStatus)->where('member_id', '>', 0)->min('pay_time');
        $withdrawal = DB::table('withdrawal_orders')->whereIn('status', $withdrawalStatuses)->where('member_id', '>', 0)->min('payment_time');
        $manual = DB::table('transaction_logs')
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereIn('type', [TransactionEnum::DEPOSIT->value, TransactionEnum::WITHDRAWAL->value])
            ->where(fn ($q) => $q->where('order_id', 0)->orWhereNull('order_id'))
            ->where('member_id', '>', 0)
            ->min('created_at');

        $times = array_filter([$deposit, $withdrawal, $manual]);

        return $times === [] ? null : (string) min($times);
    }
}
