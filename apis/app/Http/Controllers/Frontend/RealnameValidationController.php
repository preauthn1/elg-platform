<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\RealnameValidation\SetRequest;
use App\Http\Resources\Frontend\RealnameValidation\CheckRequestExistsResource;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\RealnameValidationRequest;
use App\Services\AgentService;
use App\Services\MemberFieldSettingService;
use App\Services\MemberRegisterLimitService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Group('RealnameValidation (實名驗證)')]
class RealnameValidationController extends Controller
{
    public function __construct(
        protected AgentService $agentService,
        protected MemberRegisterLimitService $memberRegisterLimitService,
        protected MemberFieldSettingService $memberFieldSettingService,
    ) {}

    /**
     * 提交真實姓名設定請求
     */
    public function set(SetRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $userData->agent;
        $agentSetting = $this->agentService->getAgentSettings($userData->agent);

        if ($member->realname_validate == 1) {
            return ApiResponse::error(
                message: __('error_message.2048'),
                code: HttpCode::HTTP_NOT_ACCEPTABLE->value
            );
        }

        $hasValidationRequest = RealnameValidationRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->exists();

        if ($hasValidationRequest) {
            return ApiResponse::error(
                message: __('error_message.2049'),
                code: HttpCode::HTTP_NOT_ACCEPTABLE->value
            );
        }

        if ($agentSetting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 1) {
            // 如果是開啟銀行卡的要檢查不重復卡號
            $isAccountDuplicate = DB::table('member_bank_accounts as mba')
                ->join('members as m', 'm.id', 'mba.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('mba.member_id', '!=', $member->id)
                ->where('mba.bank_id', $request->bank_id)
                ->where('mba.account_no', $request->bank_account)
                ->where('a.organization_id', $agent->organization_id)
                ->where('a.binding_scope_id', $agent->binding_scope_id)
                ->exists();

            if ($isAccountDuplicate) {
                return ApiResponse::error(message: __('param.bank_account.error') . '.');
            }
        }

        $realnameTrimmed = trim($request->realname);

        // 真實姓名重複限制:一律讀 type=6「註冊/綁定限制」的 name 上限(與 v2 註冊、TG 補資料同一套來源)。
        // 未勾選 = 不限制(6 白標舊 #6608 皆為「允許重複」,與此等價)。
        $exceeded = $this->memberRegisterLimitService->check($member->agent, [
            MemberRegisterLimitFieldEnum::NAME->value => $realnameTrimmed,
        ]);
        if ($exceeded !== null) {
            return ApiResponse::error(message: __('error_message.2065'));
        }

        $validationRequest = RealnameValidationRequest::create([
            'member_id' => $member->id,
            'realname' => $realnameTrimmed,
            'bank_id' => $request->bank_id,
            'bank_name' => $request->bank_name,
            'bank_account' => $request->bank_account,
            'status' => 0,
            'id_number' => $request->id_number,
            'birthday' => $request->birthday,
            'id_front_image' => $request->id_front_image,
            'id_back_image' => $request->id_back_image,
        ]);

        // 申請即通過(#6609)：等同後台按下通過。
        // 申請此時已寫入，這一步只是省掉人工審核，失敗不該讓會員的送出跟著失敗——
        // 記 log 後放它留在待審狀態，由後台人工處理即可。
        if (($agentSetting[AgentCustomizedSettingKeyEnum::REAL_NAME_VERIFICATION_AUTO_APPROVE] ?? 0) == 1) {
            try {
                $this->applyApproval($member, $validationRequest);
            } catch (Throwable $e) {
                Log::error(sprintf(
                    '實名驗證申請即通過失敗, request_id: %s, %s',
                    $validationRequest->id,
                    $e->getMessage()
                ));
            }
        }

        return ApiResponse::success(
            message: __('error_message.realname_validation.request')
        );
    }

    /**
     * 檢查真實姓名設定請求是否存在
     */
    public function check(Request $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $isValidated = $member->realname_validate;
        $isRequestExists = RealnameValidationRequest::query()
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->exists();

        if ($isValidated) {
            $message = __('error_message.1000');
        } elseif ($isRequestExists) {
            $message = __('error_message.2049');
        } else {
            $message = __('error_message.1096');
        }

        return ApiResponse::success(
            data: CheckRequestExistsResource::make([
                'is_validated' => (bool) $isValidated,
                'is_exists' => $isRequestExists,
                'fields' => $this->memberFieldSettingService->verificationRules($member->agent),
            ]),
            message: $message
        );
    }

    /**
     * 申請即通過：等同後台按下通過，改狀態、寫回真實姓名與生日並補上銀行帳號。
     * 整段同一個交易，中途失敗就整組不生效、申請維持待審由人工處理。
     */
    private function applyApproval(Member $member, RealnameValidationRequest $validationRequest): void
    {
        DB::transaction(function () use ($member, $validationRequest) {
            $validationRequest->update([
                'status' => 1,
                'validator_id' => 0,
            ]);

            $memberData = [
                'first_name' => $validationRequest->realname,
                'realname_validate' => 1,
            ];

            // 生日比照真實姓名以驗證通過的資料為準；未收此欄位時不動原值
            if (!empty($validationRequest->birthday)) {
                $memberData['birthday'] = $validationRequest->birthday;
            }

            $member->update($memberData);

            if (empty($validationRequest->bank_id) || empty($validationRequest->bank_account)) {
                return;
            }

            $exists = MemberBankAccount::query()
                ->where('member_id', $member->id)
                ->where('bank_id', $validationRequest->bank_id)
                ->where('account_no', $validationRequest->bank_account)
                ->where('status', 1)
                ->exists();

            if ($exists) {
                return;
            }

            MemberBankAccount::create([
                'member_id' => $member->id,
                'bank_id' => $validationRequest->bank_id,
                'account_name' => $validationRequest->realname,
                'account_no' => $validationRequest->bank_account,
                'status' => 1,
                'operator_cs' => 0,
            ]);
        });
    }
}
