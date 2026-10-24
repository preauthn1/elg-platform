<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Enums\MemberStatusLogTypeEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Helpers\Traits\GetContextTrait;
use App\Models\AgentDomain;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\ValidateToken;
use App\SP\SP_event_gift_point;
use Illuminate\Support\Facades\App;

class CheckValidationCodeService extends AbstractValidationCodeService
{
    use GetContextTrait;

    protected const int STATUS_UNUSED = 0;

    protected const int STATUS_USED = 1;

    private const int VALIDATION_CODE_FAIL_LOCK_LIMIT = 3;

    /**
     * 處理驗證碼邏輯
     *
     * @throws ApiException
     */
    public function checkCodeProcess(array $input): Member
    {
        $member = null;
        try {
            // 驗證 token 是否有效
            $validateToken = [REDACTED]'token'], $input['mobile'] ?? '', $input['email'] ?? '');

            // 工單 4540：忘記密碼（type 2）的碼不在這支驗，查到就立刻與碼錯誤回同一個例外。
            // 不能等到後面的網域、會員檢查：那些拒絕只有碼正確時才會走到，訊息又各不相同，
            // 換個網域送一次就知道碼猜中了。
            if ($validateToken->type === ValidationCodeTypeEnum::FORGET_PASSWORD->value) {
                throw $this->invalidValidationCodeException();
            }

            try {
                // 查詢驗證碼的會員
                $member = $this->getMember($validateToken);

                // 查詢登入的會員是否與驗證碼的會員相同
                // 已登入再檢查
                $this->checkLoginMember($member);

                // 驗證域名
                $this->checkDomain($input['domain'], $member);

                // 依據驗證碼類型處理邏輯
                $this->processTokenType($validateToken, $member, $input);
            } catch (ApiException $e) {
                // 工單 4540：找回密碼（type 3）的碼，後續任何拒絕都與碼錯誤回同一個例外，理由同上；
                // 其他類型（綁定、註冊等）保留原本的錯誤訊息。
                throw $validateToken->type === ValidationCodeTypeEnum::RECOVER_PASSWORD->value
                    ? $this->invalidValidationCodeException()
                    : $e;
            }

