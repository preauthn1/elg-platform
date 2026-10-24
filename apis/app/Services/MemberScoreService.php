<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\WalletEnum;
use App\Exceptions\InsufficientScoreException;
use App\Exceptions\MemberNotFoundException;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Models\MemberWallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;

/**
 * 積分（score）的發放、FIFO 扣除與到期失效——積分加減的唯一入口（target/tickets#4233）。
 *
 * 積分是活動的內部代幣，不是錢：不可提現、不抵注、無幣別。命名用 score 不用 point，
 * 因為 point 在本專案是「錢」的通用欄位名（orders.point、promotion_wallet_logs.point）。
 *
 * 三處資料必須在**同一個交易內**一鏈更新，缺一個金額就對不上：
 *   1. member_score_batches（批次，餘額的唯一真相）
 *   2. member_score_logs（異動紀錄，只增不改；一次扣除跨幾批就寫幾筆）
 *   3. WalletEnum::CODE_SCORE 的錢包列（總額投影，供單列查餘額）
 * 對不上時一律以批次表為準重算錢包列，不反向修批次。
 *
 * 🔴 **本 Service 的寫入方法不自己開交易，必須由呼叫端包在 DB::transaction 內呼叫**
 * （比照 WalletService 與 AbstractEventV2::join() 的慣例）。活動 V2（#4231）「扣點與發獎
 * 必須同一筆交易、任一失敗全部回滾」就靠這點：活動端開交易 → 呼叫本 Service → 它自己的
 * 發獎失敗時整包回滾。裸呼叫直接拋 LogicException。
 *
 * 鎖順序（WalletService::getMemberLockedWallets() docblock 的紅線，op#3075 事故）：
 * 每個寫入方法第一個 SQL 都是 `Member::lockForUpdate()`，再取鎖定的積分錢包列，
 * 之後才鎖批次。RR 下先一般讀再等鎖會讀到舊照片，鎖定讀不建快照——所以**呼叫端在同一交易內、
 * 呼叫本 Service 之前禁止先做一般讀**（範疇檢查、查會員等放交易外，或取鎖之後再做）：
 * 快照一旦在呼叫端被定住，本 Service 再怎麼鎖都讀舊照片。
 * ⚠️ 已知例外：活動 V2 的 join() 鏈（Frontend EventV2Controller → validate() → join() → grant()）在同一交易內
 * 必然先有 validate() 的一般讀，做不到「進 Service 前零一般讀」。現況安全是因為 grant() 路徑上每一個讀都是鎖定讀
 * （current read 不看快照）；**日後在 grant()／deduct() 內新增任何一般讀（例如 source_id 冪等查詢），必須同時處理
 * 活動 V2 這條鏈**，否則就是 op#3075 重演——不會報錯、只會少發或重發。
 *
 * FIFO：先扣到期日最早的批次，無到期日排最後，同到期日先扣早建立的（MemberScoreBatch::scopeFifo）。
 * 餘額不足整筆擋下、不部分扣除（InsufficientScoreException）。
 *
 * 到期：expired_at = 發放時刻 + N 小時，再**進位到下一個 10 分鐘刻度**（14:15:47 → 14:20:00、
 * 14:55 → 15:00；剛好落在刻度上不動）——與每 10 分鐘的 score:expire 排程對齊，後台顯示也乾淨；
 * 代價是會員最多多拿 <10 分鐘（Max 2026-09-16 定案，推翻先前「精確不進位」）。
 * +8 是整小時偏移，刻度在 UTC 與 +8 下相同。deduct() 內另做該會員的即時到期，把排程間隔的縫補掉。
 *
 * 精度：欄位 decimal(18,4)、內部運算 scale 4；業務規則限 2 位小數，由 normalizeScore() 擋下。
 */
class MemberScoreService
{
    /** 內部運算精度，對齊全專案金額慣例 */
    private const int SCALE = 4;

