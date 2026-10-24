<?php

declare(strict_types=1);

namespace App\Services\ValidateToken;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;

class MemberNameService extends CheckValidationCodeService
{
    /**
     * 獲取會員名稱選單
     *
     * 維持接口返回格式一制性, 就算只有一筆資料也應該返回陣列
     *
     * @throws ApiException
     */
    public function getMemberNameList(array $input, Agent $agent): array
    {
        // 驗證 token 是否有效
        $validateToken = [REDACTED]'token'], $input['mobile'] ?? null, $input['email'] ?? null);

        // 查詢會員
        $member = $this->getMember($validateToken);

        $this->checkDomain($input['domain'], $member);

        $mobileBindLimit = $this->getMobileBindLimit($member->agent);

        if ($mobileBindLimit == 1) {
            return [
                $member->username,
            ];
        }

        return Member::query()
            ->whereHas('agent', function ($query) use ($agent) {
                $query->where('organization_id', $agent->organization_id);
                $query->where('binding_scope_id', $agent->binding_scope_id);
            })
            ->where(function ($query) use ($validateToken) {
                $query->where('mobile', $validateToken->info);
                $query->orWhere('email', $validateToken->info);
            })
            ->where('status', 1) // 只撈出正常帳號
            ->pluck('username')
            ->toArray();
    }
}
