<?php

declare(strict_types=1);

namespace App\Services\ValidateToken\SendValidationCode;

use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\ValidateToken;
use App\Services\ValidateToken\SendValidationCodeService;

/**
 * 3969 v2 註冊發送驗證碼(type=12)。
 *
 * 為「非會員(尚未建帳)」在註冊階段發送手機/email 驗證碼:
 *  - verify_by=1 手機 → 走既有簡訊發送(sendValidateCode)
 *  - verify_by=2 email → 走既有寄信(sendValidateMail)
 *
 * 與 OnePageRegisterService(type=8) 的差異:修正其 email 分支(不再硬帶 mobile),
 * 手機與 email 各自正確處理;查舊 token 以 (假會員 member_id + type_id) 為準,不比對 info。
 * 沿用假會員機制(訪客尚無帳號,需指派系統假會員作為驗證碼接收對象)。
 */
class RegisterV2SendService extends SendValidationCodeService
{
    /**
     * @throws ApiException
     */
    public function handle(array $input): bool
    {
        $agentDomain = AgentDomain::where('domain', $input['domain'])->first();
        if (!$agentDomain) {
            throw new ApiException(__('error_message.1213'));
        }
        $agent = $agentDomain->agent;

        // 綁定上限檢查(依 verify_by 分手機/email)
        if ($input['verify_by'] == 1) {
            if (empty($input['mobile'])) {
                throw new ApiException(__('error_message.9991'));
            }
            if ($this->memberService->hasReachedMobileBindingLimit($input['mobile'], $agent)) {
                throw new ApiException(__('error_message.1090'));
            }
        } elseif ($input['verify_by'] == 2) {
            if (empty($input['email'])) {
                throw new ApiException(__('error_message.9991'));
            }
            if ($this->memberService->hasReachedEmailBindingLimit($input['email'], $agent)) {
                throw new ApiException(__('error_message.1088'));
            }
        }

        [$tokenExpired, $maxSend] = $this->getTokenExpiredAndMaxSend($agent, (int) $input['verify_by']);

        // 訪客尚無帳號,指派系統假會員作為接收對象(沿用 OnePageRegister 機制)
        $member = $this->getFakeMember('Registrants', $agent->id);

        // 查有效期內的舊 token:[REDACTED],故必須連同 info(手機號/信箱)一起比對,
        // 否則 A 訪客發的碼會被 B 訪客查到並沿用同一列(重發次數互相消耗、碼可跨人使用)
        $typeId = (int) ($input['type'] . $input['verify_by']);
        $info = $input['verify_by'] == 2 ? $input['email'] : $input['mobile'];
        $validateToken = [REDACTED]'member_id', $member->id)
            ->where('status', 0)
            ->where('type_id', $typeId)
            ->where('info', $info)
            ->where('expire_date', '>=', now())
            ->first();

        if (!$validateToken) {
            $validateToken = [REDACTED], $input, (int) $tokenExpired);
        }

        if ($validateToken->times >= $maxSend) {
            throw new ApiException(__('error_message.2037', ['values' => $maxSend]));
        }

        if (!$validateToken->wasRecentlyCreated) {
            $validateToken->increment('times');
            $validateToken->expire_date = date('Y-m-d H:i:s', strtotime('+ ' . $tokenExpired . 'minute'));
            $validateToken->save();
        }

        // 依 verify_by 分流發送:手機走簡訊、email 走寄信
        if ($input['verify_by'] == 2) {
            // 需帶 $member:sendMail 以其 agent 決定該站的 email gateway 與寄件人,
            // 未帶則 agentGroupId 為 0 查不到閘道,寄送紀錄的 agent_id / member_id 亦記 0
            $this->sendValidateMail($input['email'], $validateToken->token, $member, (int) $input['type']);
        } else {
            // 簡訊樣板複用一頁註冊(type=8)的既有樣板:註冊驗證碼簡訊內容通用,
            // 免為 type=12 逐白標補 sms_texts 樣板資料(sendValidateCode 的 type 僅用於查樣板)
            $this->sendValidateCode(
                $member,
                array_merge($input, ['type' => ValidationCodeTypeEnum::REGISTER->value]),
                $validateToken->token,
                $validateToken->times
            );
        }

        return true;
    }
}