            $this->resetValidationCodeFailCount($member);
        } catch (ApiException $e) {
            // 驗證碼錯誤的後續流程
            $this->failProcessTokenType($input, $member);
            throw $e;
        }

        return $member;
    }

    /**
     * 找回密碼（type 3）：驗證碼的會員必須是先前登入失敗的那個會員。
     *
     * 工單 4540：不匹配時與驗證碼錯誤回同一個例外。這時驗證碼本身已經查到、是對的，
     * 回別的訊息就等於告訴對方碼猜中了。
     *
     * @throws ApiException
     */
    protected function checkRecoverPassword(ValidateToken $validateToken, Member $member, array $input): void
    {
        $guestValidateToken = [REDACTED]'guest_validate_token'] ?? '';

        try {
            $guestMember = $this->getMemberByGuestValidateToken($guestValidateToken);
        } catch (ApiException) {
            throw $this->invalidValidationCodeException();
        }

        if ($member->id != $guestMember->id) {
            throw $this->invalidValidationCodeException();
        }

        // 這裡不要更新驗證碼狀態，重設密碼會用同個驗證碼，到時候再更新狀態
        // $this->updateTokenStatus($validateToken);
    }

    protected function checkLoginMember(Member $member): void
    {
        $userData = $this->getUserContext();
        if (!$userData) {
            return;
        }
        $loginMember = $userData->member;
        if ($loginMember && $loginMember->id != $member->id) {
            throw new ApiException('member not match login');
        }
    }

    protected function checkDomain(string $domain, Member $member): void
    {
        $agentDomain = AgentDomain::where('domain', $domain)->where('status', 1)->first();
        if (!$agentDomain) {
            throw new ApiException('domain not found');
        }
        $agent = $member->agent;
        if (!in_array($agentDomain->agent_id, $agent->hierarchy_array)) {
            throw new ApiException('domain not match agent');
        }
        $domainAgent = $agentDomain->agent;
        if ($domainAgent->organization_id != $agent->organization_id || $domainAgent->binding_scope_id != $agent->binding_scope_id) {
            throw new ApiException('binding scope not match');
        }
    }

    /**
     * 根據驗證碼類型處理邏輯
     *
     * @throws ApiException
     */
    private function processTokenType(ValidateToken $validateToken, Member $member, array $input): void
    {
        // lad($validateToken->type);
        match ($validateToken->type) {
            1 => $this->bindMobileOrEmail($validateToken, $member),
            5, 6 => $this->bindPaymentPassword($validateToken, $member),
            // 7,11 單純驗證;12(v2 註冊)同理:會員尚未建立,驗證碼僅確認正確並作廢,實際綁定於建會員時處理
            7, 11, 12 => $this->updateTokenStatus($validateToken),
            3 => $this->checkRecoverPassword($validateToken, $member, $input),
            // 工單 4540：type 2（忘記密碼）的碼本來就不該在這支驗，要走取得帳號列表→重設密碼。
            // 回應必須與驗證碼錯誤完全相同（連例外的建立位置都一樣），否則這支會變成猜碼的判定器。
            2 => throw $this->invalidValidationCodeException(),
            default => throw new ApiException(__('error_message.9992')),
        };
    }

    /**
     * 根據驗證碼類型處理驗證碼錯誤的後續流程
     *
     * @throws ApiException
     */
    private function failProcessTokenType(array $input, ?Member $member): void
    {
        $type = (int) ($input['type'] ?? 0);
        if (!in_array($type, [
            ValidationCodeTypeEnum::RECOVER_PASSWORD->value,
            ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value,
        ], true)) {
            return;
        }

        $member = $this->resolveMemberForValidationCodeFail($input, $member);
        if ($member === null) {
            return;
        }

        $this->increaseValidationCodeFailCount($member);
    }

    /**
     * 累計驗證碼錯誤次數，達上限時鎖帳並回應登出。
     *
     * 計次、鎖帳與作廢 token 的順序和交易邊界見 MemberFailCountService::increaseAndLock()：
     * 作廢 token 失敗時帳號仍維持鎖定。
     *
     * @throws ApiException
     */
    private function increaseValidationCodeFailCount(Member $member): void
    {
        $failCount = $this->memberFailCountService->increaseAndLock(
            $member,
            MemberStatusLogTypeEnum::VERIFICATION_CODE_LIMIT,
            static fn (int $failCount): bool => $failCount >= self::VALIDATION_CODE_FAIL_LOCK_LIMIT,
        );

        if ($failCount >= self::VALIDATION_CODE_FAIL_LOCK_LIMIT) {
            throw $this->memberLockedException();
        }
    }

    /**
     * 若是未登入用戶需要從 guest_validate_token 來取得 member
     * 其他則從驗證碼關聯的 member 來取得
     */
    private function resolveMemberForValidationCodeFail(array $input, ?Member $member): ?Member
    {
        $guestValidateToken = [REDACTED]'guest_validate_token'] ?? '';
        if ($guestValidateToken !== '') {
            try {
                return $this->getMemberByGuestValidateToken($guestValidateToken);
            } catch (ApiException) {
                return null;
            }
        }

        return $member;
    }

    /**
     * @throws ApiException
     */
    protected function getActiveToken($token, $mobile = null, $email = null): ValidateToken
    {
        $validateToken = [REDACTED]'token', $token)
            ->where('status', self::STATUS_UNUSED)
            ->where('expire_date', '>=', date('Y-m-d H:i:s'))
            ->when($mobile || $email, function ($query) use ($mobile, $email) {
                $query->where(function ($q) use ($mobile, $email) {
                    $q->where('info', $mobile)
                        ->orWhere('info', $email);
                });
            })
            ->first();

        if (!$validateToken) {
            throw $this->invalidValidationCodeException();
        }

        return $validateToken;
    }

    /**
     * 驗證碼不能用（錯誤、或對了但不該在這裡用）的例外，一律從這裡建立。
     *
     * 工單 4540：ApiException 的回應帶 file（建立例外的檔案與行號），各處各自 new 的話，
     * 就算訊息相同，行號不同也能分辨「碼錯了」與「碼對了但被拒絕」。
     */
    protected function invalidValidationCodeException(): ApiException
    {
        return new ApiException(__('error_message.9992'));
    }

    /**
     * 獲取會員
     *
     * @throws ApiException
     */
    protected function getMember(ValidateToken $validateToken): Member
    {
        $member = Member::find($validateToken->member_id);

        if (!$member) {
            throw new ApiException(__('error_message.9992'));
        }

        return $member;
    }

    /**
     * type 1: 綁定手機/電子信箱
     *
     * @throws ApiException
     */
    private function bindMobileOrEmail(ValidateToken $validateToken, Member $member): void
    {
        switch ($validateToken->verify_by) {
            case 1:
                $mobile = $validateToken->info;

                $hasReachedMobileBindingLimit = $this->memberService->hasReachedMobileBindingLimit(
                    $mobile,
                    $member->agent,
                );

                if ($hasReachedMobileBindingLimit) {
                    throw new ApiException(__('error_message.1090'));
                }

                $memberData = [
                    'mobile_validate' => 1,
                    'mobile' => $mobile,
                ];
                break;
            case 2:
                $email = $validateToken->info;

                // 檢查Email是否已超過允許綁定的次射
                $hasReachedEmailBindingLimit = $this->memberService->hasReachedEmailBindingLimit(
                    $email,
                    $member->agent
                );

                if ($hasReachedEmailBindingLimit) {
                    throw new ApiException(__('error_message.1088'));
                }

                $memberData = [
                    'email_validate' => 1,
                    'email' => $email,
                ];
                break;
            default:
                throw new ApiException(__('error_message.9992'));
        }

        // 更新會員資料
        $member->update($memberData);

        // 建立綁定紀錄
        $this->logBinding($validateToken, $member);

        // 更新驗證碼為無效
        $this->updateTokenStatus($validateToken);

        // 發放綁手機獎勵
        if ($validateToken->verify_by == 1) {
            $this->bindMobileReward($member);
        }
    }

    /**
     * 發放綁手機獎勵
     */
    private function bindMobileReward(Member $member): void
    {
        $sp = App::make(SP_event_gift_point::class);
        $sp->handle(2, $member);
    }

    /**
     * 記錄綁定日誌
     *
     * @throws ApiException
     */
    private function logBinding(ValidateToken $validateToken, Member $member): void
    {
        MemberBindingLog::create([
            'type' => $this->getBindingLogType($validateToken->verify_by),
            'member_id' => $member->id,
            'value' => $validateToken->info,
            'ip' => Common::getIp(),
            'validate_type' => $validateToken->verify_by,
            'status' => self::STATUS_USED,
        ]);
    }

    /**
     * 獲取綁定日誌類型
     *
     * @throws ApiException
     */
    private function getBindingLogType(int $verifyBy): int
    {
        return match ($verifyBy) {
            1 => 3, // 綁定手機
            2 => 4, // 綁定電子郵件
            default => throw new ApiException(__('error_message.9992')),
        };
    }

    /**
     * 更新驗證碼狀態
     */
    protected function updateTokenStatus(ValidateToken $validateToken): void
    {
        $validateToken->update(['status' => self::STATUS_USED]);
    }

    private function resetValidationCodeFailCount(Member $member): void
    {
        $statistics = $member->statistics()->firstOrCreate([]);
        if ($statistics->validation_code_fail_count === 0) {
            return;
        }

        $statistics->validation_code_fail_count = 0;
        $statistics->save();
    }

    /**
     * type 5, 6: 支付密碼
     */
    private function bindPaymentPassword(ValidateToken $validateToken, Member $member): void
    {
        // 更新會員資料
        $member->update(['payment_password' => $validateToken->info_2]);

        if ($validateToken->type === ValidationCodeTypeEnum::FORGET_PAYMENT_PASSWORD->value) {
            // 支付密碼錯誤次數（存於 member_statistics）
            $member->statistics()->firstOrCreate([])->update(['payment_pwd_fail_count' => 0]);
        }

        // 更新驗證碼為無效
        $this->updateTokenStatus($validateToken);
    }
}
