<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Agent;
use Illuminate\Support\Facades\Hash;

/**
 * 代理後台驗證「操作者自己」的支付密碼（target/tickets#4438），由 API_Agent `agent/verifyAgentPaymentPassword` 移植。
 *
 * 會員與代理的開洗分、錢包操作彈窗送出前呼叫。
 * 必須：只驗登入者本人，不收前端指定的 agent_id——舊接口由前端傳 agent_id，等於任何能登入後台的帳號
 * 都能拿它試別人的支付密碼；新後台四個呼叫點本來就只傳登入者自己，改後正常操作不受影響。
 */
class AgentPaymentPasswordService
{
    /** 支付密碼是否正確；操作者沒設支付密碼一律不通過 */
    public function verify(Agent $operator, string $password): bool
    {
        $hash = (string) $operator->payment_password;

        return $hash !== '' && Hash::check($password, $hash);
    }
}
