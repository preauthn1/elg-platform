<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Data\VendorResetPasswordData;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Vendor\BalanceRequest;
use App\Http\Requests\Agent\Vendor\ResetPasswordRequest;
use App\Http\Resources\Agent\Vendor\BalanceResource;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\Agent\AgentOperateLogService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Models\AbstractAccount;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

#[Group('vendor (代理操作會員遊戲帳號)')]
class VendorController extends Controller
{
    public function __construct(
        private VendorService $vendorService,
        private AgentOperateLogService $agentOperateLogService,
    ) {}

    /**
     * 後台修改會員遊戲帳號密碼
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $input = $request->validated();
        $agent = $this->getAgentContext()->agent;

        // 僅限 admin(agent_id=1)與其子帳號（host_id=1）操作
        if ($agent->id !== 1 && $agent->host_id !== 1) {
            throw new ApiException(__('error_message.9003'));
        }

        $member = Member::where('id', $input['member_id'])->first();

        if (!$member) {
            throw new ApiException(__('error_message.1007'));
        }

        $vendorId = (int) $input['vendor_id'];
        $vendor = Vendor::find($vendorId);

        if (!$vendor) {
            throw new ApiException(__('error_message.9994') . '(1)');
        }

        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);

        // 同一 vendor_id 對同一會員可能綁定多個遊戲帳號，需依 vendor_username 指定其一
        $accountModel = App::make($vendorDriver->getAccountModel());
        $account = $accountModel
            ->where('vendor_info_id', $vendorDriver->vendorInfo->id)
            ->where('member_id', $member->id)
            ->where($accountModel->getVendorUsernameColumn(), $input['vendor_username'])
            ->first();

        if (!$account) {
            throw new ApiException(__('error_message.9994') . '(2)');
        }

        $vendorDriver->setVendorAccount($account);

        $newPassword = [REDACTED];
        $params = VendorResetPasswordData::from([
            'newPassword' => $newPassword,
        ]);

        try {
            $result = $vendorDriver->resetPassword($params);
        } catch (VendorSetupException $e) {
            throw new ApiException($vendor->name . ' does not support resetPassword');
        }

        if ($result == VendorTransferResultEnum::SUCCESS) {
            $this->agentOperateLogService->log(
                agentId: $agent->id,
                type: AgentOperateTypeEnum::RESET_VENDOR_PASSWORD,
                debugInfo: new PlainDebugInfo([
                    'member_id' => $member->id,
                    'vendor_id' => $vendorId,
                    'vendor_username' => $input['vendor_username'],
                ]),
                ip: Common::getAgentClientIp(),
                referenceId: (string) $account->id,
                referenceType: AgentOperateReferenceTypeEnum::VENDOR_ACCOUNT,
            );

            return ApiResponse::success();
        }

        throw new ApiException('Password change failed');
    }

    /**
     * 查詢會員在遊戲商的錢包餘額
     *
     * 取代舊系統 API_Agent 的 api/member/vendor/balance（target/tickets#4005）。
     * 舊系統由後台代發會員 token，再轉打舊系統 API_Frontend 的 api/getVendorBalance。
     * 這裡直接以 agent token 呼叫 Vendor Driver。
     * 另外用 assertMemberInScope() 檢查查詢對象在自己的下線範圍內，舊系統沒有這道檢查。
     *
     * 僅轉帳錢包（AbstractTransferWallet）支援餘額查詢；無縫錢包共用本地錢包，無獨立餘額。
     */
    public function balance(BalanceRequest $request)
    {
        $input = $request->validated();
        $agent = $this->getAgentContext()->agent;

        $member = Member::with('agent')->find((int) $input['member_id']);

        if (!$member) {
            throw new ApiException(__('error_message.1007'));
        }

        $this->assertMemberInScope($agent, $member);

        $vendorId = (int) $input['vendor_id'];
        $vendor = Vendor::find($vendorId);

        if (!$vendor) {
            throw new ApiException(__('error_message.9994') . '(1)');
        }

        $vendorDriver = $this->vendorService->getDriver($vendorId, $member);

        if (!$vendorDriver instanceof AbstractTransferWallet) {
            throw new ApiException($vendor->name . ' is not a transfer wallet');
        }

        // 同一 vendor_id 對同一會員可能綁定多個遊戲帳號；未指定時沿用 driver 預設綁定帳號
        $vendorUsername = $input['vendor_username'] ?? null;

        if ($vendorUsername !== null && $vendorUsername !== '') {
            $account = $this->findVendorAccount($vendorDriver, $member, $vendorUsername);

            if (!$account) {
                throw new ApiException(__('error_message.9994') . '(2)');
            }

            $vendorDriver->setVendorAccount($account);
        }

        $vendorBalance = $vendorDriver->getBalance();
        $balance = Common::numDiv((string) $vendorBalance, (string) $vendorDriver->getRate());

        $vendorAccount = $vendorDriver->getAccount();
        $usernameColumn = $vendorAccount->getVendorUsernameColumn();

        return ApiResponse::success(BalanceResource::make([
            'vendor_balance' => $balance,
            'vendor_username' => $usernameColumn === null ? '' : (string) $vendorAccount->{$usernameColumn},
        ]));
    }

    /**
     * 依 vendor_username 取出該會員在此 vendor_info 下的指定遊戲帳號。
     */
    private function findVendorAccount(AbstractTransferWallet $vendorDriver, Member $member, string $vendorUsername): ?AbstractAccount
    {
        $accountModel = App::make($vendorDriver->getAccountModel());
        $usernameColumn = $accountModel->getVendorUsernameColumn();

        if ($usernameColumn === null) {
            return null;
        }

        return $accountModel
            ->where('vendor_info_id', $vendorDriver->vendorInfo->id)
            ->where('member_id', $member->id)
            ->where($usernameColumn, $vendorUsername)
            ->first();
    }

    /**
     * 檢查會員是否落在該代理的可視範疇。
     *
     * 比照 MemberActivityFilterService::scopePath()：admin(id=1) 與其子帳號（host_id=1）不限制；
     * 代理子帳號（host_id!=0）沿用其上層代理的範疇。
     */
    private function assertMemberInScope(Agent $agent, Member $member): void
    {
        if ($agent->id == 1 || $agent->host_id == 1) {
            return;
        }

        $scopePath = $agent->hierarchy_path;

        if ($agent->host_id != 0) {
            $upperAgent = Agent::where('id', $agent->host_id)->first();
            $scopePath = $upperAgent->hierarchy_path ?? $scopePath;
        }

        $memberPath = $member->agent?->hierarchy_path;

        if ($scopePath === null || $memberPath === null || !str_starts_with((string) $memberPath, (string) $scopePath)) {
            throw new ApiException(__('error_message.9003'));
        }
    }
}
