<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Facades\BotNotify;
use App\Models\WithdrawalOrder;
use App\Services\WithdrawalPayoutQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 排程：掃描停留於代付請求中(21)超過門檻的訂單並告警（target/tickets#4137 E 項）。
 *
 * 事務拆分後 21 是「已提交、結果未明」的常駐停留態：結果未明當下程式會發即時
 * 告警，但 pod 被終止時程式已消失、什麼都發不出來——本命令補上「怎麼發現」。
 *
 * 告警前先走三方查單自動查證（擴充點，見 SupportsPayoutQuery）：provider 有查單
 * API 且查到有單者自動補單號轉 22、不進告警清單；查無／未知／不支援者照舊告警。
 * 本命令自身只告警不改單：定案走查證服務、回調、後台按鈕或 withdrawal:resolve-payout。
 *
 * 去重＝Cache::add 原子預占＋失敗歸還：預占成功才納入本批（防重疊執行重複發送），
 * BotNotify 拋例外時 forget 歸還本批 key、命令回 FAILURE，下輪排程自動重試；
 * 訂單以 chunkById 分批自 DB 載入（大量卡單不撐爆記憶體），每批即一則通知；
 * 排程執行紀錄另有 command_logs 稽核。
 *
 * @knowledge rules/provider-a3pay
 */
class AlertStuckSubmittedWithdrawal extends Command
{
    /**
     * 預占 TTL（秒）＝15 分鐘：Cache::add 預占後、通知送出前若進程遭硬中斷
     * （kill／OOM，catch 的 forget 來不及執行），預占最多 15 分鐘自動失效、
     * 下輪重試，不會靜默整個去重時窗
     */
    private const RESERVE_TTL = 900;

    /** 同一訂單的告警去重時窗（秒）：通知成功送出後延長至 6 小時，未定案會再次提醒 */
    private const ALERT_DEDUP_TTL = 21600;

    /** 單則通知的訂單筆數上限：大量卡單時分批發送，避免訊息超長整批失敗 */
    private const NOTIFY_CHUNK_SIZE = 20;

    protected $signature = 'withdrawal:alert-stuck-submitted
        {--minutes=10 : 停留門檻（分鐘），低於此時長視為正常在途}';

    protected $description = '掃描停留於代付請求中(21)超過門檻的代付訂單，先經三方查單自動查證，未定案者告警';

    public function __construct(
        private readonly WithdrawalPayoutQueryService $payoutQueryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $threshold = now()->subMinutes((int) $this->option('minutes'));
        $alerted = 0;
        $exitCode = self::SUCCESS;

        // chunkById 分批自 DB 載入：大量卡單不一次性 get() 撐爆記憶體，每批即一則通知
        WithdrawalOrder::query()
            ->where('status', WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value)
            ->where('updated_at', '<=', $threshold)
            ->select(['id', 'amount', 'updated_at'])
            ->chunkById(self::NOTIFY_CHUNK_SIZE, function ($batch) use (&$alerted, &$exitCode): bool {
                // 告警前先嘗試三方查單自動查證（#4137 擴充點）：查到有單者已補單號轉 22，
                // 不進告警清單；每輪掃描都會對尚未定案者重試查證
                $pending = $batch->reject(
                    fn (WithdrawalOrder $order): bool => $this->payoutQueryService->tryResolveSubmitted($order),
                );

                if ($pending->isEmpty()) {
                    return true;
                }

                // Cache::add 原子預占（短 TTL）：預占成功才納入本批，重疊執行不會重複發送同一單；
                // 送出成功後才延長至完整去重時窗（兩段式，防硬中斷造成長時間靜默）
                $reserved = $pending->filter(
                    fn (WithdrawalOrder $order): bool => Cache::add($this->dedupKey($order), 1, self::RESERVE_TTL),
                );

                if ($reserved->isEmpty()) {
                    return true;
                }

                $lines = $reserved->map(
                    // Carbon 3 的 diff 帶正負號：以過去時間為基準呼叫才是正值
                    fn (WithdrawalOrder $order): string => sprintf(
                        '訂單 %d（金額 %s，停留 %d 分鐘）',
                        $order->id,
                        $order->amount,
                        (int) $order->updated_at->diffInMinutes(now()),
                    ),
                )->implode(PHP_EOL);

                try {
                    BotNotify::send(sprintf(
                        '偵測到停留於代付請求中(21)超過 %d 分鐘的訂單（可能為發版砍 pod 中斷），請至支付商後台逐筆確認：有單以後台「填寫三方單號」轉 22，查無此單以「修改為代付失敗」轉 29。%s%s',
                        (int) $this->option('minutes'),
                        PHP_EOL,
                        $lines,
                    ));
                } catch (\Throwable $e) {
                    // 送出失敗＝歸還本批預占，下輪重試；已成功的前批維持標記
                    foreach ($reserved as $order) {
                        Cache::forget($this->dedupKey($order));
                    }

                    Log::error('withdrawal:alert-stuck-submitted 通知送出失敗', [
                        'batch_order_ids' => $reserved->pluck('id')->all(),
                        'error' => $e->getMessage(),
                    ]);
                    $this->error(sprintf('通知送出失敗，本批 %d 筆已歸還去重預占，下輪重試', $reserved->count()));
                    $exitCode = self::FAILURE;

                    return false; // 中止後續批次
                }

                // 通知成功送出：預占延長為完整去重時窗（送出後、延長前硬中斷＝15 分鐘後
                // 重複通知一次，寧可重複不可靜默）
                foreach ($reserved as $order) {
                    Cache::put($this->dedupKey($order), 1, self::ALERT_DEDUP_TTL);
                }

                $alerted += $reserved->count();

                return true;
            });

        if ($exitCode === self::SUCCESS) {
            $this->info($alerted > 0 ? sprintf('已告警 %d 筆停留訂單', $alerted) : '無需告警的停留訂單');
        }

        return $exitCode;
    }

    private function dedupKey(WithdrawalOrder $order): string
    {
        return 'withdrawal:stuck-submitted-alerted:' . $order->id;
    }
}
