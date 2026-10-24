<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use target\support\Models\AgentCashFlow;
use App\Helpers\VendorSend;
use App\Models\Order;
use DB;

class CheckTelcopay extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_telco';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '確認telco交易記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_send;
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->checkOrder();
    }
    
    private function checkOrder()
    {
        $start = strtotime('-1day');
        $stop = date('U');
        $agent_cash_flows = AgentCashFlow::where('cash_flow_id', 20)->where('status', 1)->get();
        if (count($agent_cash_flows) > 0) {
            foreach ($agent_cash_flows as $agent_cash_flow) {
                echo '-------- AgentCashFlowID ' . $agent_cash_flow->id . ' --------' . PHP_EOL;
                $info = json_decode($agent_cash_flow['info'], true);
                $orders = Order::where('agent_cash_flow_id', $agent_cash_flow->id)
                    ->where('status', 0)
                    ->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)')
                    ->whereRaw('created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
                    ->select(
                        'id',
                        'order_no',
                        'callback',
                        'remark'
                    )
                    ->get();
                if (count($orders) > 0) {
                    echo 'COUNT:' . count($orders) . PHP_EOL;
                    $this->getOrderResult($info, $orders);
                } else {
                    echo 'No Record' . PHP_EOL;
                }
                echo PHP_EOL;
            }
        }
    }

    private function getOrderResult($info, $orders)
    {
        try {
            $url = $info['APIUrl'] . '/apitelco/api/card/check';
            foreach ($orders as $order) {
                if ($order->remark != '') {
                    $remark = json_decode($order->remark, true);
                    $req = $remark['request'];

                    $post_data = array();
                    $post_data['trans_id'] = isset($req['trans_id']) ? $req['trans_id'] : $order->order_no;
                    $post_data['serial'] = $req['serial'];
                    $str = $post_data['trans_id'] . '|' . $post_data['serial'] . '|' . $info['PartnerId'];
                    $post_data['signature'] = strtolower(md5($str));
                    $headers = array('Content-Type: application/json');
                    $response = $this->vendor_send->send('telco_check', $url,'POST', $post_data, $headers);
                    $json = json_decode($response, true);
                    echo 'Trans_id:' . $post_data['trans_id'] . ' Serial:' . $req['serial'];
                    if (isset($json['status'])) {
                        // 呼叫external直接上分
                        $post_data = array();
                        $post_data['status'] = $json['status'];
                        $post_data['desc'] = $json['desc'];
                        $post_data['amount'] = $json['amount'];
                        $post_data['real_amount'] = $json['real_amount'];
                        $post_data['trans_id'] = isset($req['trans_id']) ? $req['trans_id'] : $order->order_no;
                        $post_data['serial'] = $req['serial'];
                        $str = $post_data['status'] . '|' . $post_data['trans_id'] . '|' . $post_data['serial'] . '|' .
                            $info['PartnerId'] . '|' . $info['PrivateKey'];
                        $post_data['signature'] = strtolower(md5($str));
                        $ext_res = $this->vendor_send->send('Call_External_API', $order->callback, 'POST', $post_data, $headers);
                        $ext_res_data = json_decode($ext_res, true);
                        if ($ext_res_data['status'] == 0) {
                            echo ' SUCCESS';
                        } else {
                            echo ' Fail';
                        }
                    } else {
                        echo ' Fail';
                    }
                    echo PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}