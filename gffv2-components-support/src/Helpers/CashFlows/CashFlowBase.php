<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use target\support\Models\Member;
use target\support\Models\MemberWallet;
use Exception;
use Validator;
use DB;

abstract class CashFlowBase
{
    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode
     *      "url" : string
     * ]
     */
    abstract protected function send(
        AgentCashFlow $agentCashFlow,
        Order $order,
        string $callback_url,
        string $return_url
    );

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    abstract protected function callback(Request $request);

    /**
     * 取得 AgentCashFlow
     * @param integer $agent_cash_flow_id
     * @return AgentCashFlow
     * @throws Exception
     */
    protected function getAgentCashFlow($agent_cash_flow_id)
    {
        $agentCashFlow = DB::table('agent_cash_flows as acf')
            ->join('cash_flow_infos as cfi', function ($join) {
                $join->on('cfi.id', 'acf.cash_flow_info_id')
                    ->where('cfi.status', 1);
            })
            ->where('acf.id', $agent_cash_flow_id)
            ->select(
                'cfi.cash_flow_id',
                'acf.id as agent_cash_flow_id',
                'example.invalid'
            )
            ->first();
        if ($agentCashFlow === null) {
            throw new Exception(__('error_message.cashflow.2001'), 2001);
        }
        return $agentCashFlow;
    }

    /**
     * 取得 OrderType
     * @param integer $cash_flow_id
     * @param string $code
     * @return OrderType
     * @throws Exception
     */
    protected function getOrderTypeByCashFlowId($cash_flow_id, $code)
    {
        $orderType = OrderType::where('cash_flow_id', $cash_flow_id)
            ->where('code', $code)
            ->where('status', 1)
            ->first();
        if ($orderType === null) {
            throw new Exception(__('error_message.cashflow.2002'), 2002);
        }
        return $orderType;
    }

    /**
     * 取得 Member
     * @param string $uuid
     * @return Member
     * @throws Exception
     */
    protected function getMemberByUuid($uuid)
    {
        $member = Member::where('uuid', $uuid)->first();
        if ($member === null) {
            throw new Exception(__('error_message.cashflow.2003'), 2003);
        }
        return $member;
    }

    /**
     * 取得 MemberWallet
     * @param integer $member_id
     * @param integer $wallet_type_id
     * @return MemberWallet
     * @throws Exception
     */
    protected function getMemberWalletById($member_id, $wallet_type_id)
    {
        $memberWallet = MemberWallet::where('member_id', $member_id)
            ->where('wallet_type_id', $wallet_type_id)
            ->first();
        if ($memberWallet === null) {
            throw new Exception(__('error_message.cashflow.2004'), 2004);
        }
        return $memberWallet;
    }

    /**
     * 格式檢查
     * @param Request $request
     * @param array $rules
     * @throws Exception
     */
    protected function validate(Request $request, array $rules)
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new Exception(__('error_message.9997'), 9997);
        }
    }

    /**
     * 數字轉換
     * @param string $amount
     * @return $amount
     * @throws Exception
     */
    protected function getAmount($amount)
    {
        return ($amount != '')
            ? str_replace(',', '', $amount)
            : 0 ;
    }
}
