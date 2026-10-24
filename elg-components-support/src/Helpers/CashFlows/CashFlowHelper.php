<?php

namespace target\support\Helpers\CashFlows;

use target\support\Enums\CashFlowEnum;
use target\support\Models\AgentCashFlow;
use target\support\Models\FirebaseCloudMessage;
use target\support\Models\Order;
use target\support\Models\Member;
use target\support\Models\Agent;
use target\support\Models\REDIS;
use target\support\Services\CashFlowService;
use target\support\Services\TelegramService;
use Illuminate\Http\Request;

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
            case CashFlowEnum::DEPOSIT_NCPAY:
                $this->instance = new NCPay();
                break;
            case CashFlowEnum::DEPOSIT_ALLPAY:
                //case CashFlowEnum::DEPOSIT_MARS_CARD:
            case CashFlowEnum::DEPOSIT_ALLPAY_SGD:
            case CashFlowEnum::DEPOSIT_SE_PAY_CENTER:
                $this->instance = new MTPay();
                break;
            case CashFlowEnum::DEPOSIT_USDT:
                $this->instance = new USDTPay();
                break;
            case CashFlowEnum::DEPOSIT_FAST_PAY:
                $this->instance = new FastPay();
                break;
            case CashFlowEnum::DEPOSIT_ASIA_LIFE_PAY:
                $this->instance = new AsiaLifePay();
                break;
            case CashFlowEnum::DEPOSIT_TOUCHN_GO:
                $this->instance = new TouchngoPay();
                break;
            case CashFlowEnum::DEPOSIT_XPAY:
                $this->instance = new XPay();
                break;
            case CashFlowEnum::DEPOSIT_TELCO:
                $this->instance = new TelcoPay();
                break;
            case CashFlowEnum::DEPOSIT_AUTO_BANK_PAY:
                $this->instance = new AutoBankPay();
                break;
            case CashFlowEnum::DEPOSIT_QR_RELOAD:
                $this->instance = new QRReload();
                break;
            case CashFlowEnum::DEPOSIT_BIG_PAY:
                $this->instance = new BigPay();
                break;
            case CashFlowEnum::DEPOSIT_MB88_PAY:
                $this->instance = new Mb88Pay();
                break;
            case CashFlowEnum::DEPOSIT_SURE_PAY:
                $this->instance = new SurePay();
                break;
            case CashFlowEnum::DEPOSIT_SURE_PAY_IDR:
                $this->instance = new SurePayIdr();
                break;
            case CashFlowEnum::DEPOSIT_DG_PAY:
            case CashFlowEnum::DEPOSIT_DG_PAY_SGD:
            case CashFlowEnum::DEPOSIT_DG_PAY_THB:
            case CashFlowEnum::DEPOSIT_DG_PAY_IDR:
                $this->instance = new DGPay();
                break;
            case CashFlowEnum::DEPOSIT_DG_PAY_THB_V2:
                $this->instance = new DGPayTHB();
                break;
            case CashFlowEnum::DEPOSIT_FEI_BAO_PAY:
                $this->instance = new FeiBaoPay();
                break;
            case CashFlowEnum::DEPOSIT_FON_PAY:
                $this->instance = new FonPay();
                break;
            case CashFlowEnum::DEPOSIT_VG_PAY:
                $this->instance = new VGPay();
                break;
            case CashFlowEnum::DEPOSIT_GFF_PAY:
                $this->instance = new GffPay();
                break;
            case CashFlowEnum::DEPOSIT_TNGPIN_PAY:
                $this->instance = new TngpinPay();
                break;
            case CashFlowEnum::DEPOSIT_LEGEND_PAY:
                $this->instance = new LegendPay();
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
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        return $this->instance->send($agentCashFlow, $order, $callbackUrl, $returnUrl);
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
            if ($order->member_id > 0) {
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
                        'member_id' => $result->getOrder()->member_id,
                        'title' => '系統訊息',
                        'content' => '線上儲值成功',
                        'url' => env('PUSH_URL') . 'RecordList'
                    ];
                    FirebaseCloudMessage::create($message);
                }
            } else {
                $service = new CashFlowService();
                $service->updateAgentBalance($result->getOrder());
            }
        }
        return $result->getResponse();
    }
}