    /** 業務規則允許的小數位數（顯示與輸入皆 2 位） */
    private const int BUSINESS_SCALE = 2;

    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    /**
     * 會員可用積分——讀錢包投影列，單列查詢、不鎖、不需交易。
     *
     * 查無錢包列（從未發過積分）回 '0'。要在交易內改餘額請走 grant()／deduct()，不要拿這個值去算。
     */
    public function balance(int $memberId): string
    {
        $wallet = $this->walletService->queryMemberWallet($memberId, WalletEnum::CODE_SCORE);

        return $this->normalize((string) ($wallet?->balance ?? '0'));
    }

    /**
     * 發放一批積分。**必須在呼叫端的 DB::transaction 內呼叫。**
     *
     * @param  string  $score  正數、最多 2 位小數（'10.50'）
     * @param  int|null  $hours  有效期小時數；null = 無限期（不會被到期排程掃掉、FIFO 排最後）
     * @param  ScoreSourceTypeEnum  $source  來源系統（MANUAL、EVENT_V2_USER_JOIN…，不可 NONE）；新來源加 enum case 即可
     * @param  int  $sourceId  來源單據 id，指向的表依 $source（EVENT_V2_USER_JOIN＝event_v2_user_joins.id）；MANUAL 填 0。
     *                         **同一 ($source, $sourceId>0) 只能發一批**：member_score_batches.uniq_source_key（虛擬欄
     *                         source_type:source_id）在 DB 層擋重複，重發撞唯一鍵拋 UniqueConstraintViolationException、
     *                         呼叫端交易整包回滾——這是活動 V2 積分型（紀錄一出生即 COMPLETE、JOIN 互斥失效）的最後防線
     * @param  string|null  $operator  操作者帳號（人工調整帶操作代理；系統發放留 null）
     *
     * @throws InvalidArgumentException 積分格式不合（非正數、超過 2 位小數）、$hours 非正數或 $source 為 NONE
     * @throws LogicException 未在交易內呼叫
     */
    public function grant(
        Member $member,
        string $score,
        ?int $hours,
        ScoreSourceTypeEnum $source,
        int $sourceId = 0,
        ?string $remark = null,
        ?string $operator = null,
        int $adminId = 0,
    ): MemberScoreBatch {
        $this->assertInTransaction();
        $score = $this->normalizeScore($score);

        if ($hours !== null && $hours <= 0) {
            throw new InvalidArgumentException('Score validity hours must be a positive integer or null');
        }

        if ($source === ScoreSourceTypeEnum::NONE) {
            throw new InvalidArgumentException('Score batch source cannot be NONE; every batch must come from somewhere');
        }

        [$member, $wallet] = $this->lockMemberAndWallet($member);

        $now = Carbon::now();

        // 即時到期：與 deduct() 對稱。排程每 10 分鐘掃一次，間隔內錢包列仍含剛過期的積分，
        // 先清掉再入帳，否則 before/after 與投影餘額會在這 10 分鐘內多算已到期的分數
        $this->expireDueBatches($member, $wallet, $now);

        $expiredAt = $hours === null ? null : self::ceilToTenMinuteGrid($now->copy()->addHours($hours));

        $batch = MemberScoreBatch::create([
            'member_id' => $member->id,
            'score' => $score,
            'remaining_score' => $score,
            'expired_at' => $expiredAt,
            'source_type' => $source->value,
            'source_id' => $sourceId,
            'status' => ScoreBatchStatusEnum::ACTIVE->value,
            'admin' => $adminId,
        ]);

        $before = $this->normalize((string) $wallet->balance);
        $after = Common::numAdd($before, $score, self::SCALE);

        $this->writeLog(
            batch: $batch,
            type: $source->toGrantLogType(),
            direction: ScoreLogDirectionEnum::INCREASE,
            score: $score,
            before: $before,
            after: $after,
            expiredAt: $expiredAt,
            referenceType: $source->toGrantReference(),
            referenceId: $source === ScoreSourceTypeEnum::MANUAL ? 0 : $sourceId,
            operator: $operator,
            remark: $remark,
            adminId: $adminId,
        );

        $this->updateWalletBalance($wallet, $after, $adminId);

        return $batch;
    }

