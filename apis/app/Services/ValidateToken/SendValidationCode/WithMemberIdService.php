<?php

declare(strict_types=1);

namespace App\Services\ValidateToken\SendValidationCode;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\HttpCode;
use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Services\ValidateToken\SendValidationCodeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * 登入後, 有 member_id
 * 適用 type: 1, 5, 6, 7, 9, 10 ,11
 *
 * {
 *     'domain': 'example.invalid',
 *     'type': 2,
 *     'verify_by': 2,
 *     'email': 'user@example.invalid',
 *     'member_id': 1,
 * }
 */
class WithMemberIdService extends SendValidationCodeService
{
    /**
     * @throws ApiException
     */
    public function handle(array $input, ?Member $member): bool
    {
        // lad('$input', $input);
        if (!($member instanceof Member)) {
            throw new ApiException(__('error_message.1007'));
        }

        // 會員不存在
        // if ($member->id != $input['member_id']) {
        //     throw new ApiException(__('error_message.1007'));
        // }

        $agent = $member->agent;

        switch ($input['type']) {
            case ValidationCodeTypeEnum::BINDING_DATA->value:
                $this->checkType1Rule($input, $member);
                break;
            case ValidationCodeTypeEnum::PAYMENT_PASSWORD->value:
                $this->checkType5Rule($input, $member);
                break;
            case ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value:
                $this->checkType6Rule($input, $member);
                break;
            case ValidationCodeTypeEnum::REBINDING_DATA->value:
                $this->checkType9Rule($input, $member);
                break;
            case ValidationCodeTypeEnum::VERIFY_CODE->value:
                $this->checkType11Rule($input, $member);
                break;
        }

        // 運營商是否要驗證 username
        if ($agent->need_username && !in_array($input['type'], [1, 5, 6])) {
            // 帳號不符回傳錯誤訊息
            if ($member->username != $input['username']) {
                throw new ApiException(__('error_message.1005'));
            }
        }

        // 取得組織的 token expired 配置
        [$tokenExpired, $maxSend] = $this->getTokenExpiredAndMaxSend($agent, (int) $input['verify_by']);

        // 若存在未過期的驗證碼，則選擇另一個簡訊商重新發送
        $validateToken = [REDACTED], $input['type'], $input['verify_by']);

