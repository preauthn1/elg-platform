<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\General\HttpCode;
use App\Enums\IpRestrictionBlockItemEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\ValidateToken\CheckCodeRequest;
use App\Http\Requests\Frontend\ValidateToken\ResetEmailRequest;
use App\Http\Requests\Frontend\ValidateToken\ResetMobileRequest;
use App\Http\Requests\Frontend\ValidateToken\ResetPasswordRequest;
use App\Http\Requests\Frontend\ValidateToken\SendCodeRequest;
use App\Services\Captcha\SliderCaptchaService;
use App\Services\IpRestrictionGuard;
use App\Services\MemberFieldSettingService;
use App\Services\ValidateToken\CheckValidationCodeService;
use App\Services\ValidateToken\MemberNameService;
use App\Services\ValidateToken\ResetEmailService;
use App\Services\ValidateToken\ResetMobileService;
use App\Services\ValidateToken\ResetPasswordService;
use App\Services\ValidateToken\SendValidationCode\OnePageRegisterService;
use App\Services\ValidateToken\SendValidationCode\RegisterV2SendService;
use App\Services\ValidateToken\SendValidationCode\WithMemberIdService;
use App\Services\ValidateToken\SendValidationCode\WithoutMemberIdService;
use App\Services\ValidateToken\SendValidationCodeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Group('ValidationCode (驗證碼)')]
/**
 * type 驗證類型：
 * 1=資料驗證,
 * 2=忘記密碼,
 * 3=找回密碼,
 * 4=洗分驗證,
 * 5=支付密碼,
 * 6=忘記支付密碼,
 * 7=洗分驗證,
 * 8=一頁註冊,
 * 9=修改綁定手機/email,
 * 10=登入手機/email驗證,
 * 11=驗證碼驗證,
 *
 * verify_by 驗證方式：1=手機, 2=電子信箱
 */
class ValidationCodeController extends Controller
{
    public function __construct(
        protected SendValidationCodeService $sendService,
        protected CheckValidationCodeService $checkService,
        protected MemberNameService $memberNameService,
        protected ResetPasswordService $resetPasswordService,
        protected ResetMobileService $resetMobileService,
        protected ResetEmailService $resetEmailService,
        protected WithMemberIdService $withMemberIdService,
        protected WithoutMemberIdService $withoutMemberIdService,
        protected OnePageRegisterService $onePageRegisterService,
        protected RegisterV2SendService $registerV2SendService,
        protected IpRestrictionGuard $ipRestrictionGuard,
        protected SliderCaptchaService $captchaService,
        protected MemberFieldSettingService $memberFieldSettingService,
    ) {}

    /**
     * 發送訪客驗證碼
     *
     * 需傳入驗證過的電子信箱或手機號碼
     *
     * 支援 types:
     * 2=忘記密碼, 3=找回密碼, 4=洗分驗證, 8=一頁註冊
     *
     * OLD: /api/sendValidateCode
     *
     * @throws ApiException
     * @throws LockTimeoutException
     */
    public function sendCodeToGuest(SendCodeRequest $request)
    {
        return $this->handleSendCode($request, function ($input, $agent, $member) {
            return match ($input['type']) {
                2, 3, 4, 10 => $this->withoutMemberIdService->handle($input, $agent), // missing member_id
                8 => $this->onePageRegisterService->handle($input), // guest registration
                12 => $this->registerV2SendService->handle($input), // 3969 v2 註冊(非會員發手機/email 驗證碼)
                default => throw new ApiException('Unacceptable types: ' . $input['type']),
            };
        });
    }

    /**
     * 發送會員驗證碼
     *
     * 支援 types:
     * 1=資料驗證, 5=支付密碼, 6=忘記支付密碼, 7=洗分驗證, 9=修改綁定手機/email, 10.登入手機/email驗證, 11.驗證碼驗證
     *
     * @throws ApiException
     * @throws LockTimeoutException
     */
    public function sendCodeToMember(SendCodeRequest $request)
    {
        return $this->handleSendCode($request, function ($input, $agent, $member) {
            return match ($input['type']) {
                1, 5, 6, 7, 9, 10, 11 => $this->withMemberIdService->handle($input, $member), // has member_id
                default => throw new ApiException('Unacceptable types: ' . $input['type']),
            };
        });
    }

