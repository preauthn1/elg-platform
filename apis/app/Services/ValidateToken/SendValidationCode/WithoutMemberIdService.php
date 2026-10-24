<?php

declare(strict_types=1);

namespace App\Services\ValidateToken\SendValidationCode;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\MemberStatusEnum;
use App\Enums\ValidationCodeTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Services\ValidateToken\SendValidationCodeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * 登入前, 缺少 member_id, 需透過手機號或信箱搜尋會員
 * 適用 type: 2, 3, 4
 *
 * 由於相同的手機號或信箱可能存在多筆不同站台的會員資料
 * 必須透過域名比對找出匹配的會員帳號
 *
 * {
 *   'domain': 'example.invalid',
 *   'type': 2,
 *   'verify_by': 2,
 *   'email': 'user@example.invalid'
 * }
 */
class WithoutMemberIdService extends SendValidationCodeService
{
    /**
     * @throws ApiException
     */
    public function handle(array $input, Agent $agent): bool
    {
        // lad('$input', $input);

        if ($input['verify_by'] == 1 && !$input['mobile']) {
            throw new ApiException(__('error_message.9991'));
        }

        if ($input['verify_by'] == 2 && !$input['email']) {
            throw new ApiException(__('error_message.9991'));
        }

        try {
            // 透過手機號或信箱搜尋用戶, 包含已經鎖定的會員
            $members = $this->filterMembers($agent, $input);

            /**
             * Rule
             * type: 2,3,4,8 適用
             * 如果透過手機號或信箱搜尋用戶
             * 可能會找到不同站台使用相同手機號或信箱的用戶
             * 需比對 domain 來判斷是哪個站台的
             *
             * {
             *     'verify_by': 2,
             *     'email': 'user@example.invalid',
             *     'domain': 'example.invalid',
             *     'type': 2
             * }
             */
            $members = $members->filter(function (Member $member) use ($input) {
                return $this->agentService->checkAgentDomain($member->agent, $input['domain']);
            });

            // 找不到符合的會員
            if ($members->isEmpty()) {
                throw new ApiException(__('error_message.1007'));
            }

            if ($input['type'] == ValidationCodeTypeEnum::RECOVER_PASSWORD->value) {
                $this->checkRecoverPassword($members, $input);
            }
        } catch (ApiException $e) {
            $this->handleRecoverPasswordFail($input);
            throw $e;
        }

        // 找到的會員全都被鎖定：只作廢 token 並回應鎖帳，不重新鎖帳（狀態讀自從庫，可能是客服剛解鎖前的舊值）
        if ($members->every(function (Member $member) {
            return $member->status == MemberStatusEnum::LOCKED->value;
        })) {
            $this->throwMemberAlreadyLockedException($members->first());
        }

        // 取得第一個正常狀態的會員
        $member = $members->filter(function (Member $member) {
            return $member->status == MemberStatusEnum::VERIFIED->value;
        })->first();

        $agent = $member->agent;

        if ($agent->need_username && in_array($input['type'], [2, 3, 4, 7, 8, 9])) {
            // 帳號不符回傳錯誤訊息
            if ($member->username != $input['username']) {
                throw new ApiException(__('error_message.1005'));
            }
        }

        // 取得組織的 token expired 配置 (全流程適用)
        [$tokenExpired, $maxSend] = $this->getTokenExpiredAndMaxSend($agent, (int) $input['verify_by']);

        // 若存在未過期的驗證碼，則選擇另一個簡訊商重新發送
        $validateToken = [REDACTED], $input['type'], $input['verify_by']);

        // 找不到有效時間內的 token 或收件目標(手機／信箱)不同就重新產生驗證碼
        // 有效時間(預設6分鐘)內沒發送過的話就重新發送
        // 工單4286：原本只比對 mobile，email 驗證每次都被判定為不同而重建 token，發送上限形同無效；改成比照 WithMemberIdService
        if (!$validateToken || $validateToken->info != ($input['mobile'] ?? $input['email'] ?? '')) {
            // 建立驗證碼紀錄
            $validateToken = [REDACTED], $input, (int) $tokenExpired);
            // $validateToken = [REDACTED];
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

        // 發送驗證碼（$input 中含 sms_gateway_info_id，由 getSmsGatewayInfo 內部解析）
        $this->sendValidateCode($member, $input, $token, $times);

        return true;
    }

    private function checkRecoverPassword(Collection $members, array $input): void
    {
        $guestValidateToken = [REDACTED]'guest_validate_token'] ?? '';
        $guestMember = $this->getMemberByGuestValidateToken($guestValidateToken);
        if ($members->every(function (Member $member) use ($guestMember) {
            return $member->id != $guestMember->id;
        })) {
            throw new ApiException(__('error_message.1007'));
        }
    }

    private function handleRecoverPasswordFail(array $input): void
    {
        if ($input['type'] == ValidationCodeTypeEnum::RECOVER_PASSWORD->value) {
            $guestValidateToken = [REDACTED]'guest_validate_token'] ?? '';
            $guestMember = $this->getMemberByGuestValidateToken($guestValidateToken);
            $count = $this->IncreaseFailCount($guestMember);
            // 手機或信箱不符合當前會員資料次數達到3次鎖定帳號
            if ($count >= 3) {
                $this->throwMemberLockedException($guestMember);
            }
        }
    }

    protected function getFailCount(Member $member): int
    {
        $key = CacheKeys::moblieEmailMatchFailCount($member->id);
        $failCount = (int) Redis::get($key);

        return $failCount;
    }

    protected function IncreaseFailCount(Member $member): int
    {
        $key = CacheKeys::moblieEmailMatchFailCount($member->id);
        $failCount = (int) Redis::incr($key);
        if ($failCount == 1) {
            Redis::expire($key, CacheTtl::ONE_DAY->value);
        }

        return $failCount;
    }

    /**
     * @return Collection<int, Member>
     *
     * @throws ApiException
     */
    private function filterMembers(Agent $agent, array $input): Collection
    {
        $members = Member::query()
            ->with(['agent'])
            ->whereHas('agent', function ($query) use ($agent) {
                $query->where('organization_id', $agent->organization_id);
                $query->where('binding_scope_id', $agent->binding_scope_id);
                $query->where('status', 1);
            })
            ->when($input['verify_by'] == 1 && $input['mobile'] != '', function ($query) use ($input) {
                $query->where('mobile', $input['mobile']);
                $query->where('mobile_validate', 1);
            })
            ->when($input['verify_by'] == 2 && $input['email'] != '', function ($query) use ($input) {
                $query->where('email', $input['email']);
                $query->where('email_validate', 1);
            })
            ->when(!empty($input['username']), function ($query) use ($input) {
                $query->where('username', $input['username']);
            })
            ->whereIn('status', [1, 2])
            ->get();

        // 會員不存在
        if ($members->isEmpty()) {
            throw new ApiException(__('error_message.1007'));
        }

        return $members;
    }
}