    /**
     * FIFO 扣除。**必須在呼叫端的 DB::transaction 內呼叫。**
     *
     * 內部先對該會員做即時到期（過期批次標失效、同步錢包列），再依 FIFO 逐批扣到滿足為止。
     * 餘額不足拋 InsufficientScoreException，整筆擋下、不部分扣除——呼叫端交易回滾即無任何殘留。
     *
     * @param  ScoreLogTypeEnum  $type  只接受 CONSUME（系統消耗）或 MANUAL（人工扣除）
     * @param  ScoreSourceTypeEnum  $referenceType  這次扣除對回哪個系統（活動 V2 傳 EVENT_V2_USER_JOIN；人工扣除 NONE）
     * @param  int  $referenceId  對回的單據 id（EVENT_V2_USER_JOIN＝event_v2_user_joins.id）；NONE 時 0
     * @return Collection<int, MemberScoreLog> 跨幾批就回幾筆 log，依扣除順序
     *
     * @throws InsufficientScoreException 餘額不足
     * @throws InvalidArgumentException 積分格式不合或 $type 不是扣除類
     * @throws LogicException 未在交易內呼叫，或批次加總覆蓋不了錢包列餘額（資料漂移，需人工對帳）
     */
    public function deduct(
        Member $member,
        string $score,
        ScoreLogTypeEnum $type,
        ScoreSourceTypeEnum $referenceType = ScoreSourceTypeEnum::NONE,
        int $referenceId = 0,
        ?string $remark = null,
        ?string $operator = null,
        int $adminId = 0,
    ): Collection {
        $this->assertInTransaction();
        $score = $this->normalizeScore($score);

        if (!in_array($type, [ScoreLogTypeEnum::CONSUME, ScoreLogTypeEnum::MANUAL], true)) {
            throw new InvalidArgumentException("Score log type {$type->name} is not a deduction type");
        }

        [$member, $wallet] = $this->lockMemberAndWallet($member);

        $now = Carbon::now();

        // 即時到期：排程每 10 分鐘掃一次，間隔內錢包列仍含剛過期的積分，這裡先清掉再扣
        $this->expireDueBatches($member, $wallet, $now);

        $balance = $this->normalize((string) $wallet->balance);

        if (Common::numCompare($balance, $score, self::SCALE) < 0) {
            throw new InsufficientScoreException(available: $balance, requested: $score);
        }

        $batches = MemberScoreBatch::query()
            ->where('member_id', $member->id)
            ->availableAt($now)
            ->fifo()
            ->lockForUpdate()
            ->get();

        $remaining = $score;
        $logs = collect();

        foreach ($batches as $batch) {
            $batchRemaining = $this->normalize((string) $batch->remaining_score);
            $take = Common::numCompare($batchRemaining, $remaining, self::SCALE) <= 0 ? $batchRemaining : $remaining;
            $after = Common::numSub($balance, $take, self::SCALE);

            $logs->push($this->writeLog(
                batch: $batch,
                type: $type,
                direction: ScoreLogDirectionEnum::DECREASE,
                score: $take,
                before: $balance,
                after: $after,
                expiredAt: null,
                referenceType: $referenceType,
                referenceId: $referenceId,
                operator: $operator,
                remark: $remark,
                adminId: $adminId,
            ));

            $newBatchRemaining = Common::numSub($batchRemaining, $take, self::SCALE);
            $batch->remaining_score = $newBatchRemaining;
            if (Common::numCompare($newBatchRemaining, '0', self::SCALE) === 0) {
                $batch->status = ScoreBatchStatusEnum::EXHAUSTED;
            }
            $batch->admin = $adminId;
            $batch->save();

            $balance = $after;
            $remaining = Common::numSub($remaining, $take, self::SCALE);

            if (Common::numCompare($remaining, '0', self::SCALE) === 0) {
                break;
            }
        }

        // 走到這裡代表錢包列餘額 ≥ 扣除數、批次加總卻不夠——投影與真相漂移，不可靜默扣成半套
        if (Common::numCompare($remaining, '0', self::SCALE) > 0) {
            throw new LogicException(sprintf(
                'Score batches do not cover wallet balance for member %d: short %s (wallet %s, requested %s)',
                $member->id,
                $remaining,
                (string) $wallet->balance,
                $score,
            ));
        }

        $this->updateWalletBalance($wallet, $balance, $adminId);

        return $logs;
    }

