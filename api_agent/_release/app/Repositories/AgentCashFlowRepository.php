<?php

namespace App\Repositories;

use App\Models\AgentCashFlow;
use DB;
use App\Helpers\Common;

class AgentCashFlowRepository extends Repository
{
   
    /**
     * AgentRepository constructor.
     * @param Agent $model
     */
    public function __construct()
    {
        $this->model = new AgentCashFlow();
    }

    /* 取得特定代理 可用的cash_flows */
    public function getAvailableCashflowByAgentID($agent_id, $filter_cash_flow_ids = [])
    {
        $queryResult = DB::table('agents as a')
            ->join('agent_cash_flows as acf', function ($join) use ($filter_cash_flow_ids) {
                $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                    ->where('acf.status', 1);
                if (count($filter_cash_flow_ids) > 0) {
                    $join->whereIn('acf.cash_flow_id', $filter_cash_flow_ids);
                }
            })
            ->join('cash_flows as c', function ($join) {
                $join->on('acf.cash_flow_id', 'c.id')
                    ->where('c.status', 1);
            })
            ->where('a.id', $agent_id)
            ->select(
                'c.id as cash_flow_id',
                'acf.id as agent_cash_flow_id',
                'c.name',
                'example.invalid'
            )
            ->get();

        return $queryResult;
    }


    /* 取得特定代理 可用的 [代付] cash_flows */
    public function getAvailablePayoutCashflowByAgentID($agent_id, $targetBankCode = '', $amount)
    {
        //先寫死只有特定的cash_flow_id 可以支援代付
        $aviable_payout_cashflows = [11, 14, 23];

        $query = $this->getAvailableCashflowByAgentID($agent_id, $aviable_payout_cashflows);

        if (count($query) <= 0 || Common::IsNullOrEmptyString($targetBankCode)) {
            return $query;
        }

        //若指定參數 要過濾bankCode
        $bankAvailableCashFlows = array();

        foreach ($query as $item) {
            if (Common::IsNullOrEmptyString($item->info)) {
                continue;
            }

            $setting = json_decode($item->info);
            if (!property_exists($setting , 'available_banks')) {
                continue;
            }

            if (!is_array($setting->available_banks)) {
                continue;
            }

            if (property_exists($setting , 'min_amount') && $setting->min_amount > $amount) {
                continue;
            }

            if (property_exists($setting , 'max_amount') && $setting->max_amount < $amount) {
                continue;
            }

            foreach ($setting->available_banks as $each_bank) {
                //判斷有支援的銀行 加到array
                if ($each_bank->BankCode_ELG == $targetBankCode) {
                    array_push($bankAvailableCashFlows, $item);
                }
            }
        }

        if(count($bankAvailableCashFlows) <= 0) {
            return [];
        }

        return json_decode(json_encode($bankAvailableCashFlows));
    }
}