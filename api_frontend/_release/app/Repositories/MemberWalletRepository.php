<?php

namespace App\Repositories;

use App\Models\MemberWallet;
use App\Models\AgentWallet;
use App\Models\WalletType;
use DB;

class MemberWalletRepository
{
    protected $member_id;
    protected $agent_group_id;
    protected $language_id;
    protected $wallet_type_id;

    public function create($member_id, $wallet_type_id, $balance = 0)
    {
        $data = array();
        $data['member_id'] = $member_id;
        $data['wallet_type_id'] = $wallet_type_id;
        $data['balance'] = $balance;
        $data['status'] = 1;
        $data['admin'] = 51;
        $wallet = MemberWallet::create($data);
        return $wallet;
    }

    public function find($member_id, $agent_group_id, $language_id, $wallet_type_id = 0)
    {
        $wallet = DB::table('member_wallets AS mw')
            ->join('agent_wallets as aw', function ($join) use ($agent_group_id) {
                $join->on('aw.wallet_type_id', 'mw.wallet_type_id')
                    ->where('aw.agent_group_id', $agent_group_id)
                    ->where('aw.status', 1);
            })
            ->join('wallet_types AS wt', function ($join) {
                $join->on('wt.id', 'mw.wallet_type_id')
                    ->where('wt.status', 1);
            })
            ->leftJoin('wallet_type_names as wtn', function ($join) use ($language_id) {
                $join->on('wtn.wallet_type_id', 'wt.id')
                    ->where('wtn.language_id', $language_id);
            })
            ->where('mw.member_id', $member_id);
        if ($wallet_type_id > 0) {
            $wallet = $wallet->where('mw.wallet_type_id', $wallet_type_id);
        }
        $wallet = $wallet->where('mw.status', 1)
            ->select(
                'wt.id AS wallet_type_id',
                DB::raw('IFNULL(wtn.name, wt.name) as name'),
                'mw.balance'
            )
            ->get();
        return $wallet;
    }

}