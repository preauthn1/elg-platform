<?php

declare(strict_types=1);

namespace App\Vendors;

use App\CacheKeys\CacheKeys;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorBonusCodeRequestData;
use App\Data\VendorBonusCodeResponseData;
use App\Data\VendorResetPasswordData;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Enums\StatusEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorAccountException;
use App\Exceptions\VendorSetupException;
use App\Models\Agent;
use App\Models\EventV2Account;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorService;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\AbstractBetRecord;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * @knowledge rules/vendor-account-pool
 */
abstract class BaseAbstractVendor
{
    public readonly Vendor $vendor;

    public readonly ?Member $member;

    public readonly ?Agent $agent;

    /**
     * VendorInfo|EventVendorInfo：後者僅用於 {@see useLegacyEventContext()} 注入舊版活動專用商戶
     * 資訊，與 EventV2（`app/Services/EventV2/`）無關
     */
    public VendorInfo|EventVendorInfo|null $vendorInfo;

    protected array $config;

    protected ?AbstractAccount $vendorAccount;

    /**
     * 以下需要再繼承類別中實作
     */
    protected string $accountModel;

    protected string $betRecordModel;

    /**
     * @throws ApiException
     * @throws BindingResolutionException
     * @throws Throwable
     * @throws VendorSetupException
     */
    public function __construct(int $vendorId, ?Member $member = null)
    {
        if (!$this->accountModel) {
            throw new VendorSetupException('Account model not set or empty');
        }

        if (!$this->betRecordModel) {
            throw new VendorSetupException('Bet record model not set or empty');
        }

        $vendor = Cache::remember(
            key: CacheKeys::vendor($vendorId),
            ttl: CacheTtl::ONE_HOUR->value + rand(0, 60),
            callback: fn () => Vendor::where('id', $vendorId)->first(),
        );

        if (!$vendor) {
            throw new VendorSetupException("Vendor {$vendorId} not found");
        }

        $this->vendor = $vendor;
        Context::addHidden(ContextKeyEnum::VENDOR->value, $this->vendor);

        if (!$member) {
            return;
        }

        $this->member = $member;
        $this->agent = $member->agent;

        $vendorService = App::make(VendorService::class);
        $vendorService->setIsEventWallet($this->member->is_event_wallet);
        $vendorInfo = $vendorService->getVendorInfo($this->agent, $vendorId);

        if (!$vendorInfo) {
            throw new VendorSetupException(
                sprintf(
                    'VendorInfo not found: vendorId=%d, agentId=%d, memberId=%d, isEventWallet=%d, class=%s',
                    $vendorId,
                    $this->agent->id,
                    $this->member->id,
                    $this->member->is_event_wallet,
                    static::class
                )
            );
        }

        $this->vendorInfo = $vendorInfo;
        Context::addHidden(ContextKeyEnum::VENDOR_INFO->value, $this->vendorInfo);

        $this->vendorAccount = $this->getVendorAccount();
    }

