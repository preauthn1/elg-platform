<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\SmsSendRecord;
use App\Models\ValidateToken;
use Illuminate\Support\Facades\Hash;

class ResetPasswordService extends CheckValidationCodeService
{
    /**
     * 訪客重設密碼
     *
     * @throws ApiException
     */
    public function resetPassword(array $input): void
    {
        // 驗證 token 是否有效
        $validateToken = [REDACTED]'token']);

        // 2=忘記密碼, 3=找回密碼 貌似沒人知道差在哪裡
        // 工單 4540：其他類型原本直接 return，controller 照樣回成功、密碼其實沒改，改為拒絕
        if (!in_array($validateToken->type, [2, 3])) {
            throw new ApiException(__('error_message.9992'));
        }

        // 查詢選擇的會員
        $member = Member::where('username', $input['username'])->first();

        if (!$member) {
            throw new ApiException('member not match token');
        }

        // 查詢驗證碼的會員
        $tokenMember = $this->getMember($validateToken);

        // 手機與信箱分開判定，且只有非空值才算得上一次比對成功。
        // 舊寫法 `mobile != && email !=` 在兩邊 email 同為 null 時整條 AND 不成立而放行，
        // 等於 email 為空的帳號可被他人的驗證碼改密碼（純手機註冊者為常態）。
        // 用嚴格比較避免 "0912345678" == "912345678" 這類數字字串被判為相等。
        $mobileMatched = Common::isNotEmptyString($member->mobile) && $member->mobile === $tokenMember->mobile;
        $emailMatched = Common::isNotEmptyString($member->email) && $member->email === $tokenMember->email;

        if (!$mobileMatched && !$emailMatched) {
            throw new ApiException('member not match token');
        }

        // 檢查驗證碼的會員與選擇的會員是否在同一個綁定範圍
        $tokenAgent = $tokenMember->agent;
        $agent = $member->agent;
        if ($tokenAgent->organization_id != $agent->organization_id || $tokenAgent->binding_scope_id != $agent->binding_scope_id) {
            throw new ApiException('binding scope not match');
        }

        $updateData = [
            'password' => Hash::make($input['new_password']),
            // 風控比對用(會員追蹤查同密碼帳號),不參與登入驗證
            'password_md5' => md5($input['new_password']),
            'status' => 1,
            'admin' => 0,
        ];

        $member->update($updateData);

        // 忘記密碼次數（存於 member_statistics）
        $statistics = $member->statistics()->firstOrCreate([]);
        $statistics->update(['forget_pwd_count' => $statistics->forget_pwd_count + 1]);

        // if ($validateToken->member_id != $member->id) {
        //     SmsSendRecord::where('member_id', $validateToken->member_id)
        //         ->where('message_content', 'like', '%' . $input['token'] . '%')
        //         ->where('created_at', '>=', $validateToken->created_at)
        //         ->update(['member_id' => $member->id]);
        // }

        // 建立綁定紀錄
        $this->logResetPassword($validateToken);

        // 更新驗證碼為無效
        $this->updateTokenStatus($validateToken);
    }

    /**
     * 記錄修改密碼
     *
     * 工單 4540：不存新密碼原文，SystemNotifyService 會把 value 當通知內容送出
     */
    private function logResetPassword(ValidateToken $validateToken): void
    {
        MemberBindingLog::create([
            'type' => 5, // 知道所有 type 的人寫成 ENUM 幫幫大家理解吧
            'member_id' => $validateToken->member_id,
            'value' => null,
            'ip' => Common::getIp(),
            'validate_type' => $validateToken->verify_by,
            'status' => self::STATUS_USED,
        ]);
    }
}
