<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResetEmailService extends ResetMobileService
{
    /**
     * 訪客重設密碼
     *
     * @throws ApiException
     * @throws Throwable
     */
    public function resetEmail(Member $member, array $input): void
    {
        $oldValue = $member->email;
        $newValue = $input['new_email'];

        // 檢查Email是否已超過允許綁定的次射
        $hasReachedEmailBindingLimit = $this->memberService->hasReachedEmailBindingLimit($newValue, $member->agent);

        if ($hasReachedEmailBindingLimit) {
            throw new ApiException(__('error_message.1088'));
        }

        $validateToken = [REDACTED], $input['token'], $oldValue, $newValue);

        // 驗證成功, 更新手機號
        $updated = DB::transaction(function () use ($member, $newValue, $validateToken) {
            $memberId = $member->id;
            $oldValue = $member->email;
            $updated = $member->update([
                'email' => $newValue,
            ]);

            // 建立綁定紀錄
            $this->logResetMobile(2, $memberId, $oldValue, $newValue);
            // 更新驗證碼為無效
            $this->updateTokenStatus($validateToken);

            return $updated;
        });

        if ($updated) {
            $this->notifyMember($member, $oldValue, $newValue, true);
        }
    }

    /**
     * 通知會員綁定結果
     *
     * @throws Exception
     */
    private function notifyMember(Member $member, string $oldValue, string $newValue, bool $isSuccess = false): void
    {
        $typeText = 'Email';

        if ($isSuccess === true) {
            $maskBeforeValue = Common::maskData($oldValue, 'email');
            $maskValue = Common::maskData($newValue, 'email');

            $text = ' 您的帳號已';
            $text .= '進行修改' . $typeText . ' 將 ' . $maskBeforeValue . ' 更改為 ' . $maskValue;

            $emails = [$oldValue, $newValue];
        } else {
            $text = ' 您的帳號已';
            $text .= '進行修改' . $typeText . ' 失敗，若非本人，請盡速修改密碼！';

            $emails = [$member->email];
        }

        $mobiles = ($member->mobile_validate == 1) ? [$member->mobile] : [];

        // 通知發送失敗不中斷主流程
        try {
            $this->notifyMemberBySms($member, $mobiles, $text);
            $this->notifyMemberByEmail($emails, $text, $member);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