    /**
     * 把該會員在 $at 時點已到期的批次標失效並寫紀錄。**必須在呼叫端的 DB::transaction 內呼叫。**
     *
     * 供 score:expire 排程逐會員呼叫；deduct() 內也走同一段做即時到期。
     *
     * @param  Carbon|null  $at  以哪個時點判定到期；null = 現在。排程的 --at 參數即由此傳入
     * @return Collection<int, MemberScoreLog> 每個有剩餘的過期批次各一筆 EXPIRE log
     *
     * @throws LogicException 未在交易內呼叫
     */
    public function expireMember(Member $member, ?Carbon $at = null): Collection
    {
        $this->assertInTransaction();

        [$member, $wallet] = $this->lockMemberAndWallet($member);

        return $this->expireDueBatches($member, $wallet, $at ?? Carbon::now());
    }

    /**
     * 到期核心：鎖住已到期批次 → 逐批寫 EXPIRE log → 批次歸零標 EXPIRED → 同步錢包列。
     *
     * 呼叫前 $member 與 $wallet 必須已上鎖（lockMemberAndWallet()）。
     * 錢包列若因漂移扣成負數，夾到 0 並記 warning——到期是排程路徑，拋錯會讓該會員永遠卡住排程。
     *
     * @return Collection<int, MemberScoreLog>
     */
    private function expireDueBatches(Member $member, MemberWallet $wallet, Carbon $at): Collection
    {
        $batches = MemberScoreBatch::query()
            ->where('member_id', $member->id)
            ->dueAt($at)
            ->orderBy('expired_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($batches->isEmpty()) {
            return collect();
        }

        $balance = $this->normalize((string) $wallet->balance);
        $logs = collect();

        foreach ($batches as $batch) {
            $amount = $this->normalize((string) $batch->remaining_score);

            if (Common::numCompare($amount, '0', self::SCALE) > 0) {
                $after = Common::numSub($balance, $amount, self::SCALE);

                if (Common::numCompare($after, '0', self::SCALE) < 0) {
                    Log::warning('Score wallet balance below expiring batch total; clamping to 0', [
                        'member_id' => $member->id,
                        'batch_id' => $batch->id,
                        'wallet_balance' => $balance,
                        'batch_remaining' => $amount,
                    ]);
                    $after = '0';
                }

                $logs->push($this->writeLog(
                    batch: $batch,
                    type: ScoreLogTypeEnum::EXPIRE,
                    direction: ScoreLogDirectionEnum::DECREASE,
                    score: $amount,
                    before: $balance,
                    after: $after,
                    expiredAt: null,
                    referenceType: ScoreSourceTypeEnum::NONE,
                    referenceId: 0,
                    operator: null,
                    remark: '積分到期失效',
                    adminId: 0,
                ));

                $balance = $after;
            }

            $batch->remaining_score = '0';
            $batch->status = ScoreBatchStatusEnum::EXPIRED;
            $batch->save();
        }

        $this->updateWalletBalance($wallet, $balance, 0);

        return $logs;
    }