    /**
     * @throws ApiException
     * @throws LockTimeoutException
     */
    private function handleSendCode(SendCodeRequest $request, callable $callback)
    {
        $input = $request->validated();

        // 風控IP限制(4096)：來源IP有勾選「簡訊驗證」的啟用規則 → 擋發送並累計重複次數。
        // 只擋簡訊(verify_by=1)；email 驗證不在此項目範圍內。訪客/會員兩支發送 API 共用此進入點
        if ((int) $input['verify_by'] === 1
            && $this->ipRestrictionGuard->blockAttempt(Common::getIp(), IpRestrictionBlockItemEnum::SMS)
        ) {
            throw new ApiException(
                IpRestrictionBlockItemEnum::SMS->message(),
                IpRestrictionBlockItemEnum::SMS->errorCode(),
            );
        }

        $userData = $this->getUserContext();
        $member = $userData->member;

        $agent = $userData->agent;

        // 驗證組織是否存在
        if (!$agent) {
            return ApiResponse::error(__('error_message.9997'));
        }

        // 4188 滑塊驗證閘門：訪客與會員兩條發送路徑都經過這裡
        $this->captchaService->assertVerified($agent, $input['captcha_token'] ?? null);

        $lockKey = sprintf('send_validation_code:%s:%s%s', $input['type'], $input['mobile'] ?? '', $input['email'] ?? '');

        // 使用分佈式鎖保護關鍵邏輯
        $result = Cache::lock($lockKey, 10)->block(5, function () use ($input, $agent, $member, $callback) {
            return $callback($input, $agent, $member);
        });

        if (!$result) {
            throw new ApiException(__('error_message.2003'));
        }

        return ApiResponse::success();
    }

    /**
     * 驗證發出的驗證碼
     *
     * 需傳入驗證過的電子信箱或手機號碼,
     * 不會傳入 type 或 member_id,
     * 但可以透過返回 ValidateToken 取得
     *
     * OLD: /api/checkValidateCode
     *
     * @throws LockTimeoutException
     */
    public function checkCode(CheckCodeRequest $request)
    {
        $input = $request->validated();
        $lockKey = sprintf('check_validation_code:%s%s', $input['mobile'] ?? '', $input['email'] ?? '');

        // 使用分佈式鎖保護關鍵邏輯
        $member = Cache::lock($lockKey, 10)->block(5, function () use ($input) {
            return $this->checkService->checkCodeProcess($input);
        });

        return ApiResponse::success([
            $member->username,
        ]);
    }

    /**
     * 取得會員帳號選單
     *
     * type=2 忘記密碼 sendCode > 取得會員帳號選單 getMemberNames > 重設密碼 resetPassword
     *
     * OLD: /api/checkValidateCode
     *
     * @throws ApiException
     */
    public function getMemberNames(CheckCodeRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $agent = $userData->agent;

        $usernameList = $this->memberNameService->getMemberNameList($input, $agent);

        return ApiResponse::success([
            'username_list' => $usernameList,
        ]);
    }

    /**
     * 忘記密碼
     *
     * type=2 忘記密碼 sendCode > 取得會員帳號選單 getMemberNames > 重設密碼 resetPassword
     *
     * OLD: /api/resetPassword
     *
     * @throws ApiException
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $input = $request->validated();

        // 密碼規則要在 try 外檢查：try 內的例外一律轉成登出，玩家會看不到是哪條規則沒過
        $this->memberFieldSettingService->passwordPolicy($this->getUserContext()->agent)
            ->assert($input['new_password']);

        try {
            $this->resetPasswordService->resetPassword($input);
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            // 通知前端登出
            return ApiResponse::error(
                message: __('error_message.9998'),
                code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
            );
        }

        return ApiResponse::success();
    }

    /**
     * 重新綁定手機
     */
    public function resetMobile(ResetMobileRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        // try {
        $this->resetMobileService->resetMobile($userData->member, $input);
        // } catch (\Exception $e) {
        //     // 通知前端登出
        //     return ApiResponse::error(
        //         message: __('error_message.9998'),
        //         code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
        //     );
        // }

        return ApiResponse::success();
    }

    /**
     * 重新綁定信箱
     */
    public function resetEmail(ResetEmailRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        // try {
        $this->resetEmailService->resetEmail($userData->member, $input);
        // } catch (\Exception $e) {
        //     // 通知前端登出
        //     return ApiResponse::error(
        //         message: __('error_message.9998'),
        //         code: HttpCode::HTTP_REDIRECT_LOGOUT->value,
        //     );
        // }

        return ApiResponse::success($userData->member->agent->toArray());
    }
}
