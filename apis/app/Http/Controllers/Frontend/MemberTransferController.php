<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\ApiResponse;
use App\Helpers\BetHelper;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\MemberTransfer\GetVendorBalanceRequest;
use App\Http\Requests\Frontend\MemberTransfer\TransferBackRequest;
use App\Http\Resources\Frontend\MemberTransfer\GetVendorBalanceResource;
use App\Http\Resources\Frontend\MemberTransfer\TransferBackResource;
use App\Models\AgentCustomizedSetting;
use App\Models\Member;
use App\Models\PrizeContentFlowLimit;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\WalletTransactionLog;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Services\WalletService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Group('MemberTransfer (會員轉帳)')]
/**
 * @knowledge rules/currency-and-wallet-currency
 */
class MemberTransferController extends Controller
{
    /** @var array<int, array<int, int>>|null 維護中的遊戲商清單，依維護類型分組（同一請求內記憶化） */
    private ?array $maintainVendorIdsByType = null;

    public function __construct(
        protected VendorService $vendorService,
        protected MemberTransferService $memberTransferService,
        protected WalletService $walletService,
    ) {}

    /**
     * 會員轉帳操作（含轉回或轉入目標遊戲商）
     *
     * 確保先將前次遺留於遊戲商的餘額轉回本地後，再將餘額轉至目標遊戲商。
     * 若 vendor_id <= 0，則僅執行轉回本地刷新餘額操作。
     *
     * @throws ApiException
     * @throws Throwable
     */
    public function transfer(TransferBackRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();
        $member = $userData->member;
        $targetVendorId = (int) ($input['vendor_id'] ?? 0);

        // ===== 為什麼必須使用統一的會員級別鎖？ =====
        // 因為幾乎「所有轉帳請求」都會先執行「轉回本地」。若以 vendor_id 實作細粒度鎖，將造成：
        //
        // 危險場景（假設最後轉出至 Vendor 5，餘額 1000）：
        // T1: 請求A (vendor_id=0) 取鎖 member:100:transfer:vendor:0，查詢最後紀錄 = Vendor 5
        // T2: 請求B (vendor_id=6) 取鎖 member:100:transfer:vendor:6，查詢最後紀錄 = Vendor 5（鎖不同，順利通過）
        // T3: 請求A 發出 API，從 Vendor 5 轉回 1000 元
        // T4: 請求B 發出 API，也從 Vendor 5 轉回 1000 元（重複轉回，嚴重漏洞！）❌
        //
        // 結論：必須依賴會員級別鎖 (member:{id}:transfer)，嚴格串行化同一會員的所有轉帳操作。
        $lockKey = sprintf('member:%d:transfer', $member->id);
        $lockTtl = 60; // 轉帳操作可能涉及多次網絡請求（查詢 + 轉出 + 轉入）

        Log::info('開始轉帳請求', [
            'member_id' => $member->id,
            'vendor_id' => $targetVendorId,
            'lock_key' => $lockKey,
            'lock_ttl' => $lockTtl,
        ]);

        // 使用立即獲取鎖模式（waitSeconds=0），並發請求會立即失敗而非等待
        return $this->withLock(
            key: $lockKey,
            ttl: $lockTtl,
            callback: function () use ($member, $targetVendorId) {
                try {
                    // 1. 從最後一個遊戲商轉回餘額到本地（新會員/維護中/同目標時內部跳過）
                    $this->transferBackFromLastVendor($member, $targetVendorId);

                    // 2. 驗證轉入資格並解析目標遊戲商（vendor_id <= 0 僅刷新，回 null）
                    $vendorDriver = $this->validateTargetVendor($member, $targetVendorId);

                    // 3. 從本地轉入目標遊戲商（單一錢包共用本地錢包，不需實際轉帳）
                    $vendorBalance = $this->transferIntoTargetVendor($member, $targetVendorId, $vendorDriver);

                    // 4. 返回目前最後餘額
                    return $this->latestBalanceResponse($member, $targetVendorId, $vendorDriver, $vendorBalance);
                } catch (ApiException $e) {
                    return ApiResponse::error($e->getMessage());
                }
            },
            errorMsg: __('error_message.99993'),
        );
    }

