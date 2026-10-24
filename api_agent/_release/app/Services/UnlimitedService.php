<?php

namespace App\Services;

use DB;
use App\Repositories\AgentRepository;

class UnlimitedService extends Service
{
    /* Repositories */
    protected $agentRepository;

    /* services */
    protected $agentService;

    /**
     * UnlimitedService constructor.
     * @param UnlimitRepository $repository
     */
    public function __construct()
    {
        $this->agentService = new AgentService();
        $this->agentRepository = new AgentRepository();
    }

    public function platformReconciliationTotal($request, $isAgent)
    {
        $agent = (isset($request->agent_username) && $request->agent_username != '')
            ? $this->agentRepository->firstBy('username', $request->agent_username)
            : $this->agentRepository->collectBy($request->HOST);
        if (is_object($agent)) {
            //只能查詢自己或自己下線的，修改子帳號權限，$request->ID => $request->HOST
            if (!$this->agentService->isChild($request->HOST, $agent->id)) {
                //不是下線
                return false;
            }
        } else {
            //代理商不存在
            return false;
        }
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'));

        if ($isAgent) {
            $model = DB::table('unlimit_bonusreport_agents as ua')
                ->join('agents as a', function($join) use ($agent) {
                   $join->on('a.id', 'ua.agent_id')
                       ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                })
                ->whereBetween('ua.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(ua.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(ua.bonus_team, 2)'), '>', 0);
                })
                ->select(
                    DB::raw('a.id as agent_id'),
                    DB::raw('TRUNCATE(ua.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(ua.bonus_team, 2) as bonus_team')
                );
            // 會員
            $member_model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', function ($join) use ($agent) {
                    $join->on('m.id', 'um.member_id');
                })
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%')
                        ->where('a.id', '!=', $agent->id);
                })
                ->whereBetween('um.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(um.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(um.bonus_team, 2)'), '>', 0);
                })
                ->select(
                    DB::raw('a.id as agent_id'),
                    DB::raw('TRUNCATE(um.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(um.bonus_team, 2) as bonus_team')
                );
            $model = $model
                ->unionAll($member_model);
            $model = DB::table(DB::raw("({$model->toSql()}) AS s"))
                ->mergeBindings($model);

            $type = DB::raw("CASE WHEN agent_id = " . $agent->id . " THEN 'self' ELSE 'agent' END as type");
            $lottery = DB::raw('SUM(s.bonus_direct) + SUM(s.bonus_team) as lottery');

        } else {
            // 會員
            $model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', function ($join) use ($agent) {
                    $join->on('m.id', 'um.member_id')
                        ->where('m.parent', $agent->id);
                })
                ->join('agents as a', function ($join) use ($agent) {
                   $join->on('a.id', 'm.parent')
                       ->where('a.id', $agent->id);
                })
                ->whereBetween('um.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(um.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(um.bonus_team, 2)'), '>', 0);
                });

            $type = DB::raw("'member' as type");
            $lottery = DB::raw('SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery');
        }

        $model = $model
			->select(
				$type, 
				DB::raw("'" . $agent->id . "' as agent_id"), 
				DB::raw("'" . $agent->username . "' as agent_username"), 
				DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'V-Reward' as vendor"),
                DB::raw("'-2' as vendor_id"),
				DB::raw("'-' as game_type"), 
				DB::raw('0 as game_type_id'), 
				DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as commission'),
                $lottery,
				DB::raw('0 as share_amount'))
            ->groupBy('vendor')
            ->groupBy('type');
        return $model;
    }

    // SR11 代理
    public function platformReconciliationModel($request, $isAgent, $temp)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'));

        if ($isAgent) {
            // 代理
            $model = DB::table('unlimit_bonusreport_agents as ua')
                ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'ua.agent_id')
                ->mergeBindings($temp)
                ->whereBetween('ua.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(ua.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(ua.bonus_team, 2)'), '>', 0);
                })
                ->select(
                    DB::raw('temp.agent_id as id'),
                    DB::raw('temp.username as username'),
                    DB::raw('temp.nickname as nickname'),
                    DB::raw('TRUNCATE(ua.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(ua.bonus_team, 2) as bonus_team')
                );
            // 會員
            $member_model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', 'm.id', 'um.member_id')
                ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
                ->mergeBindings($temp)
                ->whereBetween('um.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(um.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(um.bonus_team, 2)'), '>', 0);
                })
                ->select(
                    DB::raw('temp.agent_id as id'),
                    DB::raw('temp.username as username'),
                    DB::raw('temp.nickname as nickname'),
                    DB::raw('TRUNCATE(um.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(um.bonus_team, 2) as bonus_team')
                );
            $model = $model
                ->unionAll($member_model);
            $model = DB::table(DB::raw("({$model->toSql()}) as s"))
                ->mergeBindings($model);
            $type = DB::raw("'agent' as type");
            $lottery = DB::raw('SUM(s.bonus_direct) + SUM(s.bonus_team) as lottery');

        } else {
            // 會員
            $model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', 'm.id', 'um.member_id')
                ->join('agents as s', function ($join) use ($temp) {
                    $join->on('s.id', 'm.parent')
                        ->where('s.id', $temp->id);
                })
                ->whereBetween('um.created_at', [$start_time, $end_time])
                ->where(function ($query) {
                    $query->where(DB::raw('TRUNCATE(um.bonus_direct, 2)'), '>', 0)
                        ->orWhere(DB::raw('TRUNCATE(um.bonus_team, 2)'), '>', 0);
                });
            $type = DB::raw("'member' as type");
            $lottery = DB::raw('SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery');
        }
        $data = $model->select(
            $type,
            DB::raw('s.id as agent_id'),
            DB::raw('s.username as agent_username'),
            DB::raw('s.nickname as agent_nickname'),
            DB::raw("'V-Reward' as vendor"),
            DB::raw("'-2' as vendor_id"),
            DB::raw("'-' as game_type"),
            DB::raw('0 as game_type_id'),
            DB::raw('0 as total_bet'),
            DB::raw('0 as win_lose'),
            DB::raw('0 as commission'),
            $lottery,
            DB::raw('0 as share_amount'))
            ->groupBy('s.id'); //加上group沒記錄的才不會出現0
            //->groupBy('temp.agent_id'); //加上group沒記錄的才不會出現0
        return $data;
    }

    // SR11 會員
    public function platformReconciliationMember($request)
    {
        $agent = (isset($request->agent_username) && $request->agent_username != '')
            ? $this->agentRepository->firstBy('username', $request->agent_username)
            : ((isset($request->member_username) && $request->member_username != '')
                ? DB::table('members as m')
                    ->join('agents as a', function ($join) use ($request) {
                        $join->on('m.parent', 'a.id')
                            ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    })
                    ->where('m.username', $request->member_username)
                    ->select('a.id', 'a.parent')->first()
                : $this->agentRepository->collectBy($request->HOST));
        if (is_object($agent)) {
            //只能查詢自己或自己下線的，修改子帳號權限，$request->ID => $request->HOST
            if (!$this->agentService->isChild($request->HOST, $agent->id)) {
                //不是下線
                return false;
            }
        } else {
            //代理商不存在
            return false;
        }

        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'));

        //以agent為主
        $model = DB::table('unlimit_bonusreport as um')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 'um.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->whereBetween('um.created_at', [$start_time, $end_time])
            ->where(function ($query) {
                $query->where(DB::raw('TRUNCATE(um.bonus_direct, 2)'), '>', 0)
                    ->orWhere(DB::raw('TRUNCATE(um.bonus_team, 2)'), '>', 0);
            })
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("'V-Reward' as vendor"),
                DB::raw("'-2' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery'),
                DB::raw('0 as share_amount')
            )
            ->groupBy('m.id');
        return $model;
    }

    public function newPlatformReconciliationTotal($request, $agent, $isAgent, $promotion)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute'));

        if ($isAgent) {
            $model = DB::table('unlimit_bonusreport_agents as ua')
                ->join('agents as a', function($join) use ($agent) {
                    $join->on('a.id', 'ua.agent_id')
                        ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                })
                ->where('ua.created_at', '>=', $start_time)
                ->where('ua.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(ua.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(ua.bonus_team, 2) > 0');
                })
                ->select(
                    DB::raw('a.id as agent_id'),
                    DB::raw('TRUNCATE(ua.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(ua.bonus_team, 2) as bonus_team')
                );
            // 會員
            $member_model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', function ($join) use ($agent) {
                    $join->on('m.id', 'um.member_id');
                })
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%')
                        ->where('a.id', '!=', $agent->id);
                })
                ->where('um.created_at', '>=', $start_time)
                ->where('um.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
                })
                ->select(
                    DB::raw('a.id as agent_id'),
                    DB::raw('TRUNCATE(um.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(um.bonus_team, 2) as bonus_team')
                );
            $model = $model
                ->unionAll($member_model);
            $model = DB::table(DB::raw("({$model->toSql()}) AS s"))
                ->mergeBindings($model);

            $type = DB::raw("CASE WHEN agent_id = " . $agent->id . " THEN 'self' ELSE 'agent' END as type");
            $lottery = DB::raw('SUM(s.bonus_direct) + SUM(s.bonus_team) as lottery');

        } else {
            // 會員
            $model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', function ($join) use ($agent) {
                    $join->on('m.id', 'um.member_id')
                        ->where('m.parent', $agent->id);
                })
                ->join('agents as a', function ($join) use ($agent) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.id', $agent->id);
                })
                ->where('um.created_at', '>=', $start_time)
                ->where('um.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
                });

            $type = DB::raw("'member' as type");
            $lottery = DB::raw('SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery');
        }

        $model = $model
            ->select(
                $type,
                DB::raw("'" . $agent->id . "' as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'" . $promotion . "' as vendor"),
                DB::raw("'-2' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as commission'),
                DB::raw('0 as cost'),
                $lottery,
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                DB::raw('2 as sort')
            )
            ->groupBy('vendor')
            ->groupBy('type');
        return $model;
    }

    public function newPlatformReconciliationModel($request, $isAgent, $temp, $promotion)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute'));

        if ($isAgent) {
            // 代理
            $model = DB::table('unlimit_bonusreport_agents as ua')
                ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'ua.agent_id')
                ->mergeBindings($temp)
                ->join('agents as a', 'a.id', 'temp.agent_id')
                ->where('ua.created_at', '>=', $start_time)
                ->where('ua.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(ua.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(ua.bonus_team, 2) > 0');
                })
                ->select(
                    DB::raw("temp.agent_id as id"),
                    DB::raw("a.username as username"),
                    DB::raw("a.nickname as nickname"),
                    DB::raw("TRUNCATE(ua.bonus_direct, 2) as bonus_direct"),
                    DB::raw("TRUNCATE(ua.bonus_team, 2) as bonus_team")
                );
            // 會員
            $member_model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', 'm.id', 'um.member_id')
                ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
                ->mergeBindings($temp)
                ->join('agents as a', 'a.id', 'temp.agent_id')
                ->where('um.created_at', '>=', $start_time)
                ->where('um.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
                })
                ->select(
                    DB::raw('temp.agent_id as id'),
                    DB::raw('a.username as username'),
                    DB::raw('a.nickname as nickname'),
                    DB::raw('TRUNCATE(um.bonus_direct, 2) as bonus_direct'),
                    DB::raw('TRUNCATE(um.bonus_team, 2) as bonus_team')
                );
            $model = $model
                ->unionAll($member_model);
            $model = DB::table(DB::raw("({$model->toSql()}) as s"))
                ->mergeBindings($model);
        } else {
            // 會員
            $model = DB::table('unlimit_bonusreport as um')
                ->join('members as m', 'm.id', 'um.member_id')
                ->join('agents as s', function ($join) use ($temp) {
                    $join->on('s.id', 'm.parent')
                        ->where('s.id', $temp->id);
                })
                ->where('um.created_at', '>=', $start_time)
                ->where('um.created_at', '<', $end_time)
                ->where(function ($query) {
                    $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                        ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
                });
        }
        $data = $model->groupBy('s.id')     //加上group沒記錄的才不會出現0
        ->select(
            DB::raw(($isAgent) ? "'agent' as type" : "'member' as type"),
            DB::raw("s.id as agent_id"),
            DB::raw("s.username as agent_username"),
            DB::raw("s.nickname as agent_nickname"),
            DB::raw("'" . $promotion . "' as vendor"),
            DB::raw("'-2' as vendor_id"),
            DB::raw("'-' as game_type"),
            DB::raw("0 as game_type_id"),
            DB::raw("0 as total_bet"),
            DB::raw("0 as win_lose"),
            DB::raw("0 as cost"),
            DB::raw("0 as commission"),
            DB::raw(($isAgent)
                ? "SUM(s.bonus_direct) + SUM(s.bonus_team) as lottery"
                : "SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery"
            ),
            DB::raw("0 as share_amount"),
            DB::raw("0 as v_jackpot"),
            DB::raw('2 as sort')
        );
        return $data;
    }

    public function newPlatformReconciliationMember($request, $agent, $promotion)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute'));

        //以agent為主
        $model = DB::table('unlimit_bonusreport as um')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 'um.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->where('um.created_at', '>=', $start_time)
            ->where('um.created_at', '<', $end_time)
            ->where(function ($query) {
                $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                    ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
            })
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("'" . $promotion . "' as vendor"),
                DB::raw("'-2' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as cost'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(um.bonus_direct, 2)) + SUM(TRUNCATE(um.bonus_team, 2)) as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                DB::raw('2 as sort')
            )
            ->groupBy('m.id');
        return $model;
    }
}
