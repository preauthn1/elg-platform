<?php

declare(strict_types=1);

namespace App\Services\ValidateToken\SendValidationCode;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Services\ValidateToken\SendValidationCodeService;

/**
 * 登入前, 一頁式註冊
 * 適用 type: 8
 *
 * 在訪客狀態要綁定手機號碼, 必須指派假會員帳號作為接收對象
 * todo 邏輯有缺陷, 所有訪客共用同一組(商戶)假會員, 註冊碼會一直被洗掉造成驗證失敗
 *
 * {
 *   'domain': 'example.invalid',
 *   'type': 2,
 *   'verify_by': 2,
 *   'email': 'user@example.invalid'
 * }
 */
class OnePageRegisterService extends SendValidationCodeService
{
    /**
     * @throws ApiException
     */
    public function handle(array $input): bool
    {
        // lad('$input', $input);

        $agentDomain = AgentDomain::where('domain', $input['domain'])->first();
        $agent = $agentDomain->agent;

        if ($input['verify_by'] == 1) {
            $hasReachedMobileBindingLimit = $this->memberService->hasReachedMobileBindingLimit(
                $input['mobile'],
                $agent,
            );

            if ($hasReachedMobileBindingLimit) {
                throw new ApiException(__('error_message.1090'));
            }
        }

        if ($input['verify_by'] == 2) {
            $hasReachedEmailBindingLimit = $this->memberService->hasReachedEmailBindingLimit(
                $input['email'],
                $agent,
            );

            if ($hasReachedEmailBindingLimit) {
                throw new ApiException(__('error_message.1088'));
            }
        }

        // 一頁註冊 (在訪客狀態要綁定手機號碼, 必須指派假會員帳號作為接收對象)
        // todo 邏輯有缺陷, 所有訪客共用同一組(商戶)假會員, 註冊碼會一直被洗掉造成驗證失敗

        // 取得組織的 token expired 配置
        [$tokenExpired, $maxSend] = $this->getTokenExpiredAndMaxSend($agent, (int) $input['verify_by']);

        if ($agent->id == 33893) {
            // 如果是 vworldmy 的話 直接改成 acompany 先寫死
            $agent->id = 66829;
        }

        // 因為還未創建會員, 用系統的傳簡訊會員當作 member_id,不然後台會查不到
        $member = $this->getFakeMember('Registrants', $agent->id);

        // 未過㝛的驗證碼不重建，選擇另一個簡訊傷重新發送
        $validateToken = [REDACTED], $input['mobile'], $input['type'], $input['verify_by']);

        if (!$validateToken) {
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
}
