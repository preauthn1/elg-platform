<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\ValidateToken;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResetMobileService extends CheckValidationCodeService
{
    /**
     * 會員重新綁定手機號, 若驗證失敗需警示會員有人試圖修改密碼
     *
     * @throws ApiException
     * @throws Throwable
     */
    public function resetMobile(Member $member, array $input): void
    {
        $oldValue = $member->mobile;
        $newValue = $input['new_mobile'];

        // 檢查手機號是否已超過允許綁定的次射
        $hasReachedMobileBindingLimit = $this->memberService->hasReachedMobileBindingLimit($newValue, $member->agent);

        if ($hasReachedMobileBindingLimit) {
            throw new ApiException(__('error_message.1090'));
        }

        $validateToken = [REDACTED], $input['token'], $oldValue, $newValue);

        $updated = DB::transaction(function () use ($member, $newValue, $validateToken) {
            $memberId = $member->id;
            $oldValue = $member->mobile;
            // 驗證成功, 更新手機號
            $updated = $member->update([
                'mobile' => $newValue,
            ]);

            // 建立綁定紀錄
            $this->logResetMobile(1, $memberId, $oldValue, $newValue);

            // 更新驗證碼為無效
            $this->updateTokenStatus($validateToken);

            return $updated;
        });

        if ($updated) {
            $this->notifyMember($member, $oldValue, $newValue, true);
        }
    }

    /**
     * 驗證規則
     *
     * @throws ApiException
     */
    protected function getValidToken(Member $member, string $token, string $oldValue, string $newValue): ValidateToken
    {
        // 驗證 token 是否有效
        try {
            $validateToken = [REDACTED];
        } catch (ApiException $e) {
            // 驗證碼過期或不存在
            $this->notifyMember($member, $oldValue, $newValue);
            throw new ApiException(__('error_message.9992'));
        }

        // 會員不同可能是使用別人的驗證碼，要回傳錯誤
        if ($validateToken->member_id != $member->id) {
            $this->notifyMember($member, $oldValue, $newValue);
            throw new ApiException(__('error_message.9992'));
        }

        // 驗證碼類別錯誤, 本接口只適用重新綁定
        if ($validateToken->type != 9) {
            $this->notifyMember($member, $oldValue, $newValue);
            throw new ApiException(__('error_message.9992'));
        }

        return $validateToken;
    }

    /**
     * 建立綁定紀錄
     */
    protected function logResetMobile(int $type, int $memberId, string $oldValue, string $newValue): void
    {
        MemberBindingLog::create([
            'type' => $type, // 類別: 1=修改手機, 2=修改email, 3=綁定手機, 4=綁定email, 5=修改密碼
            'member_id' => $memberId,
            'before_value' => $oldValue,
            'value' => $newValue,
            'ip' => Common::getIp(),
            'validate_type' => 1,
            'status' => self::STATUS_USED,
        ]);
    }

    /**
     * 通知會員綁定結果
     */
    private function notifyMember(Member $member, string $oldValue, string $newValue, bool $isSuccess = false): void
    {
        $typeText = '手機號';

        if ($isSuccess === true) {
            $maskBeforeValue = Common::maskData($oldValue, 'mobile');
            $maskValue = Common::maskData($newValue, 'mobile');

            $text = ' 您的帳號已';
            $text .= '進行修改' . $typeText . ' 將 ' . $maskBeforeValue . ' 更改為 ' . $maskValue;

            // 成功的話要給之前的手機跟修改後的手機發通知
            $mobiles = [$oldValue, $newValue];
        } else {
            $text = ' 您的帳號已';
            $text .= '進行修改' . $typeText . ' 失敗，若非本人，請盡速修改密碼！';

            $mobiles = [$member->mobile];
        }

        $emails = ($member->email_validate == 1) ? [$member->email] : [];

        // 通知發送失敗不中斷主流程
        try {
            $this->notifyMemberBySms($member, $mobiles, $text);
            $this->notifyMemberByEmail($emails, $text, $member);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