        // 找不到有效時間內的 token 或手機號碼不同就重新產生驗證碼
        // 有效時間(預設6分鐘)內沒發送過的話就重新發送
        if (!$validateToken || $validateToken->info != ($input['mobile'] ?? $input['email'] ?? '')) {
            // 建立驗證碼紀錄
            $validateToken = [REDACTED], $input, (int) $tokenExpired);
        }

        // 驗證碼已超過發送次數
        if ($validateToken->times >= $maxSend) {
            throw new ApiException(__('error_message.2037', ['values' => $maxSend]));
        }

        // 舊但可用的驗證碼
        if (!$validateToken->wasRecentlyCreated) {
            $validateToken->increment('times');
            $validateToken->expire_date = date('Y-m-d H:i:s', strtotime('+ ' . $tokenExpired . 'minute'));
            $validateToken->save();
        }

        $token = [REDACTED];
        $times = $validateToken->times;

        // 發送驗證碼
        $this->sendValidateCode($member, $input, $token, $times);

        return true;
    }

    /**
     * Type 1 額外驗證規則
     * 檢查手機號是否重複
     *
     * {
     *     'verify_by': 2,
     *     'email': 'user@example.invalid',
     *     'domain': 'example.invalid',
     *     'member_id': '48902',
     *     'type': 1
     * }
     *
     * @throws ApiException
     */
    private function checkType1Rule(array $input, Member $member): void
    {
        if ($input['verify_by'] == 1) {
            // 檢查手機號是否已達綁定限制
            $hasReachedMobileBindingLimit = $this->memberService->hasReachedMobileBindingLimit(
                $input['mobile'],
                $member->agent,
                $member->id,
            );

            if ($hasReachedMobileBindingLimit) {
                throw new ApiException(__('error_message.1090'));
            }
        }

        // 檢查信箱是否已達綁定限制(原僅檢查手機、email 未檢查;發碼前先擋,免寄了信才失敗)
        if ($input['verify_by'] == 2
            && $this->memberService->hasReachedEmailBindingLimit($input['email'], $member->agent)
        ) {
            throw new ApiException(__('error_message.1088'));
        }

        // 手機好不允許重複綁定
        if ($input['verify_by'] == 1 && $member->mobile_validate == 1) {
            throw new ApiException(__('error_message.9012'));
        }

        // 信箱不允許重複綁定
        if ($input['verify_by'] == 2 && $member->email_validate == 1) {
            throw new ApiException(__('error_message.9013'));
        }
    }

    /**
     * Type 5 額外驗證規則
     * 請求不用填寫手機號或信箱, 從已綁定的資料中取得
     * todo 需確認前端是否不用填寫手機號或信箱
     *
     * @throws ApiException
     */
    private function checkType5Rule(array &$input, Member $member): void
    {
        if ($input['verify_by'] == 1 && $member->mobile_validate == 1) {
            if ($member->mobile != '') {
                $input['mobile'] = $member->mobile;
            } else {
                throw new ApiException(__('error_message.9012'));
            }
        }

        if ($input['verify_by'] == 2 && $member->email_validate == 1) {
            if ($member->email != '') {
                $input['email'] = $member->email;
            } else {
                throw new ApiException(__('error_message.9013'));
            }
        }
    }

    /**
     * Type 6 額外驗證規則
     * 如果是找回支付密碼,要確認沒有跟密碼重複
     *
     * @throws ApiException
     */
    private function checkType6Rule(array $input, Member $member): void
    {
        // 確認輸入的手機號碼或信箱是否與目前綁定的手機號碼或信箱相同
        if ($input['verify_by'] == 1 && $input['mobile'] != $member->mobile) {
            $this->handleType6MatchFail($member);
            throw new ApiException(__('error_message.9015'));
        } elseif ($input['verify_by'] == 2 && $input['email'] != $member->email) {
            $this->handleType6MatchFail($member);
            throw new ApiException(__('error_message.9016'));
        }

        // 如果是找回支付密碼,要確認沒有跟密碼重複
        if (Hash::check($input['payment_password'], $member->password)) {
            throw new ApiException(__('error_message.1094'));
        }
    }

    /**
     * 手機或信箱不符合當前會員資料，累計失敗次數；達 3 次鎖定帳號
     *
     * @throws ApiException
     */
    private function handleType6MatchFail(Member $member): void
    {
        $count = $this->increaseMatchFailCount($member);
        if ($count >= 3) {
            $this->throwMemberLockedException($member);
        }
    }

    protected function increaseMatchFailCount(Member $member): int
    {
        $key = CacheKeys::moblieEmailMatchFailCount($member->id);
        $failCount = (int) Redis::incr($key);
        if ($failCount == 1) {
            Redis::expire($key, CacheTtl::ONE_DAY->value);
        }

        return $failCount;
    }

    /**
     * Type 9 額外驗證規則
     * 如果是修改綁定要檢查7天內是否有修改過
     *
     * {
     *     'verify_by': 1,
     *     'mobile': '60111223344',
     *     'domain': 'example.invalid',
     *     'member_id': '48902',
     *     'type': 9
     * }
     *
     * @throws ApiException
     */
    private function checkType9Rule(array $input, Member $member): void
    {
        // 如果是修改綁定要檢查7天內是否有修改過
        if ($this->hasRecentBindingRecord(
            member: $member,
            type: $input['verify_by'], // 1=綁定手機, 2=綁定信箱
            settingKey: AgentCustomizedSettingKeyEnum::MODIFY_BINDING_LIMIT_TIME,
            days: 7
        )) {
            throw new ApiException(__('error_message.2045'));
        }
    }

    /**
     * Type 11 額外驗證規則
     * 檢查手機號或信箱是否已綁定
     *
     * @throws ApiException
     */
    private function checkType11Rule(array $input, Member $member): void
    {
        if ($input['verify_by'] == 1 && $member->mobile_validate != 1) {
            throw new ApiException(__('error_message.9007'), HttpCode::HTTP_REDIRECT_SET_MOBILE->value);
        } elseif ($input['verify_by'] == 2 && $member->email_validate != 1) {
            throw new ApiException(__('error_message.9008'), HttpCode::HTTP_REDIRECT_SET_EMAIL->value);
        }
    }
}