    /**
     * 取得遊戲帳號
     *
     * @throws BindingResolutionException
     * @throws VendorAccountException
     * @throws VendorSetupException
     * @throws Throwable
     */
    public function getVendorAccount(): AbstractAccount
    {
        // 防護：此方法需要 member context，若建構時未傳入 member 則拋出例外
        if (!isset($this->member) || !isset($this->vendorInfo)) {
            throw new VendorSetupException('getVendorAccount() requires a member context');
        }

        $accountModel = App::make($this->accountModel);

        $vendorInfoId = $this->vendorInfo->id;
        $memberId = $this->member->id;

        // 提前驗證 EventV2 所需資料，避免在 Transaction 內才發現缺少關聯
        $eventV2JoinId = null;
        if ($this->member->is_event_v2) {
            // 明確確保 relation 已載入，避免 lazy load 時序不確定性
            $this->member->loadMissing('eventV2Joins');
            $eventV2JoinId = $this->member->eventV2Joins->first()?->id;

            if (!$eventV2JoinId) {
                throw new VendorAccountException('EventV2 join record not found for member ' . $memberId);
            }
        }

        // 序列化同一會員對同一遊戲商的並發請求，防止首次綁定時雙重綁定。
        // 已綁定的重入請求（Query 1 命中）不受影響，鎖超時拋出 LockTimeoutException 由上層處理。
        $lock = Cache::lock("vendor_account_bind:{$vendorInfoId}:{$memberId}", seconds: 10);

        $lock->block(seconds: 5);

        // Deadlock 有限重試：最多 3 次，退避 50ms / 100ms。
        // 僅針對 SQLSTATE 40001 / errno 1213（InnoDB Deadlock）重試，其他錯誤直接拋出。
        $maxAttempts = 3;
        $attempt = 0;

        try {
            while (++$attempt <= $maxAttempts) {
                try {
                    DB::beginTransaction();

                    // Query 1: 取得會員已綁定的遊戲帳號
                    // 不使用 lockForUpdate()：cache lock 已保護同一 (vendorInfoId, memberId) 序列化執行，
                    // FOR UPDATE 查無資料時會在 secondary index 產生 gap lock，導致並發綁定時 Deadlock。
                    $account = $accountModel
                        ->where('vendor_info_id', $vendorInfoId)
                        ->where('member_id', $memberId)
                        ->where('status', StatusEnum::ENABLE->value)
                        ->first();

                    if ($account) {
                        DB::commit();

                        return $account;
                    }

                    // Query 2: 綁定未使用的遊戲帳號
                    // 使用 FOR UPDATE SKIP LOCKED 避免多個 worker 並發時對同一池子產生 Deadlock
                    // 注意：必須傳入純字串，DB::raw() 回傳 Expression 物件會被 Laravel compileLock 降級為 for update
                    $account = $accountModel
                        ->where('vendor_info_id', $vendorInfoId)
                        ->where('member_id', 0) // member_id=0 代表未被綁定
                        ->where('status', StatusEnum::ENABLE->value)
                        ->orderBy('id')
                        ->lock('for update skip locked')
                        ->first();

                    // 沒有未使用的遊戲帳號可供綁定
                    if (!$account) {
                        throw new VendorAccountException(
                            sprintf(
                                '[%s] %s: %s (vendor_id=%d, vendor_info_id=%d)',
                                $this->vendor->code,
                                $this->vendorInfo->name,
                                __('error_message.no vendor account'),
                                $this->vendor->id,
                                $vendorInfoId,
                            )
                        );
                    }

                    // 驗證更新是否成功，避免靜默失敗
                    $updated = $account->update(['member_id' => $memberId]);
                    if (!$updated) {
                        throw new VendorAccountException(
                            sprintf('Failed to bind vendor account %d to member %d', $account->id, $memberId)
                        );
                    }

                    if ($eventV2JoinId) {
                        EventV2Account::create([
                            'vendor_id' => $this->vendor->id,
                            'account_id' => $account->id,
                            'event_v2_user_join_id' => $eventV2JoinId,
                        ]);
                    }

                    DB::commit();

                    Log::info('遊戲帳號綁定成功', [
                        'vendor_id' => $this->vendor->id,
                        'vendor_info_id' => $vendorInfoId,
                        'account_id' => $account->id,
                        'member_id' => $memberId,
                        'event_v2' => $eventV2JoinId !== null,
                    ]);

                    return $account;
                } catch (QueryException $e) {
                    DB::rollBack();

                    $sqlState = $e->errorInfo[0] ?? null;
                    $mysqlErrno = (int) ($e->errorInfo[1] ?? 0);
                    $isDeadlock = $sqlState === '40001' || $mysqlErrno === 1213;

                    if ($isDeadlock && $attempt < $maxAttempts) {
                        Log::warning('遊戲帳號綁定發生死鎖，進行重試', [
                            'attempt' => $attempt,
                            'max_attempts' => $maxAttempts,
                            'vendor_info_id' => $vendorInfoId,
                            'member_id' => $memberId,
                        ]);
                        usleep($attempt * 50_000); // 50ms / 100ms

                        continue;
                    }

                    throw $e;
                } catch (Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        } finally {
            $lock->release();
        }

        // 此行不可達：while 迴圈每次迭代必定透過 return 或 throw 離開
        throw new VendorAccountException(
            sprintf('Failed to bind vendor account after %d attempts (vendor_info_id=%d, member_id=%d)', $maxAttempts, $vendorInfoId, $memberId)
        );
    }

    public function getAccount(): AbstractAccount
    {
        return $this->vendorAccount;
    }

    public function isSingleWallet(): bool
    {
        return $this->vendor->is_single_wallet === 1;
    }

    public function getName(): string
    {
        return $this->vendorInfo->name;
    }

    // 匯率
    public function getRate(): string
    {
        return $this->vendorInfo->rate;
    }

    // 最小轉帳金額
    public function getTransferMinAmount(): string
    {
        return $this->vendorInfo->transfer_min_amount;
    }

    // 存款限制
    public function getDepositLimit(): string
    {
        return $this->vendorInfo->deposit_limit;
    }

    protected function getDefaultPassword(string $username, string $createdAt): string
    {
        return substr(md5($username . strtotime($createdAt)), 8, 12);
    }

    public function getAccountModel(): string
    {
        return $this->accountModel;
    }

    /**
     * 注單表的唯一鍵欄位（SupportsBetRecordFetch 用），由 BetRecord Model 宣告
     */
    public function betRecordUniqueKey(): string
    {
        /** @var AbstractBetRecord $model */
        $model = new $this->betRecordModel;
        $column = $model->getBetIdColumn();

        if ($column === null) {
            throw new LogicException($this->betRecordModel . ' 未宣告 betIdColumn，無法作為注單唯一鍵');
        }

        return $column;
    }

    /**
     * 注單上的玩家識別值對應到帳號表的哪個欄位（SupportsBetRecordFetch 用）：
     * 就是玩家在遊戲商那邊的帳號欄位，由 Account Model 宣告
     */
    public function betRecordAccountColumn(): string
    {
        /** @var AbstractAccount $model */
        $model = new $this->accountModel;
        $column = $model->getVendorUsernameColumn();

        if ($column === null) {
            throw new LogicException($this->accountModel . ' 未宣告 vendorUsernameColumn，無法解析注單的會員');
        }

        return $column;
    }

    public function getBetRecordModel(): string
    {
        return $this->betRecordModel;
    }

    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    public function setVendorInfo(VendorInfo|EventVendorInfo $vendorInfo): void
    {
        $this->vendorInfo = $vendorInfo;
        $this->setInfo($this->vendorInfo->info);
    }

    public function setVendorAccount(AbstractAccount $vendorAccount): void
    {
        $this->vendorAccount = $vendorAccount;
    }

    /**
     * 注入舊版活動專用商戶資訊／帳號，供 `refresh_ranking/game_url`（ticket 4005）等
     * V1 舊版活動沿用既有 Driver 的 getGameUrl() 邏輯，不重寫三方串接。
     *
     * 與 EventV2（`app/Services/EventV2/`、`is_event_v2`、`EventV2Account`）無關，
     * 是完全獨立的另一套「活動專用帳號池」機制（`event_{vendor}_accounts` 系列表）。
     *
     * 呼叫前須以 `member: null` 建構 Driver（跳過一般會員帳號解析）；子類別若在建構子中
     * 將 `vendorInfo`/`vendorAccount` 另外轉存成自己的屬性（如 UUSlotSW／VpowerSW），
     * 需 override 本方法補上該轉存邏輯。
     *
     * **為何不直接用 `setVendorInfo()` + `setVendorAccount()` 組合**：`setVendorInfo()` 內部
     * 會強制呼叫 `setInfo()`，而未 override `setInfo()` 的 Driver（如 WFSW／PegasusSW，
     * 兩者的 `getGameUrl()` 直接讀 `$this->vendorInfo->info`）會落到本類別預設實作而拋
     * `VendorSetupException`。本方法刻意不觸發 `setInfo()`，由需要的子類別自行在 override 內呼叫。
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        $this->vendorInfo = $eventVendorInfo;
        $this->vendorAccount = $eventAccount;
    }

    public function setInfo(array $info): void
    {
        // 不同遊戲商奇奇怪怪的商戶配置請在這方法宣告清楚
        throw new VendorSetupException(static::class . '::setInfo not implemented');
    }

    /**
     * 取得 DB 上儲存帳號 username 的「完整 prefix」，供排程查詢最新序號用。
     *
     * 與 `vendor_info.account_prefix`（送註冊用的 SEND prefix）的差別：
     * 部分遊戲商（如 JokerBaccaratSW）會自動把 merchant ID 加到 playerID 前才回傳，
     * DB 實際儲存值會帶上 merchant prefix。此時子類別需 override 回傳「merchant prefix + SEND prefix」。
     *
     * 預設回傳 SEND prefix 本身（多數 vendor 兩者相同）。
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
    {
        return (string) $vendorInfo->account_prefix;
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        throw new VendorSetupException(static::class . '::resetPassword not implemented');
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        throw new VendorSetupException(static::class . '::createAccount not implemented');
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        throw new VendorSetupException(static::class . '::getGameList not implemented');
    }

    public function getBonusCode(VendorBonusCodeRequestData $params): VendorBonusCodeResponseData
    {
        throw new VendorSetupException(static::class . '::getBonusCode not implemented');
    }
}
