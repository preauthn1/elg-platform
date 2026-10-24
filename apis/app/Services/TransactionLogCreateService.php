<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TransactionLogOptionalData;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\EventV2WalletTransactionLog;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Models\WalletTransactionLog;
use Exception;

/**
 * 錢包帳變流水的唯一建立入口（決定寫哪張表、產 transaction_no、必要時沖銷上一筆）。
 *
 * 每筆餘額異動都必須有對應流水列，**禁止再開第三套帳變紀錄表**。
 *
 * 寫哪張表由本 Service 決定：會員 `is_event_wallet`（Member accessor）或呼叫端建構時傳入的
 * `$isMultipleWallet` 為真 → wallet_transaction_logs，否則 → transaction_logs。
 * 會員另有 `is_event_v2` 時，在上面那列之外**再多寫一列** event_v2_wallet_transaction_logs
 * 當橋接——那是附加紀錄，不是第三個去處，查活動會員的流水要往 wallet_transaction_logs 找。
 *
 * 接入現況：遊戲／單一錢包側一律經本 Service；金流上分（PaymentService）、轉帳錢包
 * （MemberTransferService）與活動／後台側為**存量直建 Model**，
 * 新增寫入點優先走本 Service、不再新增直建。
 *
 * ⚠️ 注單層的彙整屬 total_bet_records，兩者只能透過 total_bet_record_transaction_logs
 * 或 total_bet_record_wallet_transaction_logs 對照，**禁止把注單欄位塞進本表**。
 * 餘額本身的讀寫與上鎖見 {@see WalletService}。
 */
class TransactionLogCreateService
{
    private ?int $type = null;

    private ?int $agentId = null;

    private int $singleWalletBetType;

    private ?string $sessionToken = [REDACTED];

    private TransactionLogOptionalData $optionalData;

    public function __construct(
        private Member $member,
        private ?VendorInfo $vendorInfo = null,
        private bool $isMultipleWallet = false,
        private $account = null
    ) {
        // make default
        $this->optionalData = new TransactionLogOptionalData;
        $this->singleWalletBetType = ($this->isMultipleWallet) ?
            WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value :
            TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value;
    }

    public function isMultipleWallet(): static
    {
        $this->isMultipleWallet = true;
        $this->singleWalletBetType = WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value;

        return $this;
    }

