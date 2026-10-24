<?php

namespace App\Http\Controllers;

use target\support\Helpers\CashFlows\CashFlowHelper;
use target\support\Models\AgentCashFlow;
use Illuminate\Http\Request;
use Log;

class CashFlowController extends Controller
{
    
    public function Callback(Request $request) {
        \DB::enableQueryLog();
        try {

            Log::debug(get_class($this) . '_Callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ]);
            $agent_cash_flow_id = intval($request->agent_cash_flow_id);
            if(!$agent_cash_flow_id) {
                return "fail1";
            }
            $agentCashFlow = AgentCashFlow::find($agent_cash_flow_id);
            if($agentCashFlow === null) {
                return "fail2";
            }
            $helper = new CashFlowHelper($agentCashFlow->cash_flow_id);
            return $helper->callback($request);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            var_dump($info);
            return '';
        } catch (\Exception $e) {
            $sqls = \DB::getQueryLog();
            return 'fail3 ' . $e->getMessage();
        }
    }
    

    /* asiaLife的callback網址無法動態指定 (對方寫死設定的) 所以不使用acf_id 當作router */
    public function AsiaLifeCallback(Request $request) {        
        try {
            Log::debug(get_class($this) . '_Callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $request->all(),
            ]);            
            $helper = new CashFlowHelper(12);
            return $helper->callback($request);

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            var_dump($info);
            return '';
        } catch (\Exception $e) {
            $sqls = \DB::getQueryLog();
            return 'fail3 ' . $e->getMessage();
        }
    }



}