<?php

declare(strict_types=1);

namespace App\Jobs\Wallet;

use App\Enums\WalletEnum;
use App\Jobs\Concerns\SafelyDispatchable;
use App\Models\Member;
use App\Services\WalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * 影子同步：members.balance → member_wallets 一般錢包（type=1）
 *
 * 第一期 1.2 交付。產出的是影子資料——沒有任何業務邏輯讀它，只用於觀察期比對
 * 「如果切過去，數字對不對」。業務邏輯完全不動，仍全部走 members.balance。
 *
 * ⚠️ 啟用前置（簡報第 9 節）：本 Job 只是待命的代碼，接上任何觸發器（雙寫開關、
 * 排程、全量回填）之前，第二期幣別主檔治理必須先完成——會員的幣別來源不可信時，
 * 建出的錢包 currency_id 是猜的；覆寫語意只覆寫餘額，幣別要修就得回頭 UPDATE 存量。
 * 六站實測 TPower 有 60.9% 會員因 members.parent 指向不存在的代理而解析不出幣別，
 * 這批孤兒歸屬是第二期要先處理的主體。
 *
 * 派送一律走 dispatchSafely()（見 WalletService::dispatchShadowSync）：影子同步
 * 不得與主業務交易共生死。**刻意不做 queue 去重**（不掛 ShouldBeUnique）——
 * dispatchSafely 底層的 Bus::dispatch 不經 PendingDispatch、unique lock 根本不會
 * 被檢查，掛了只是誤導；且 unique lock 在 worker 被硬殺時會永久卡住該會員的同步
 * （圓桌四席交叉實證）。不去重的代價是同會員高頻變動時多派幾個 job，實測量級
 * 每日約 5 萬、覆寫語意冪等，可承受。
 *
 * 冪等：以「會員＋類型」兩元組定位錢包後覆寫餘額（不是累加），重跑不會讓餘額
 * 翻倍。餘額在鎖內才讀 members.balance 當前值（不帶快照），亂序執行也收斂到
 * 最新真相。精度 4 位 → 10 位補零，不失真。
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class SyncMemberBalanceToWalletJob implements ShouldQueue
{
    use Queueable;
    use SafelyDispatchable;

    public int $tries = 3;

    /** @var array<int, int> 死鎖或暫時性失敗的重試間隔（秒）；避免三次幾乎同時重打 */
    public array $backoff = [5, 15];

    public function __construct(public readonly int $memberId) {}

    public function handle(): void
    {
        $member = Member::query()->find($this->memberId);

        if (is_null($member)) {
            return;
        }

        $currencyId = (int) $member->currency_id;

        DB::transaction(function () use ($member, $currencyId) {
            // CODE_PC 非廢除型別，getLockedWallet 恆回實例（查無即建、帶組織幣別）
            $wallet = app(WalletService::class)->getLockedWallet(
                $member->id,
                WalletEnum::CODE_PC,
                $currencyId
            ) ?? throw new \LogicException("一般錢包（type=1）不得被列為已廢除，member_id={$member->id}");

            // 覆寫而非累加：真相在 members.balance，影子只是它的投影。
            //
            // ⚠️ 不變式：下面這條讀取必須是本交易的第一次一致性讀。上一行的
            // SELECT ... FOR UPDATE 是 locking read（InnoDB 不為它建 read view），
            // REPEATABLE READ 的 snapshot 因此在「取得 wallet 排他鎖之後」才建立，
            // 讀到的 members.balance 必為最新已提交值；兩個 job 併發時靠 wallet
            // 列的排他鎖排隊，後到者讀到更新的餘額。若日後在 FOR UPDATE 之前
            // 插入任何一般 SELECT，snapshot 提前建立、這裡會讀到舊值且無測試會紅。
            $wallet->balance = (string) Member::query()->whereKey($this->memberId)->value('balance');
            $wallet->save();
        });
    }
}