    public function setType(int $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * 指定寫入 log 的代理，用於「操作代理不等於會員所屬代理」的情境（如 CS 後台由指定代理開洗分／發紅包）。
     *
     * 未指定時沿用會員自身所屬代理（`member.parent`）；`transaction_logs.agent_id` 的欄位語意即為
     * 「實際異動的代理」，同一玩家可能由不同代理開洗分，故不能一律取 `member.parent`。
     */
    public function setAgentId(int $agentId): static
    {
        $this->agentId = $agentId;

        return $this;
    }

    public function setSessionToken(string $sessionToken): static
    {
        $this->sessionToken = [REDACTED];

        return $this;
    }

    public function setOptionalData(TransactionLogOptionalData $data): static
    {
        $this->optionalData = $data;

        return $this;
    }

    public function create($amount, float|string $balanceBefore, float|string $balanceAfter): WalletTransactionLog|TransactionLog
    {
        if ($this->member->is_event_wallet || $this->isMultipleWallet) {
            $logModel = new WalletTransactionLog;
            $transactionNo = Common::getTransactionNo(true);
        } else {
            $logModel = new TransactionLog;
            $transactionNo = Common::getTransactionNo(false);
        }

        if (is_null($this->type)) {
            $this->type = $this->singleWalletBetType;
        }

        $amount = $this->calcActualAmount($amount, $balanceBefore, $balanceAfter);

        [$needCreateTransactionLog, $transactionLog] = $this->verifyAndCloseLastTransactionLog($amount, $balanceAfter);
        if ($needCreateTransactionLog) {
            $log = [
                'transaction_no' => $transactionNo,
                'agent_id' => $this->agentId ?? $this->member->parent,
                'member_id' => $this->member->id,
                'vendor_id' => $this->optionalData->vendor_id,
                'order_id' => $this->optionalData->order_id,
                'type' => $this->type,
                'point' => $amount,
                'tax' => $this->optionalData->tax,
                'vendor_point' => $this->optionalData->vendor_point,
                'rate' => $this->optionalData->rate,
                'before_balance' => $balanceBefore,
                'balance' => $balanceAfter,
                'vendor_balance' => $this->optionalData->vendor_balance,
                'status' => $this->type === $this->singleWalletBetType ? 999 : 1000,
                'remark' => $this->optionalData->remark,
                'checksum' => $this->optionalData->checksum,
                'result' => $this->optionalData->result,
                'operator' => $this->optionalData->operator,
                'admin' => $this->optionalData->admin,
            ];

            if (is_object($this->vendorInfo)) {
                $log['vendor_id'] = $this->vendorInfo->vendor_id;
                $log['vendor_point'] = Common::numMul($amount, $this->vendorInfo->rate);
                $log['rate'] = $this->vendorInfo->rate;
                $log['vendor_balance'] = Common::numMul($balanceAfter, $this->vendorInfo->rate);
            }

            if (!empty($this->sessionToken)) {
                $log['remark'] = $this->sessionToken; // not assign null directly
            }

            try {
                if ($this->member->is_event_wallet) {
                    $log['wallet_type_id'] = WalletEnum::CODE_EVENT_WALLET;
                    $createdTransactionLog = $logModel->create($log);

                    // 只有真的參加 Event V2 活動才建立橋接記錄；新人專享挑戰沒有真實 event_v2_user_joins 可指
                    if ($this->member->is_event_v2) {
                        EventV2WalletTransactionLog::create([
                            'wallet_transaction_log_id' => $createdTransactionLog->id,
                            'vendor_id' => $log['vendor_id'],
                            'account_id' => $this->account?->id ?? null,
                            'account_username' => $this->account?->username ?? null,
                            'event_v2_user_join_id' => $this->member->eventV2Joins->first()->id,
                        ]);
                    }
                } elseif ($this->isMultipleWallet) {
                    $log['wallet_type_id'] = WalletEnum::CODE_GAME;
                    $createdTransactionLog = $logModel->create($log);
                } else {
                    $createdTransactionLog = $logModel->create($log);
                }
            } catch (Exception $e) {
                throw new Exception('Transaction log create failed', 500001);
            }

            return $createdTransactionLog;
        }

        return $transactionLog;
    }

    /**
     * @return array{0: bool, 1: WalletTransactionLog|TransactionLog|null}
     */
    private function verifyAndCloseLastTransactionLog($amount, float|string $balanceAfter): array
    {
        if (!is_object($this->vendorInfo)) {
            return [true, null];
        }

        if ($this->member->is_event_wallet) {
            return [true, null];
        }

        $transactionLog = $this->getLastTransactionLog();
        if (!is_object($transactionLog)) {
            // 查不到上一筆，不代表沒有待關閉的進行中紀錄：本交易若在等會員鎖之前做過一般讀，
            // REPEATABLE READ 的快照就停在那一刻，等到鎖之後仍讀不到別條連線剛 commit 的紀錄
            // （2026-09-05 TPower 事故的同一個競態，target/op_tickets#3075）。關閉走 UPDATE、
            // 屬當前讀，看得到最新 commit，所以照跑；會員真的沒有紀錄時它比對不到列、是 no-op。
            $this->closeGamingTransactionLog();

            return [true, null];
        }

        $needCreateTransactionLog = true;
        if ($this->type === $this->singleWalletBetType && $transactionLog->type == $this->singleWalletBetType) {
            $needCreateTransactionLog = $this->updateOrLockTransactionLog($transactionLog, $amount, $balanceAfter);

            // 回傳 false 代表本回合金額已合併進上一筆進行中紀錄，此時不能關閉同錢包型別的紀錄，
            // 關掉的正是剛合併進去的那筆；回傳 true 則沒有合併（上一筆已結算，或屬於別的
            // session／遊戲商而剛被關閉），本遊戲商若還留著進行中紀錄就該一併關掉。
            if ($needCreateTransactionLog) {
                $this->closeGamingTransactionLog();
            }

            // 下面兩支的錢包型別與 $isMultipleWallet 相反，各自的前置判斷會直接返回，實際不做事；
            // 保留是為了對稱，勿依「方法名看起來會關閉」來理解。
            if ($this->isMultipleWallet) {
                $this->closeSingleWalletTransactionLog();
            } else {
                $this->closeSingleWalletTransactionLog_MultipleWallet();
            }
        } else {
            $this->closeGamingTransactionLog();
        }

        return [$needCreateTransactionLog, $transactionLog];
    }

    /**
     * 以主鍵鎖定讀重取同一列，把它在資料庫的現況寫回傳入的物件。
     *
     * 傳入的物件來自 getLastTransactionLog() 的一般讀。本交易若在等會員鎖之前做過任何一般讀，
     * 讀到的內容就停在那一刻，看不到別條連線在那之後對同一列的異動。合併是「讀出金額再加上
     * 本次金額寫回」，拿過期金額去加會把對方剛寫入的金額整個蓋掉；判斷該筆是否已結算也會看到
     * 過期狀態（2026-09-05 TPower 事故的同一個競態，target/op_tickets#3075）。
     *
     * 鎖定讀讀得到最新內容。這裡以主鍵定位，只鎖這一列、不會鎖到相鄰區間，因此沒有並發插入
     * 互相卡住的風險；單一錢包路徑進來時會員列也早已鎖住，等待範圍有限。
     */
    private function refreshTransactionLogWithLock(mixed $transactionLog): void
    {
        if (!$transactionLog instanceof WalletTransactionLog && !$transactionLog instanceof TransactionLog) {
            return;
        }

        $locked = $transactionLog->newQuery()
            ->whereKey($transactionLog->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked instanceof WalletTransactionLog || $locked instanceof TransactionLog) {
            $transactionLog->setRawAttributes($locked->getAttributes(), true);
        }
    }

    /** 關閉本會員在本遊戲商尚未結算（狀態 999）的紀錄，錢包型別依 $isMultipleWallet 決定 */
    private function closeGamingTransactionLog(): void
    {
        if ($this->isMultipleWallet) {
            $this->closeSingleWalletTransactionLog_MultipleWallet();
        } else {
            $this->closeSingleWalletTransactionLog();
        }
    }

    public function getLastTransactionLog()
    {
        if ($this->isMultipleWallet) {
            $log = WalletTransactionLog::where('member_id', $this->member->id)
                ->where('wallet_type_id', WalletEnum::CODE_GAME)
                ->orderBy('id', 'DESC')
                ->first();

            if (is_object($log)) {
                return $log;
            }
        }

        return TransactionLog::where('member_id', $this->member->id)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * 取得本會員在本遊戲商進行中（status=999）的單錢包交易紀錄
     *
     * 條件與 closeSingleWalletTransactionLog() 相同，即「本回合尚未結算的那筆」；
     * 未結算紀錄是逐遊戲商各自持有，故不會取到其他遊戲商的紀錄。
     */
    public function getGamingTransactionLog(): WalletTransactionLog|TransactionLog|null
    {
        // 活動錢包每筆交易各自成立、不合併回合，無進行中紀錄可取
        if (!is_object($this->vendorInfo) || $this->member->is_event_wallet) {
            return null;
        }

        $query = $this->isMultipleWallet
            ? WalletTransactionLog::where('wallet_type_id', WalletEnum::CODE_GAME)
            : TransactionLog::query();

        return $query
            ->where('member_id', $this->member->id)
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('type', $this->singleWalletBetType)
            ->where('status', TransactionStatusEnum::STATUS_GAMING->value)
            ->orderByDesc('id')
            ->first();
    }

    private function updateOrLockTransactionLog($transactionLog, $amount, float|string $balanceAfter): bool
    {
        // 下面要「讀出金額再加上本次金額寫回」，先把這一列的資料庫現況鎖定讀回來，
        // 否則會拿過期金額累加，把別條連線剛寫入的金額蓋掉（見該方法註解）。
        $this->refreshTransactionLogWithLock($transactionLog);

        if ($transactionLog->status == 1000) {
            return true;
        }

        // 未鎖定的訂單就檢查用戶 session_token 或 vendor_id 是否相同
        $isSameSessionToken = [REDACTED] && ($transactionLog->remark == $this->sessionToken);
        $isSameVendor = empty($this->sessionToken) && ($transactionLog->vendor_id == $this->vendorInfo->vendor_id);
        if ($isSameSessionToken || $isSameVendor) {
            $new_point = Common::numAdd($transactionLog->point, $amount);

            $updateData = [];
            $updateData['point'] = $new_point;
            $updateData['vendor_point'] = Common::numMul($new_point, $this->vendorInfo->rate);
            $updateData['balance'] = $balanceAfter;
            $updateData['vendor_balance'] = Common::numMul($balanceAfter, $this->vendorInfo->rate);

            $transactionLog->update($updateData);

            return false;
        } else {
            $transactionLog->update(['status' => 1000]);

            return true;
        }
    }

    private function calcActualAmount($amount, float|string $balanceBefore, float|string $balanceAfter)
    {
        if ($this->type !== $this->singleWalletBetType) {
            return $amount;
        }

        if (Common::numSub($balanceAfter, $balanceBefore) !== Common::numMul($amount, -1)) {
            // In single wallet, bet amount should be positive, payout amount should be negative
            $amount = Common::numMul($amount, -1);
        }

        return $amount;
    }

    private function closeSingleWalletTransactionLog_MultipleWallet(): void
    {
        if (!$this->isMultipleWallet) {
            return;
        }

        WalletTransactionLog::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('type', $this->singleWalletBetType)
            ->where('status', 999)
            ->update(['status' => 1000]);
    }

    private function closeSingleWalletTransactionLog(): void
    {
        if ($this->isMultipleWallet) {
            return;
        }

        TransactionLog::where('member_id', $this->member->id)
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('type', $this->singleWalletBetType)
            ->where('status', 999)
            ->update(['status' => 1000]);
    }
}