    /**
     * 步驟1：將最後一次轉入的遊戲商餘額轉回本地
     *
     * 跳過條件見 shouldSkipTransferBack；只有轉帳錢包需要實際轉回。
     * 轉回失敗不阻斷主流程：Service 層已記錄詳細錯誤（若三方成功但DB失敗，Service 已標記轉丟）。
     */
    private function transferBackFromLastVendor(Member $member, int $targetVendorId): void
    {
        // 查詢最後一筆遊戲轉點記錄
        $lastLog = $this->memberTransferService->getLastTransferOutTransactionLog($member);

        if ($this->shouldSkipTransferBack($lastLog, $member, $targetVendorId)) {
            Log::debug('轉回階段跳過', [
                'member_id' => $member->id,
                'target_vendor_id' => $targetVendorId,
                'last_vendor_id' => $lastLog?->vendor_id,
            ]);

            return;
        }

        $transferBackVendorId = (int) $lastLog->vendor_id;

        try {
            $vendorDriver = $this->vendorService->getDriver($transferBackVendorId, $member);
        } catch (VendorSetupException $e) {
            // 可能 all agent_vendors on list, status = 0, vendor_info not found.
            Log::error('遊戲商配置錯誤', [
                'vendor_id' => $transferBackVendorId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        // 如果遊戲商是轉帳錢包, 才需要轉回本地
        if (!$vendorDriver instanceof AbstractTransferWallet) {
            return;
        }

        try {
            $this->memberTransferService->transferToLocal($vendorDriver);
        } catch (Throwable $e) {
            // Service 層已記錄詳細錯誤，此處補充流程警示，繼續後續轉出
            Log::warning('轉回本地失敗，略過繼續後續流程', [
                'vendor_id' => $transferBackVendorId,
                'member_id' => $member->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 是否跳過「先將最後遊戲商餘額轉回本地」
     */
    private function shouldSkipTransferBack(null|TransactionLog|WalletTransactionLog $lastLog, Member $member, int $targetVendorId): bool
    {
        // 沒有轉出記錄, 跳過轉回 (剛註冊的新會員, 餘額都在本地)
        if (!$lastLog) {
            return true;
        }

        // 遊戲方錢包維護中, 跳過轉回 (錢留在遊戲商, 維護結束後再轉回)
        if (in_array($lastLog->vendor_id, $this->walletMaintainVendorIds($member))) {
            return true;
        }

        // lastLog 與本次目標相同, 跳過轉回 (同遊戲商二次充值, 不需先轉回)
        return $lastLog->vendor_id == $targetVendorId;
    }

    /**
     * 步驟2：驗證轉入資格並解析目標遊戲商
     *
     * vendor_id <= 0 表示僅轉回刷新、無目標遊戲商，回傳 null。
     * 驗證不通過時 throw ApiException，由 callback 統一轉為錯誤回應。
     *
     * @throws ApiException
     */
    private function validateTargetVendor(Member $member, int $targetVendorId): AbstractTransferWallet|AbstractSingleWallet|null
    {
        if ($targetVendorId <= 0) {
            return null;
        }

        // 鎖分中且此遊戲商不被允許下注
        if (!BetHelper::isMemberFlowLimitAllowed($member, $targetVendorId)) {
            Log::warning('因打碼限制，該遊戲商不允許轉出', [
                'member_id' => $member->id,
                'vendor_id' => $targetVendorId,
            ]);

            throw new ApiException(__('error_message.event_mode.vendor_not_allowed_to_transfer_out'));
        }

        // 有未領取的活動獎勵，禁止轉入遊戲商
        if (BetHelper::hasUnclaimedPrize($member)) {
            throw new ApiException(__('error_message.1989'));
        }

        // 目標遊戲商維護中（任一類型，含只關遊戲）一律禁止轉入
        if (in_array($targetVendorId, $this->anyMaintainVendorIds($member))) {
            throw new ApiException(__('error_message.1938'));
        }

        return $this->vendorService->getDriver($targetVendorId, $member);
    }

    /**
     * 步驟3：將本地錢包餘額全數轉入目標遊戲商
     *
     * 無目標（僅刷新）或目標為單一錢包（餘額共用本地錢包）時不需實際轉帳，回傳 null。
     * walletBalance = 0 時 service 只查餘額不執行轉帳（transferToVendor 早期返回）；
     * 回傳值為轉帳後的遊戲商餘額（遊戲商幣別），由 service 直接提供。
     */
    private function transferIntoTargetVendor(Member $member, int $targetVendorId, AbstractTransferWallet|AbstractSingleWallet|null $vendorDriver): ?string
    {
        if (!$vendorDriver instanceof AbstractTransferWallet) {
            return null;
        }

        $memberWallets = $this->walletService->getMemberAllWallets($member);
        $transferResult = $this->memberTransferService->transferToVendor($vendorDriver, (string) $memberWallets->walletBalance);
        $vendorBalance = (string) $transferResult['vendor_balance'];

        Log::info('轉帳已完成 (轉帳錢包)', [
            'member_id' => $member->id,
            'vendor_id' => $targetVendorId,
            'vendor_balance' => $vendorBalance,
        ]);

        return $vendorBalance;
    }

    /**
     * 步驟4：回傳目前最後餘額
     *
     * 依目標型態組裝：無目標（已轉回本地）/ 單一錢包（依匯率換算）/ 轉帳錢包（帶轉帳後遊戲商餘額）。
     */
    private function latestBalanceResponse(
        Member $member,
        int $targetVendorId,
        AbstractTransferWallet|AbstractSingleWallet|null $vendorDriver,
        ?string $vendorBalance,
    ): JsonResponse {
        $memberWallets = $this->walletService->getMemberAllWallets($member);

        // vendor_id <= 0：完成自動轉回，回傳本地錢包餘額
        if ($vendorDriver === null) {
            Log::debug('轉回本地已完成', [
                'member_id' => $member->id,
                'vendor_id' => $targetVendorId,
            ]);

            return ApiResponse::success(TransferBackResource::make([
                'balance' => $memberWallets->mainWallet->balance,
                'game_wallet_balance' => $memberWallets->gameWallet?->balance ?? '0',
                'rolling_chip' => $memberWallets->rollingChipWallet->balance,
                'vendor_balance' => '0.00', // 已轉回本地，遊戲商餘額為0
            ]));
        }

        // 單錢包餘額共用本地錢包, 回傳依遊戲商匯率換算後的餘額
        if ($vendorDriver instanceof AbstractSingleWallet) {
            Log::debug('轉帳已完成（單錢包）', [
                'member_id' => $member->id,
                'vendor_id' => $targetVendorId,
            ]);

            return ApiResponse::success(TransferBackResource::make([
                'balance' => $memberWallets->mainWallet->balance,
                'game_wallet_balance' => $memberWallets->gameWallet?->balance ?? '0',
                'rolling_chip' => Common::numMul((string) $memberWallets->rollingChipWallet->balance, (string) $vendorDriver->getRate()),
                'vendor_balance' => Common::numMul((string) $memberWallets->walletBalance, (string) $vendorDriver->getRate()),
            ]));
        }

        return ApiResponse::success(TransferBackResource::make([
            'balance' => $memberWallets->mainWallet->balance,
            'game_wallet_balance' => $memberWallets->gameWallet?->balance ?? '0',
            'rolling_chip' => '0.00', // 轉帳錢包不支援禮碼
            'vendor_balance' => $vendorBalance,
        ]));
    }

    /**
     * 該代理下維護中的遊戲商，依維護類型分組（同一請求內只查一次 DB）
     *
     * @return array<int, array<int, int>> [維護類型 => vendor_id 列表]
     */
    private function maintainVendorIdsByType(Member $member): array
    {
        return $this->maintainVendorIdsByType ??= $this->vendorService->getMaintenanceVendorIdsByType($member->agent);
    }

    /**
     * 「錢包面向關閉」的維護中遊戲商（全關、關錢包）
     *
     * 供轉回跳過判斷使用：只關遊戲時錢包仍可動，遊戲商餘額照常轉回。
     */
    private function walletMaintainVendorIds(Member $member): array
    {
        $byType = $this->maintainVendorIdsByType($member);

        return array_merge(
            $byType[VendorMaintainTypeEnum::BOTH_CLOSED->value] ?? [],
            $byType[VendorMaintainTypeEnum::WALLET_CLOSED->value] ?? [],
        );
    }

    /**
     * 「任一類型」維護中的遊戲商（全關、關遊戲、關錢包）
     *
     * 供轉入驗證使用：維護期間不論錢包是否開啟，一律禁止轉入遊戲商。
     */
    private function anyMaintainVendorIds(Member $member): array
    {
        return array_merge(...array_values($this->maintainVendorIdsByType($member)));
    }

    /**
     * 漸進式轉回遊戲商餘額到本地會員錢包
     *
     * 1. 5分內呼叫第1次, 轉回最新一筆遊戲商餘額
     * 2. 5分內呼叫第2~3次, 轉回3個月內有轉入餘額的遊戲商
     * 3. 5分內呼叫第4次以上, 轉回該代理所有啟用的遊戲商
     *
     * @noinspection t
     */
    public function transferAll()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent;

        // ===== 重要：使用與 transfer() 相同的鎖 =====
        // transferAll() 會遍歷多個遊戲商並轉回本地
        // 必須與 transfer() 使用相同的鎖，防止並發衝突
        //
        // 危險場景（如果使用不同的鎖）：
        // T1: transfer(vendor_id=5) 正在從 Vendor 3 轉回
        // T2: transferAll() 也嘗試從 Vendor 3 轉回
        // 結果：重複轉帳！❌
        //
        // 解決方案：使用相同的會員級別鎖，確保嚴格串行化

        $lockKey = sprintf('member:%d:transfer', $member->id);

        Log::info('開始一鍵轉回請求（TransferAll）', [
            'member_id' => $member->id,
            'lock_key' => $lockKey,
        ]);

        // transferAll 可能需要遍歷多個遊戲商，使用更長的 TTL
        return $this->withLock(
            key: $lockKey,
            ttl: 120,
            errorMsg: __('error_message.99993'),
            callback: function () use ($member, $agent) {
                Log::info('已取得一鍵轉回鎖（TransferAll）', [
                    'member_id' => $member->id,
                ]);

                // 檢查5分鐘內呼叫次數
                $count = Common::getApiCallCount(request()->route()->uri(), 300, $member->id);

                $vendorIds = match (true) {
                    $count <= 1 => $this->resolveFirstCallVendorIds($member),
                    $count <= 3 => $this->resolveRecentCallVendorIds($member),
                    default => $this->vendorService->getVendorIds($agent),
                };

                Log::info('TransferAll 處理遊戲商清單', [
                    'member_id' => $member->id,
                    'vendor_count' => count($vendorIds),
                    'call_count' => $count,
                ]);

                // 檢查遊戲商是否維護
                $maintainVendorIds = $this->vendorService->getMaintenanceVendorIds(
                    agent: $member->agent,
                    status: [VendorMaintainTypeEnum::BOTH_CLOSED, VendorMaintainTypeEnum::WALLET_CLOSED],
                );

                // 將遊戲商餘額轉回本地錢包
                $successCount = 0;
                $failedCount = 0;

                foreach ($vendorIds as $vendorId) {
                    try {
                        // 若遊戲商維護中則跳過
                        if (in_array($vendorId, $maintainVendorIds)) {
                            Log::debug('遊戲商維護中，已略過', [
                                'vendor_id' => $vendorId,
                            ]);

                            continue;
                        }

                        try {
                            $vendorDriver = $this->vendorService->getDriver($vendorId, $member);
                        } catch (VendorSetupException $e) {
                            // ignore.
                            // 可能 all agent_vendors on list, status = 0, vendor_info not found.
                            Log::emergency(sprintf('[%s] 遊戲商設定例外（VendorSetupException）', $vendorId), [
                                'vendor_id' => $vendorId,
                                'message' => $e->getMessage(),
                            ]);

                            $failedCount++;

                            continue;
                        }

                        // 僅針對可轉帳錢包進行轉回
                        if ($vendorDriver instanceof AbstractTransferWallet) {
                            $this->memberTransferService->transferToLocal($vendorDriver);
                            $successCount++;
                        }
                    } catch (Throwable $e) {
                        Log::error(sprintf('[%s] transferAll 遊戲商轉回本地錢包失敗: %s', $vendorId, $e->getMessage()));
                    }
                }

                // 取得本地錢包餘額
                $memberWallets = $this->walletService->getMemberAllWallets($member);

                Log::info('TransferAll 成功完成', [
                    'member_id' => $member->id,
                    'total_vendors' => count($vendorIds),
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                ]);

                return ApiResponse::success(TransferBackResource::make([
                    'balance' => $memberWallets->mainWallet->balance,
                    'game_wallet_balance' => $memberWallets->gameWallet?->balance ?? '0',
                    'rolling_chip' => $memberWallets->rollingChipWallet->balance,
                    'vendor_balance' => '0.00',
                ]));
            });
    }

    /**
     * 查詢遊戲商餘額（回傳已換算為平台幣別）
     */
    public function getVendorBalance(GetVendorBalanceRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $vendorId = (int) $request->input('vendor_id');
        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);

        if (!$vendorDriver instanceof AbstractTransferWallet) {
            throw new ApiException('not transfer wallet');
        }

        $vendorBalance = $vendorDriver->getBalance();
        $balance = Common::numDiv((string) $vendorBalance, (string) $vendorDriver->getRate());

        return ApiResponse::success(GetVendorBalanceResource::make(['vendor_balance' => $balance]));
    }

    /**
     * 查詢遊戲商轉帳單狀態
     */
    public function checkTransferStatus(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $vendorId = (int) ($request->input('vendor_id') ?? 0);
        $transactionNo = $request->input('transaction_no') ?? '';

        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);
        $result = $vendorDriver->checkTransferStatus($transactionNo);

        return ApiResponse::success([
            'result' => $result->name,
        ]);
    }

    /**
     * 轉回近三個月有轉入記錄的遊戲商餘額
     *
     * @noinspection t
     */
    public function transferBackRecent()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $lockKey = sprintf('member:%d:transfer', $member->id);

        return $this->withLock($lockKey, 120, function () use ($member) {
            $this->memberTransferService->transferBackRecentVendors($member);

            $memberWallets = $this->walletService->getMemberAllWallets($member);

            return ApiResponse::success(TransferBackResource::make([
                'balance' => $memberWallets->mainWallet->balance,
                'game_wallet_balance' => $memberWallets->gameWallet?->balance ?? '0',
                'rolling_chip' => $memberWallets->rollingChipWallet->balance,
                'vendor_balance' => '0.00',
            ]));
        });
    }

    /**
     * TransferAll 第 1 次呼叫：僅轉回最後一筆遊戲商
     */
    private function resolveFirstCallVendorIds(Member $member): array
    {
        $lastLog = $this->memberTransferService->getLastTransferOutTransactionLog($member);

        return $lastLog ? [$lastLog->vendor_id] : [];
    }

    /**
     * TransferAll 第 2～3 次呼叫：轉回近三個月有轉入記錄的遊戲商
     */
    private function resolveRecentCallVendorIds(Member $member): array
    {
        return $this->memberTransferService->getTransferOutVendorIds(
            member: $member,
            start: Carbon::now()->subMonths(3)->startOfDay(),
        );
    }

    private function getEventVendorIds(Member $member): array
    {
        $eventLock = $member->event_mode;
        $eventVendorIds = [];
        if ($eventLock) {
            $eventVendorIds = PrizeContentFlowLimit::where('prize_content_id', $eventLock)
                ->where('vendor_id', '!=', 0)
                ->distinct()
                ->pluck('vendor_id')
                ->toArray();
        }

        return $eventVendorIds;
    }

    // =========================================================================
    // @deprecated 方法（未使用，保留供參考）
    // =========================================================================

    /**
     * @deprecated 暫時未使用，保留供參考
     *
     * @return array
     *
     * TODO: 暫時未使用, 搞半天這麼大費周章只是為了查詢維護中的遊戲商
     */
    public function getVendorListByMember(Member $member)
    {
        $agent = $member->agent;
        $device = null; // 裝置: 0 APP, 1 手機網頁, 2 電腦網頁
        // $type = 1; // 類型(預設1)1:遊戲列表，2:轉點列表
        $vendorStatus = null; // 狀態 1:開啟，2:coming soon，3:維護中

        $agentIds = $agent->hierarchy_array;
        $vendorIds = $this->vendorService->getVendorIds($agent);

        // 會員參與的活動關聯的遊戲商ID
        $eventVendorIds = $this->getEventVendorIds($member);

        // 取得 customer setting
        $filterSettingKeys = [
            AgentCustomizedSettingKeyEnum::VENDOR_SORT,
            AgentCustomizedSettingKeyEnum::HOT_VENDOR_FLAG,
            AgentCustomizedSettingKeyEnum::NEW_VENDOR_FLAG,
        ];
        $customizedSetting = AgentCustomizedSetting::query()
            ->where('agent_group_id', $agent->customized_setting_group_id)
            ->whereIn('setting_key', $filterSettingKeys)
            ->select(['setting_key', 'value'])
            ->pluck('value', 'setting_key')
            ->map(function ($value) {
                return explode(',', $value);
            })
            ->toArray();

        // 取得遊戲商列表
        $data = Vendor::with([
            'vendorLastMaintenance' => function ($query) use ($agentIds) {
                $query->whereIn('agent_id', $agentIds);
                $query->whereNotIn('exclude_agent_id', $agentIds);
            },
            'vendorRequestFails' => function ($query) {
                $query->where('status', '<', 2);
            },
        ])
            ->when($vendorStatus !== null, function ($query) use ($vendorStatus) { // @phpstan-ignore notIdentical.alwaysFalse (TODO: $vendorStatus 預留供未來篩選使用)
                $query->where('status', $vendorStatus);
            }, function ($query) {
                $query->where('status', '>', 0);
            })
            ->when($device, function ($query) use ($device) {
                // 根據玩家設備篩選顯示的遊戲商
                $deviceMapping = [
                    0 => 'show_on_app', // APP版顯示的遊戲商
                    1 => 'show_on_mobile', // 手機版顯示的遊戲商
                    2 => 'show_on_pc', // PC版顯示的遊戲商
                ];
                if (isset($deviceMapping[$device])) { // @phpstan-ignore isset.offset (TODO: $device 預留供未來篩選使用)
                    $query->where($deviceMapping[$device], 1);
                }
            })
            ->where('show_on_app', 1)
            ->whereIn('id', $vendorIds)
            ->groupBy('id')
            ->orderBy('sort', 'DESC')
            ->get();

        // 整理輸出格式
        $data = $data->map(function ($item) use ($eventVendorIds) {
            $maintenance = $item->vendorLastMaintenance;
            $status = 4;
            if ($maintenance && $maintenance->id && ($maintenance->type == 1 || $maintenance->type == 2)) {
                $status = 3;
            }
            if (in_array($item->id, $eventVendorIds)) {
                $status = $item->status;
            }

            return [
                'id' => $item->id,
                'name' => $item->name,
                'rate' => $item->rate,
                'image' => $item->image,
                'app_icon' => $item->app_icon,
                'is_new' => 0,
                'is_hot' => 0,
                'need_login' => $item->need_login,
                'only_one_game' => $item->only_one_game,
                'is_slow' => $item->is_slow,
                'vendor_status' => $item->status,
                'status' => $status,
                'maintenance_type' => $maintenance?->type ?? 0,
                'maintenance_end_time' => $maintenance?->end_time,
                'is_single_wallet' => $item->is_single_wallet,
            ];
        });

        // 設定是否為熱門遊戲
        $data = $data->map(function ($item) use ($customizedSetting) {
            $item['is_hot'] = in_array($item['id'], $customizedSetting[AgentCustomizedSettingKeyEnum::HOT_VENDOR_FLAG]) ? 1 : 0;
            $item['is_new'] = in_array($item['id'], $customizedSetting[AgentCustomizedSettingKeyEnum::NEW_VENDOR_FLAG]) ? 1 : 0;

            return $item;
        });

        // 根據 $customSorting 調整排序
        $data = $data->sortBy(function ($item) use ($customizedSetting) {
            $customSorting = $customizedSetting[AgentCustomizedSettingKeyEnum::VENDOR_SORT];

            return in_array($item['id'], $customSorting) ? array_search($item['id'], $customSorting) : PHP_INT_MAX;
        })->values();

        return $data->toArray();
    }
}
