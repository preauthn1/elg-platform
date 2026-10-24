<?php
namespace target\support\Helpers\CashFlows;

use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use Exception;
use Illuminate\Http\Request;
use Validator;

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
    abstract public function send(
        AgentCashFlow $agentCashFlow,
        Order $order,
        string $callbackUrl,
        string $returnUrl
    );

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    abstract public function callback(Request $request);

    /**
     * 取得 AgentCashFlow
     * @param integer $agent_cash_flow_id
     * @return AgentCashFlow
     * @throws Exception
     */
    protected function getAgentCashFlow($agent_cash_flow_id)
    {
        $agentCashFlow = AgentCashFlow::find($agent_cash_flow_id);
        if ($agentCashFlow === null) {
            throw new Exception("can not find agent cash flow.", 2002);
        }
        return $agentCashFlow;
    }

    /**
     * 取得 AgentCashFlow
     * @param Request $request
     * @param array $rules
     * @throws Exception
     */
    protected function validate(Request $request, array $rules)
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new Exception($validator->errors()->first(), 2002);
        }
    }


    protected function getAmount($amount)
    {
        return ($amount != '')
            ? str_replace(',', '', $amount)
            : 0 ;
    }
}
