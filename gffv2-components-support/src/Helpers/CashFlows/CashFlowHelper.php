<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Models\AgentCashFlow;
use target\support\Models\FirebaseCloudMessage;
use target\support\Models\Order;
use target\support\Models\Member;
use target\support\Models\MemberWallet;
use target\support\Models\Agent;
use target\support\Models\REDIS;
use target\support\Services\CashFlowService;
use target\support\Services\TelegramService;
use target\support\Enums\CashFlowEnum;
use target\support\Helpers\CashFlows\USDTPay;
use DB;

class CashFlowHelper
{
    private $instance;

    /**
     * 建構子
     * @param  int $cash_flow_id 金流編號
     * @return self
     * @throws
     */
    public function __construct(int $cash_flow_id)
    {
        switch ($cash_flow_id) {
            case CashFlowEnum::DEPOSIT_DEFI_ENGINE:
            case CashFlowEnum::WITHDRAW_DEFI_ENGINE:
                $this->instance = new DefiPay();
                break;
            case 4:
            case 104:
                $this->instance = new USDTPay();
                break;
            default:
                throw new \InvalidArgumentException(
                    get_class($this) .
                    "_construct exception: cash_flow_id " .
                    print_r($cash_flow_id, true) .
                    " invalid"
                );
        }
    }

    /**
     * 調用三方app, type: 0 失敗, 1 轉址, 2 QRCode, 3 html string
     * @param  AgentCashFlow $agentCashFlow
     * @param  Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *   "type": 1,
     *   "url": "位址",
     *   "html": "html string"
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callback_url, string $return_url)
    {
        return $this->instance->send($agentCashFlow, $order, $callback_url, $return_url);
    }

    /**
     * 代付回調
     * @param  Request $request
     * @return string
     */
    public function withdrawCallback(Request $request)
    {
        //檢查代付回調數據是否正確
        $result = $this->instance->withdrawCallback($request);
        if ($result->getStatus() == 1) { //代付回調成功
            //新增推播
            // $message = [
            //     'member_id' => $result->getOrder()->member_id,
            //     'title' => '系統訊息',
            //     'content' => '線上儲值成功',
            //     'url' => env('PUSH_URL') . 'RecordList'
            // ];
            // FirebaseCloudMessage::create($message);
            //鎖分模式; 不上分, 直接發TG通知
            
            // 刪除Redis中相關的資料
            $redis = new REDIS();
            $redis->delete(array(
                'member'
            ));
        }
        return $result->getResponse();
    }

    /**
     * 三方回調
     * @param  Request $request
     * @return string
     */
    public function callback(Request $request)
    {
        $result = $this->instance->callback($request);
        if ($result->getStatus() == 1) { //回調成功上分
            $order = $result->getOrder();
            $member = Member::find($order->member_id);
            //鎖分模式; 不上分, 直接發TG通知
            if ($member->event_mode > 0) {
                $agent = Agent::find($member->parent);
                $message = "[鎖分模式禁止上分通知]" . PHP_EOL .
                    "代　　理:" . $agent->username . "(" . $agent->id . ")" . PHP_EOL .
                    "玩　　家:" . $member->username . "(" . $member->id . ")" . PHP_EOL .
                    "訂單編號:" . $order->order_no . PHP_EOL .
                    "點　　數:" . $order->point;
                $tg_service = new TelegramService();
                $tg_service->sendMessage($message, 'info');
            } elseif ($order->status != 4) {
                $service = new CashFlowService();
                $service->updateMemberBalance($result->getOrder());
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'member'
                ));
                //新增推播
                $message = [
                    'member_id' => $order->member_id,
                    'title' => '系統訊息',
                    'content' => '線上儲值成功',
                    'url' => env('PUSH_URL') . 'RecordList'
                ];
                FirebaseCloudMessage::create($message);

                // 充值活動檢查
                DB::update('call SP_Check_Deposit(' . $order->id . ')');
            }
        }
        return $result->getResponse();
    }
}