    /**
     * 鎖會員主鍵列（交易內第一個 SQL），再取鎖定的積分錢包列（查無則建）。
     *
     * @return array{0: Member, 1: MemberWallet}
     *
     * @throws MemberNotFoundException 會員不存在
     */
    private function lockMemberAndWallet(Member $member): array
    {
        $locked = Member::query()->lockForUpdate()->find($member->id);

        if ($locked === null) {
            throw new MemberNotFoundException("Member {$member->id} not found");
        }

        $wallet = $this->walletService->getLockedWallet($locked->id, WalletEnum::CODE_SCORE);

        if ($wallet === null) {
            // CODE_SCORE 不在 deprecatedTypes()，getLockedWallet 一定建得出來；到這裡是 Enum 被改壞
            throw new LogicException('Score wallet could not be resolved; WalletEnum::CODE_SCORE must not be deprecated');
        }

        return [$locked, $wallet];
    }

    private function writeLog(
        MemberScoreBatch $batch,
        ScoreLogTypeEnum $type,
        ScoreLogDirectionEnum $direction,
        string $score,
        string $before,
        string $after,
        ?Carbon $expiredAt,
        ScoreSourceTypeEnum $referenceType,
        int $referenceId,
        ?string $operator,
        ?string $remark,
        int $adminId,
    ): MemberScoreLog {
        return MemberScoreLog::create([
            'member_id' => $batch->member_id,
            'batch_id' => $batch->id,
            'type' => $type->value,
            'direction' => $direction->value,
            'score' => $score,
            'before_score' => $before,
            'after_score' => $after,
            'expired_at' => $expiredAt,
            'reference_type' => $referenceType->value,
            'reference_id' => $referenceId,
            'operator' => $operator,
            'remark' => $remark,
            'admin' => $adminId,
        ]);
    }

    /**
     * 錢包列是投影，這裡是全 Service 唯一改它 balance 的地方。
     */
    private function updateWalletBalance(MemberWallet $wallet, string $balance, int $adminId): void
    {
        $wallet->balance = $balance;
        $wallet->admin = $adminId;
        $wallet->save();
    }

    /**
     * 積分數量檢核：正數、最多 2 位小數，回傳 scale 4 的正規化字串。
     *
     * bcadd 是截斷不是四捨五入，'10.505' 取 2 位得 '10.50'，與 4 位 '10.5050' 不等 → 擋下。
     */
    private function normalizeScore(string $score): string
    {
        if (!is_numeric($score)) {
            throw new InvalidArgumentException("Score must be numeric, got '{$score}'");
        }

        $normalized = $this->normalize($score);

        if (Common::numCompare($normalized, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException("Score must be positive, got '{$score}'");
        }

        $businessScaled = Common::numAdd('0', $score, self::BUSINESS_SCALE);
        if (Common::numCompare($normalized, $businessScaled, self::SCALE) !== 0) {
            throw new InvalidArgumentException(sprintf(
                'Score allows at most %d decimal places, got \'%s\'',
                self::BUSINESS_SCALE,
                $score,
            ));
        }

        return $normalized;
    }

    /**
     * 進位到下一個 10 分鐘刻度：14:15:47 → 14:20:00、14:20:01 → 14:30:00、14:55 → 15:00；
     * 剛好在刻度上（14:20:00.000）不動。
     *
     * 前端 transaction-dialog 的到期預覽用同一條規則，兩邊算出的時間才會一致。
     */
    public static function ceilToTenMinuteGrid(Carbon $time): Carbon
    {
        $hasSubMinute = $time->second > 0 || $time->micro > 0;
        $result = $time->copy()->startOfMinute();
        $remainder = $result->minute % 10;

        if ($remainder !== 0 || $hasSubMinute) {
            $result->addMinutes(10 - $remainder);
        }

        return $result;
    }

    /**
     * 外部數值入境正規化（DB 讀出的 decimal 一律先過這裡再運算）
     */
    private function normalize(string $value): string
    {
        return Common::numAdd('0', $value, self::SCALE);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('MemberScoreService write methods must be called inside DB::transaction()');
        }
    }
}
