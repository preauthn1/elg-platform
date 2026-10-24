<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use App\Models\MemberCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberCodeService
{
    public function get(Member $member): MemberCode
    {
        // ! Not use firstOrCreate here, since makeMemberAgentCode() is recursive.
        // ! If use makeMemberAgentCode() in firstOrCreate, it will be called whether the record exists or not.
        // 以 transaction + lockForUpdate 包住「查無則建」，避免併發下同一 member_id 產生多筆。
        return DB::transaction(function () use ($member) {
            $memberCode = MemberCode::where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($memberCode instanceof MemberCode) {
                return $memberCode;
            }

            return MemberCode::create([
                'member_id' => $member->id,
                'agent_code' => $this->makeMemberAgentCode(),
            ]);
        });
    }

    public function makeMemberAgentCode(): string
    {
        $str = Str::upper(config('custom.unlimit_prefix') . Str::random(7));
        $is_exist = MemberCode::where('agent_code', $str)->count();
        if ($is_exist) {
            return $this->makeMemberAgentCode();
        }

        return $str;
    }
}
