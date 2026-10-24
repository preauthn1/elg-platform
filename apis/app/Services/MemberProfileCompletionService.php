<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MemberRegisterLimitFieldEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Services\ValidateToken\CheckValidationCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * 會員補資料整合（3969）：把原本「一支一支做」的補資料（實名 / 支付密碼 / 綁手機 / 綁 email）
 * 整合成單一原子流程。依代理的 3970 註冊必填設定決定哪些欄位必須填滿；一個 transaction 完成後回傳新 token。
 *
 * 驗證碼綁定複用既有 CheckValidationCodeService::checkCodeProcess(type=1 綁定手機/信箱)，不重造。
 * 發送驗證碼仍走既有 send-validation-code 接口（前端於同一介面先呼叫），此處只做「填入後一起提交」。
 */
class MemberProfileCompletionService
{
    public function __construct(
        protected MemberFieldSettingService $fieldSettingService,
        protected CheckValidationCodeService $checkValidationCodeService,
        protected LoginService $loginService,
        protected MemberRegisterLimitService $memberRegisterLimitService,
    ) {}

    /**
     * 補資料並回傳新 token。
     *
     * @param  array{
     *     real_name?:string,
     *     payment_password?:string,
     *     mobile?:string, mobile_token?:string,
     *     email?:string, email_token?:string,
     *     domain?:string
     * }  $input
     *
     * @throws ApiException
     */
    public function complete(Member $member, array $input): string
    {
        $missing = $this->fieldSettingService->getMissingRequiredFields($member, $member->agent);

        // 依 missing 逐項要求；沒缺的欄位不強制、也不處理
        try {
            DB::beginTransaction();

            foreach ($missing as $fieldKey) {
                match ($fieldKey) {
                    MemberFieldSettingService::FIELD_PAYMENT_PASSWORD => $this->setPaymentPassword($member, $input),
                    'last_name' => $this->setRealName($member, $input),
                    'mobile' => $this->bindByValidationCode($input, 'mobile', $input['mobile_token'] ?? '', $member),
                    'email' => $this->bindByValidationCode($input, 'email', $input['email_token'] ?? '', $member),
                    'birthday' => $this->setBirthday($member, $input),
                    default => null, // 其餘欄位不在補資料範疇
                };
            }

            DB::commit();
        } catch (ApiException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 回傳刷新後的 token（取代舊流程「綁定後再次 web-app-login」那一步）
        return $this->loginService->genToken($member->id);
    }

    /**
     * 設定實名（存 first_name，只能設一次）。
     *
     * @throws ApiException
     */
    private function setRealName(Member $member, array $input): void
    {
        $realName = trim((string) ($input['real_name'] ?? ''));
        if ($realName === '') {
            throw new ApiException(__('error_message.2053', ['field' => '真實姓名']), 2053);
        }
        if ((string) $member->first_name !== '') {
            throw new ApiException(__('error_message.1097'), 1097); // 實名已設定
        }

        // 真實姓名上限(type=6 註冊/綁定限制):與 v2 註冊同一套檢查,補資料不可繞過
        $exceeded = $this->memberRegisterLimitService->check($member->agent, ['name' => $realName]);
        if ($exceeded !== null) {
            throw new ApiException(
                __('error_message.2055', ['field' => MemberRegisterLimitFieldEnum::from($exceeded)->label()]),
                2055,
            );
        }

        $member->update(['first_name' => $realName]);
    }

    /**
     * 設定生日（只能設一次，與既有 member/set-birthday 同語意）。
     *
     * @throws ApiException
     */
    private function setBirthday(Member $member, array $input): void
    {
        $birthday = trim((string) ($input['birthday'] ?? ''));
        if ($birthday === '') {
            throw new ApiException(__('error_message.2053', ['field' => '出生日期']), 2053);
        }
        if ((string) $member->birthday !== '' && (string) $member->birthday !== '0000-00-00') {
            throw new ApiException(__('error_message.1100'), 1100); // 生日已設定
        }

        $member->update(['birthday' => $birthday]);
    }

    /**
     * 設定支付密碼（Hash；不得與登入密碼相同）。
     *
     * @throws ApiException
     */
    private function setPaymentPassword(Member $member, array $input): void
    {
        $paymentPassword = [REDACTED] ($input['payment_password'] ?? '');
        if ($paymentPassword === '') {
            throw new ApiException(__('error_message.2053', ['field' => '支付密碼']), 2053);
        }
        if (Hash::check($paymentPassword, (string) $member->password)) {
            throw new ApiException(__('error_message.1094'), 1094); // 不得與登入密碼相同
        }

        $member->update([
            'payment_password' => Hash::make($paymentPassword),
            'admin' => 0,
        ]);
    }

    /**
     * 以驗證碼綁定手機 / 信箱（複用既有 checkCodeProcess，type=1）。
     *
     * @throws ApiException
     */
    private function bindByValidationCode(array $input, string $field, string $token, Member $member): void
    {
        $value = (string) ($input[$field] ?? '');
        $label = $field === 'mobile' ? '手機號碼' : '電子郵件';
        if ($value === '' || $token === '') {
            throw new ApiException(__('error_message.2053', ['field' => $label]), 2053);
        }

        $this->checkValidationCodeService->checkCodeProcess([
            $field => $value,
            'token' => $token,
            'domain' => $input['domain'] ?? '',
            'type' => 1, // BINDING_DATA：綁定手機或信箱
        ]);
    }
}
