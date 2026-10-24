<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\Member;
use App\Models\MemberBindingLog;
use App\Models\SmsGatewayGroupDetail;
use App\Models\ValidateToken;
use Exception;
use Illuminate\Support\Facades\Hash;

class SendValidationCodeService extends AbstractValidationCodeService
{
    /**
     * 檢查某會員是否在指定的天數內，是否已提交過針對特定類型的綁定操作（如手機或電子郵件），並根據代理商的自定義設定來決定是否允許該操作。
     */
    protected function hasRecentBindingRecord(Member $member, int $type, int $settingKey, $days = 7): bool
    {
        $memberId = $member->id;

        $setting = $this->agentService->getSpecificAgentSetting($member->agent, $settingKey);

        $check = $setting ?? 0;

        if ($check != 1) {
            return false;
        }

        // 判斷 N 天內是否有綁定紀錄
        $date = now()->subDays($days);

        return MemberBindingLog::where('member_id', $memberId)
            ->where('created_at', '>=', $date)
            ->where('type', $type) // 驗證方式：1=手機, 2=電子信箱
            ->where('status', 1)
            ->exists();
    }

    /**
     * 查詢手機號碼的驗證碼
     * 一頁式註冊才使用
     */
    protected function getValidateTokenByMobile(int $memberId, string $mobile, int $type = 8, int $verifyBy = 1): ?ValidateToken
    {
        $typeId = $type . $verifyBy;

        return ValidateToken:[REDACTED]'member_id', $memberId)
            ->where('info', $mobile)
            ->where('status', 0)
            ->where('type_id', $typeId)
            ->where('expire_date', '>=', now())
            ->first();
    }

    /**
     * 查詢用戶是否還有效期內的驗證碼
     */
    protected function getValidateTokenByMemberId(int $memberId, int $type = 1, int $verifyBy = 1): ?ValidateToken
    {
        $typeId = $type . $verifyBy;

        return ValidateToken:[REDACTED]'member_id', $memberId)
            ->where('status', 0)
            ->where('type_id', $typeId)
            ->where('expire_date', '>=', date('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * 取得驗證碼有效時間與有效時間內最大發送次數
     *
     * tokenExpired 驗證碼有效時間, 預設6分鐘
     * maxSend 有效時間最大發送次數, 預設3次
     *
     * 工單4286：依 $verifyBy 分流——email(2) 讀代理實際綁定的電子郵件設置組別（agents.email_gateway_group_id），
     * 查不到吃 .env MAIL_*，不回退簡訊組別；簡訊(1) 維持既有查法不動（既有的 first() 問題另案）。
     * todo 緩存
     */
    protected function getTokenExpiredAndMaxSend(Agent $agent, int $verifyBy = 1): array
    {
        if ($verifyBy === 2) {
            return $this->emailTextService->getTokenExpiredAndMaxSend((int) ($agent->email_gateway_group_id ?? 0));
        }

        $default = [
            config('custom.sms.token_expired'),
            config('custom.sms.max_send'),
        ];

        // 改用 agent.sms_gateway_group_id 查詢
        $agentGroup = AgentGroup::query()
            ->where('organization_id', $agent->organization_id)
            ->where('agent_group_type', 3) // SMS 短信設置組別
            ->first();

        if (!$agentGroup) {
            return $default;
        }

        $smsGatewayGroupDetail = SmsGatewayGroupDetail::query()
            ->where('agent_group_id', $agentGroup->id)
            ->first();

        if (!$smsGatewayGroupDetail) {
            return $default;
        }

        // json to arrayObject
        $setting = $smsGatewayGroupDetail->setting;

        return [
            $setting->token_expired ?? config('custom.sms.token_expired'),
            $setting->max_send ?? config('custom.sms.max_send'),
        ];
    }

    /**
     * 創建驗證碼
     *
     * 根據驗證方式生成驗證碼，並確保驗證碼在有效期內不重複。
     */
    private function getToken(): string
    {
        // 統一6位數字；驗證碼屬安全用途，須用 CSPRNG（rand() 可由前後值反推序列）
        $token = [REDACTED] random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 驗證碼不能重複
        $isExists = ValidateToken:[REDACTED]'token', $token)
            ->where('expire_date', '>=', now())
            ->exists();

        if ($isExists) {
            return $this->getToken();
        }

        return $token;
    }

    /**
     * 建立驗證碼紀錄
     */
    protected function createValidateToken(Member $member, array $input, int $tokenValidityPeriod)
    {
        // 一頁註冊(8)與 v2 註冊(12)不作廢其他可用的驗證碼:
        // 註冊需手機+email 兩碼同時存活,且共用假會員,作廢會互相打掉對方的碼
        $keepOtherTokenTypes = [
            ValidationCodeTypeEnum::REGISTER->value,
            ValidationCodeTypeEnum::REGISTER_V2->value,
        ];
        if (!in_array((int) $input['type'], $keepOtherTokenTypes, true)) {
            // 作廢用戶其他可用的驗證碼
            ValidateToken:[REDACTED]
                ->where('member_id', $member->id)
                ->where('status', 0)
                ->update(
                    [
                        'status' => 2,
                    ]
                );
        }

        $token = [REDACTED];

        $typeId = $input['type'] . $input['verify_by'];

        if ($input['verify_by'] == 1 && !isset($input['mobile'])) {
            throw new ApiException('Mobile number is required for SMS verification.');
        }

        if ($input['verify_by'] == 2 && !isset($input['email'])) {
            throw new ApiException('Email is required for email verification.');
        }

        return ValidateToken:[REDACTED]
            'member_id' => $member->id,
            'type_id' => $typeId,
            'info' => ($input['verify_by'] == 1) ? $input['mobile'] ?? '' : $input['email'] ?? '',
            'info_2' => (in_array($input['type'], [5, 6])) ? Hash::make($input['payment_password']) : '',
            'token' => $token,
            'expire_date' => now()->addMinutes($tokenValidityPeriod),
            'status' => 0,
            'times' => 1,
            'admin' => $member->id,
        ]);
    }

    /**
     * 發送驗證碼
     *
     * 根據驗證方式 (手機或電子郵件) 發送驗證碼，並處理發送邏輯。
     *
     * @param  Member  $member  會員資訊
     * @param  array  $input  輸入資料
     * @param  string  $token  驗證碼
     * @param  int  $times  發送次數
     *
     * @throws Exception
     */
    protected function sendValidateCode(Member $member, array $input, string $token, int $times): void
    {
        if ($input['verify_by'] == 1) {
            // 手機 SMS 驗證
            $this->sendValidateSms($member, $input, $token, $times);
        }

        if ($input['verify_by'] == 2) {
            // Email 驗證
            $this->sendValidateMail($input['email'], $token, $member, (int) $input['type']);
        }
    }

    /**
     * 一頁式註冊時由於訪客身份, 需藉由固定的代理假帳號接收驗證碼, 不然後台會查不到?
     * 假如同時有多比註冊彼此驗證碼會被後者複寫, 此處設計模式有問題,
     * todo 緩存
     */
    protected function getFakeMember(string $usernamePrefix, int $agentId = 1)
    {
        $member = Member::where('username', 'like', $usernamePrefix . '_%')
            ->where('parent', $agentId)
            ->first();

        if (!$member) {
            $member = $this->createFakeMember($usernamePrefix, $agentId);
        }

        return $member;
    }

    protected function createFakeMember(string $usernamePrefix, int $agentId)
    {
        return Member::create([
            'parent' => $agentId,
            'username' => $usernamePrefix . '_' . $agentId,
            'username_bak' => $usernamePrefix . '_' . $agentId,
            'nickname' => $usernamePrefix,
            'password' => Hash::make('DD203BC58AD6DAA5B0A2B4CD619C38C6'),
            'status' => 0, // 系統假帳號，禁止登入
            'admin' => 0,
        ]);
    }
}
