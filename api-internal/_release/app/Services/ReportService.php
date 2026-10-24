<?php
namespace App\Services;

use App\Repositories\RedEnvelopeRepository;


class ReportService
{
    public function sr07($agent_id, $username, $start_time, $end_time, $page=1, $per_page=20) {
        // 用 agent_id 所有代理的username.
        $agents = \DB::connection()->table('agents')->where('hierarchy', 'like', '%,'.$agent_id.',%')->select('username')->get();
        $usernames = $agents->pluck('username')->all();
        // 用所有代理的username 換 userid
        $user_ids = \DB::connection('cs')->table('users')->whereIn('username', $usernames)->select('id')->pluck('id')->all();

        // 查詢紅包明細表
        $repo = new RedEnvelopeRepository();
        return $repo->SearchRecordsPagination($user_ids, $username, $start_time, $end_time, $page, $per_page);
    }

}