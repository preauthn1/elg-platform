<?php

namespace App\Console\Commands;

use App\Helpers\VendorSend;
use App\Services\TelegramService;
use target\support\Models\AgentCashFlow;
use target\support\Services\CashFlowService;
use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\Ncpay;
use App\Models\Order;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\FirebaseCloudMessage;
use App\Models\FlowLimit;
use DB;
use App\REDIS;

class CheckNcpay extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_ncpay';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '確認Ncpay交易記錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorSend;
    public function __construct()
    {
        parent::__construct();
        $this->vendorSend = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //$this->checkNcpay();
    }

    /*
    private function checkNcpay() {
        $start = strtotime('-1day');
        $stop = date('U');
        $agentCashFlowList = AgentCashFlow::where('cash_flow_id', 2)->where('status', 1)->get();
        if (count($agentCashFlowList) > 0) {
            foreach ($agentCashFlowList as &$agentCashFlow) {
                echo '-------- AgentCashFlowID ' . $agentCashFlow->id . ' --------' . PHP_EOL;
                $this->fetchNcpay($agentCashFlow, $start, $stop);
                echo PHP_EOL;
            }
        }
    }

    private function fetchNcpay($agentCashFlow, $start, $stop, $page=1) {
        $service = new CashFlowService();
        $info = json_decode($agentCashFlow->info, true);
        $apiUrl = $info['APIUrl'];
        $postData = array(
            'start' => $start,
            'stop' => $stop,
            'page' => $page,
            'apikey' => $info['APIKey'],
            'timestamp' => date('U'),
        );
        $user = rand(10000, 99999) . rand(10000, 99999);
        $sign = sha1($user . json_encode($postData) . $info['APISecret']);
        $url = $apiUrl . 'V1/Merchant/DepositList?user=' . $user . '&sign=' . $sign;
        echo '-range-' .  $postData['start'] . ' ~ ' . $postData['stop'] . ' -page-' . $postData['page'];
        $records = json_decode($this->vendorSend->send('ncpay_check', $url,'POST', $postData, ['Content-Type:application/json']), true);
        if (is_array($records)) {
            foreach ($records['data'] as $row) {
                $order = \target\support\Models\Order::where('order_no', $row['thirdpartyid'])
                    ->where('agent_cash_flow_id', $agentCashFlow->id)
                    ->whereIn('status', [0, 2])->first();
                if(!$order || $order->status == 2 && $row['status'] != 'success') {
                    continue;
                }
                if($row['status'] == 'success') {
                    if ($order->status == 2) {
                        $TGService = new TelegramService();
                        $TGService->sendMessage(
                            sprintf(
                                '【NCPay】訂單 %s[%s] 狀態從失敗轉為成功',
                                $row['thirdpartyid'],
                                $order->third_party_order_no
                            ),
                            'info'
                        );
                    }
                    echo '*********' . $row['thirdpartyid'] . ' - ' . $row['status'] . '*********';
                    $order->status = 1;
                    $order->remark .= '主動查單 : ' . json_encode($row);
                    $order->save();
                    $service->updateMemberBalance($order);
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
                }
                else if ($row['status'] == 'fail' || $row['status'] == 'cancel') {
                    echo '--------' . $row['thirdpartyid'] . ' - ' . $row['status'] . '-------';
                    $order = \target\support\Models\Order::where('order_no', $row['thirdpartyid'])
                        ->where('agent_cash_flow_id', $agentCashFlow->id)
                        ->where('status', 0)->first();
                    $order->status = 2;
                    $order->remark = '主動查單 : ' . json_encode($row);
                    $order->save();
                    //新增推播
                    $message = [
                        'member_id' => $order->member_id,
                        'title' => '系統訊息',
                        'content' => 'NcPay儲值失敗',
                        'url' => env('PUSH_URL') . 'RecordList'
                    ];
                    FirebaseCloudMessage::create($message);
                } else {
                    //var_dump($row);
                }
            }
            //總頁數超過目前頁數要再call一次
            if ($records['totalpage'] > $postData['page']) {
                $this->fetchNcpay($agentCashFlow, $start, $stop, $page+1);
            }
        } else {
            echo 'fail';
            Common::log(0, 'fail', 'check_ncpay');
        }
    }

    private function checkResultNew($postData) {
        try {
            //{"APIUrl":"http:\/\/api.ncpay.cash\/","APIMerchant":"vworldtest","APIKey":"iVu1aV9t5vC28I9Sfd2q","APISecret":"E5IsFhdRckhD8F09MFD7XBrUj3q93dqoSPn8RuG5"}
            $service = new CashFlowService();
            $agentCashFlowList = AgentCashFlow::where('cash_flow_id', 2)
                //->where('status', 1)
                ->get();
            foreach($agentCashFlowList as &$agentCashFlow) {
                $info = json_decode($agentCashFlow->info, true);
                $api_url = $info['APIUrl'];
                $postData['apikey'] = $info['APIKey'];
                $postData['timestamp'] = date('U');
                $user = rand(10000, 99999) . rand(10000, 99999);
                // 取簽名
                $sign = sha1($user . json_encode($postData) . $info['APISecret']);
                $url = env('NCPAY_URL') . 'V1/Merchant/DepositList?user=' . $user . '&sign=' . $sign;
                echo '-range-' .  $postData['start'] . ' ~ ' . $postData['stop'] . ' -page-' . $postData['page'];
                $records = json_decode($this->vendorSend->send('ncpay_check', $url,'POST', $postData, ['Content-Type:application/json']), true);
                if (is_array($records)) {
                    foreach ($records['data'] as $row) {
                        $order = \target\support\Models\Order::where('order_no', $row['thirdpartyid'])
                            ->where('agent_cash_flow_id', $agentCashFlow->id)
                            ->whereIn('status', [0, 2])->first();
                        if(!$order || $order->status == 2 && $row['status'] != 'success') {
                            continue;
                        }
                        if($row['status'] == 'success') {
                            if ($order->status == 2) {
                                $TGService = new TelegramService();
                                $TGService->sendMessage(
                                    sprintf(
                                        '【NCPay】訂單 %s[%s] 狀態從失敗轉為成功',
                                        $row['thirdpartyid'],
                                        $order->third_party_order_no
                                    ),
                                    'info'
                                );
                            }
                            echo '*********' . $row['thirdpartyid'] . ' - ' . $row['status'] . '*********';
                            $order->status = 1;
                            $order->remark .= '主動查單 : ' . json_encode($row);
                            $order->save();
                            $service->updateMemberBalance($order);
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
                        }
                        else if ($row['status'] == 'fail' || $row['status'] == 'cancel') {
                            echo '--------' . $row['thirdpartyid'] . ' - ' . $row['status'] . '-------';
                            $order = \target\support\Models\Order::where('order_no', $row['thirdpartyid'])
                                ->where('agent_cash_flow_id', $agentCashFlow->id)
                                ->where('status', 0)->first();
                            $order->status = 2;
                            $order->remark = '主動查單 : ' . json_encode($row);
                            $order->save();
                            //新增推播
                            $message = [
                                'member_id' => $order->member_id,
                                'title' => '系統訊息',
                                'content' => 'NcPay儲值失敗',
                                'url' => env('PUSH_URL') . 'RecordList'
                            ];
                            FirebaseCloudMessage::create($message);
                        } else {
                            //var_dump($row);
                        }
                    }
                    //總頁數超過目前頁數要再call一次
                    if ($records['totalpage'] > $postData['page']) {
                        $postData['page'] = $postData['page'] + 1;
                        $this->checkResultNew($postData);
                    }
                } else {
                    echo 'fail';
                    Common::log(0, 'fail', 'check_ncpay');
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

    private function checkResult($postData)
    {
        try {
            $postData['apikey'] = env('NCPAY_PUBLIC_KEY');
            $postData['timestamp'] = date('U');
            $user = rand(10000, 99999) . rand(10000, 99999);
            // 取簽名
            $sign = sha1($user . json_encode($postData) . env('NCPAY_PRIVATE_KEY'));
            $url = env('NCPAY_URL') . 'V1/Merchant/DepositList?user=' . $user . '&sign=' . $sign;
            echo '-range-' .  $postData['start'] . ' ~ ' . $postData['stop'] . ' -page-' . $postData['page'];
            $records = json_decode($this->vendorSend->send('ncpay_check', $url,'POST', $postData, ['Content-Type:application/json']), true);
            if (is_array($records)) {
                foreach ($records['data'] as $row) {
                    // echo '*********' . $row['thirdpartyid'] . ' - ' . $row['status'] . '*********';
                    $order_id = substr($row['thirdpartyid'], -7);
                    $order_data = [];
                    $data = [
                        'ncpay_status' => $row['status'],
                        'ncpay_updated' => $row['updated'],
                        'ncpay_created' => $row['created']
                    ];
                    if ($row['status'] == 'success') {
                        $data['status'] = 3;
                        $order_status = 1;
                    } else if ($row['status'] == 'pending') {
                        $data['status'] = 1;
                        $order_status = 0;
                    } else if ($row['status'] == 'processing') {
                        $data['status'] = 2;
                        $order_status = 0;
                    } else if ($row['status'] == 'fail') {
                        $data['status'] = 4;
                        $order_status = 2;
                    } else if ($row['status'] == 'cancel') {
                        $data['status'] = 5;
                        $order_status = 2;
                    }
                    $ncpay = Ncpay::where('ncpay_order_id', $row['orderid'])->first();
                    if (is_object($ncpay)) {
                        $ncpay_old_statu = $ncpay->status;
                        //變更ncpay狀態
                        $result = $ncpay->update($data);
                        $order = Order::where('id', $order_id)->first();
                        //訂單狀態變更為fail時發送推播
                        if ($result && $ncpay_old_statu != $data['status'] && $data['status'] == 4) {
                            echo '--------' . $row['thirdpartyid'] . ' - ' . $row['status'] . '-------';
                            //新增推播
                            $message = [
                                'member_id' => $order->member_id,
                                'title' => '系統訊息',
                                'content' => 'NcPay儲值失敗',
                                'url' => env('PUSH_URL') . 'RecordList'
                            ];
                            FirebaseCloudMessage::create($message);
                        } else if ($result && $order_status == 1 && $order['status'] != 1) {
                            //訂單成功更新訂單, 訂單狀態尚未更新過的
                            echo '*********' . $row['thirdpartyid'] . ' - ' . $row['status'] . '*********';
                            // 新增點數
                            $member = Member::where('id', $order->member_id)->first();
                            if (is_object($member)) {
                                $before_balance = $member->balance;
                                $balance = ($member->balance + $order->point);
                                $order->update([
                                    'status' => 1,
                                    'balance' => $balance
                                ]);
                                $member->update([
                                    'balance' => DB::raw('balance+' . $order->point)
                                ]);
                                // 刪除Redis中相關的資料
                                $redis = new REDIS();
                                $redis->delete(array(
                                    'member'
                                ));
                                // 寫入log
                                $log = array();
                                $log['agent_id'] = $member->parent;
                                $log['member_id'] = $order->member_id;
                                $log['order_id'] = $order_id;
                                $log['point'] = $order->point;
                                $log['before_balance'] = $before_balance;
                                $log['balance'] = $balance;
                                $log['type'] = 3;
                                $log['status'] = 1000;
                                $transaction_log = TransactionLog::create($log);
                                //新增推播
                                $message = [
                                    'member_id' => $order->member_id,
                                    'title' => '系統訊息',
                                    'content' => 'NcPay儲值成功',
                                    'url' => env('PUSH_URL') . 'RecordList'
                                ];

                                //新增稽核流水
                                $flow_limit = array();
                                $flow_limit['member_id'] = $order->member_id;
                                $flow_limit['type'] = 3;  //訂單儲值
                                $flow_limit['reference_id'] = isset($transaction_log)? $transaction_log->id : null;
                                $flow_limit['flow_limit'] = $order->point;
                                $flow_limit['admin'] = 0;
                                FlowLimit::create($flow_limit);

                                FirebaseCloudMessage::create($message);
                            } else {
                                Common::log(0, 'Member not exist', 'check_ncpay');
                            }
                        }
                    }
                }
                //總頁數超過目前頁數要再call一次
                if ($records['totalpage'] > $postData['page']) {
                    $postData['page'] = $postData['page'] + 1;
                    $this->checkResult($postData);
                }
            } else {
                echo 'fail';
                Common::log(0, 'fail', 'check_ncpay');
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
    private function post($url, $data)
    {
        $data['apikey'] = env('NCPAY_PUBLIC_KEY');
        $data['timestamp'] = date('U');
        $user = rand(10000, 99999) . rand(10000, 99999);
        // 取簽名
        $sign = sha1($user . json_encode($data) . env('NCPAY_PRIVATE_KEY'));

        Common::log(0, $data, 'check_ncpay');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('NCPAY_URL') . $url . '?user=' . $user . '&sign=' . $sign);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, $result, 'check_ncpay');
        return json_decode($result, true);
    }
    */
}