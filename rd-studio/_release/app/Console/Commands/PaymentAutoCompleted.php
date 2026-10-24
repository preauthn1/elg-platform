<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use Illuminate\Support\Facades\Storage;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Models\WithdrawalOrder;
use App\ThirdPartyApi\Models\ThirdPartyApiPayoutResult;
use App\ThirdPartyApi\ATPayIntegrationHelper;
use App\ThirdPartyApi\GoldPayIntegrationHelper;
use App\ThirdPartyApi\AllPayIntegrationHelper;
use App\Services\TelegramService;
use DB;

class PaymentAutoCompleted extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_auto_completed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '代付自動完成程序';
    protected $tg_service;
    protected $operator = 52;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tg_service = New TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        echo $this->description . PHP_EOL;
        try {
            $send_num = 0;

            //組別設定setting
            $sub = DB::table('agent_customized_settings')
                ->whereRaw(
                    'setting_key in (' .
                    AgentCustomizedSettingKeyEnum::PAYMENT_AUTO_COMPLETED . ', ' .
                    AgentCustomizedSettingKeyEnum::PAYMENT_AUTO_COMPLETED_AMOUNT . ')'
                )
                ->select(
                    'agent_group_id',
                    DB::raw('CASE WHEN setting_key = 6006 THEN value ELSE 0 END AS auto_payment_status'),
                    DB::raw('CASE WHEN setting_key = 6007 THEN value ELSE 0 END AS auto_payment_amount')
                );
            $model = DB::table(DB::raw("({$sub->toSql()}) AS z"))
                ->mergeBindings($sub)
                ->groupBy('z.agent_group_id')
                ->select(
                    'z.agent_group_id',
                    DB::raw('MAX(auto_payment_status) AS auto_payment_status'),
                    DB::raw('MAX(auto_payment_amount) AS auto_payment_amount')
                );

            //找出可自動代付資料
            $withdraw_data = DB::table('withdrawal_orders as wo')
                ->join('members as m', 'm.id', 'wo.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->join(DB::raw("({$model->toSql()}) AS acs"), function ($join) {
                    $join->on('acs.agent_group_id', 'a.customized_setting_group_id')
                        ->where('acs.auto_payment_status', 1);
                })
                ->mergeBindings($model)
                ->whereIn('wo.status', [1, 3])
                ->whereRaw('wo.amount <= acs.auto_payment_amount')
                ->orderBy('m.parent')
                ->select(
                    'wo.id',
                    'm.username as member_username',
                    'm.parent as agent_id',
                    'm.id as member_id',
                    'wo.bank_name',
                    'wo.bank_code',
                    'wo.account_name',
                    'wo.account_no',
                    'wo.amount'
                )
                ->get();
            echo '預計代付筆數: ' . count($withdraw_data) . PHP_EOL;
            if (count($withdraw_data) > 0) {
                foreach ($withdraw_data as $row) {
                    $order_id = $row->id;
                    $agent_id = $row->agent_id;
                    $member_username = $row->member_username;
                    $member_id = $row->member_id;
                    $amount = $row->amount;
                    $bank_name = $row->bank_name;
                    $bank_code = $row->bank_code;
                    $account_name = $row->account_name;
                    $account_no = $row->account_no;

                    //找出現有代付廠商
                    $queryResult = DB::table('agents as a')
                        ->join('agent_cash_flows as acf', function ($join) {
                            $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                                ->where('acf.status', 1);
                        })
                        ->join('cash_flows as c', function ($join) {
                            $join->on('acf.cash_flow_id', 'c.id')
                                ->where('c.status', 1)
                                ->where('c.type', 3);
                        })
                        ->where('a.id', $agent_id)
                        ->whereRaw("JSON_EXTRACT(example.invalid, '$.min_amount') <= " . $amount)
                        ->whereRaw("JSON_EXTRACT(example.invalid, '$.max_amount') >= " . $amount)
                        ->select(
                            'c.id as cash_flow_id',
                            'acf.id as agent_cash_flow_id',
                            'c.name',
                            'a.username as agent_username',
                            DB::raw("JSON_EXTRACT(example.invalid, '$.min_amount') as min_amount"),
                            DB::raw("JSON_EXTRACT(example.invalid, '$.max_amount') as max_amount"),
                            DB::raw("JSON_EXTRACT(example.invalid, '$.available_banks') as available_banks"),
                            'example.invalid'
                        )
                        ->first();
                    if (is_object($queryResult)) {
                        $available_banks = json_decode($queryResult->available_banks, false);
                        foreach($available_banks as $obj) {
                            if ($bank_code == $obj->BankCode_ELG) {
                                echo $order_id . PHP_EOL;
                                $cash_flow_id = $queryResult->cash_flow_id;

                                //先將訂單 狀態更新為 準備請求三方
                                $result = $this->UpdateOrderStatusToWaitingRequest($order_id);
                                $original_status = $result['original_status'];
                                $original_check_time = $result['original_check_time'];
                                $original_memo = $result['original_memo'];
                                $original_change_logs = $result['original_change_logs'];
                                if ($result['is_success'] == true) {
                                    //請務必每一支call都實作返回這種格式的物件
                                    $payoutResult = new ThirdPartyApiPayoutResult();
                                    $isNotImplement = false;
                                    switch ($cash_flow_id) {
                                        case 11:
                                            $payoutResult = ATPayIntegrationHelper::RequestWithdrawalOrder($order_id);
                                            break;
                                        case 14:
                                            $payoutResult = GoldPayIntegrationHelper::RequestWithdrawalOrder($order_id);
                                            break;
                                        case 23:
                                            $payoutResult = AllPayIntegrationHelper::RequestWithdrawalOrder($order_id);
                                            break;
                                        default:
                                            $isNotImplement = true;
                                            break;
                                    }

                                    if ($isNotImplement || !$payoutResult->is_success) {
                                        $this->UpdateOrderStatusToReviewConfirm(
                                            $row->id,
                                            $original_status,
                                            $original_check_time,
                                            $original_memo,
                                            $original_change_logs
                                        );
                                    } else {
                                        //將狀態更新為 三方處理中
                                        $this->UpdateOrderStatusToThirdPartyOnProcess(
                                            $order_id,
                                            $payoutResult->third_party_order_no,
                                            $payoutResult->third_party_response
                                        );

                                        $content = PHP_EOL .
                                            "【Auto Payment】 " . $queryResult->name . PHP_EOL .
                                            "[order_id] ". $order_id . PHP_EOL .
                                            "[agent] " . $queryResult->agent_username . " (" . $agent_id . ")" . PHP_EOL .
                                            "[member] " . $member_username . " (" . $member_id . ")" . PHP_EOL .
                                            "[bank] " . $bank_name . " (" . $bank_code . ")" . PHP_EOL .
                                            "[account] " . $account_name . PHP_EOL .
                                            "[account_no] " . $account_no . PHP_EOL .
                                            "[amount] " . $amount . PHP_EOL;
                                        echo $content;
                                        $this->tg_service->sendMessage($content, 'payment', '', true);
                                        $send_num++;
                                    }
                                }
                                break;
                            }
                        }
                        echo PHP_EOL;
                    }
                    usleep(10000);
                }
            }
            echo '實際提交筆數: ' . $send_num . PHP_EOL;
        } catch (\Exception $exception) {
            echo 'Exception' . PHP_EOL;
            print_r($exception);
        }
    }


    private function UpdateOrderStatusToWaitingRequest($target_order_id)
    {
        $availableStatus = [1, 3, 29];

        DB::beginTransaction();

        $queryExist = WithdrawalOrder::where('id', $target_order_id)
            ->whereIn('status', $availableStatus)
            ->first();

        if (!is_object($queryExist)) {
            DB::rollback();
            return
                [
                    'original_status' => 0,
                    'is_success' => false
                ];
        }

        $original_status = $queryExist->status;
        $original_check_time = $queryExist->check_time;
        $original_memo = $queryExist->memo;
        $original_change_logs = $queryExist->change_logs;

        $data = array();
        $data['status'] = 21;
        $data['operator'] = $this->operator;
        if ($original_status == 1) {
            $data['check_time'] = DB::raw('now()');
            $data['memo'] = 'System: Payment auto completed';
            $data['change_logs'] = ' from ' . $original_status . ' to 3 by ' . $this->operator;
        }

        //狀態 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳 21:代付提交中 22:代付處理中 24:三方成功  29:三方失敗
        //將狀態更新為代付提交中(尚未打API，先鎖定此筆單、避免重複提交)
        $queryExist->update($data);
        DB::commit();

        return [
            'original_status' => $original_status,
            'original_check_time' => $original_check_time,
            'original_memo' => $original_memo,
            'original_change_logs' => $original_change_logs,
            'is_success' => true
        ];
    }

    private function UpdateOrderStatusToReviewConfirm(
        $target_order_id, $original_status,
        $original_check_time,
        $original_memo,
        $original_change_logs
    ) {
        $queryExist = WithdrawalOrder::where('id', $target_order_id)->first();

        if (is_object($queryExist)) {
            //將狀態還原回已審核
            $queryExist->update(
                [
                    'status' => $original_status,
                    'check_time' => $original_check_time,
                    'memo' => $original_memo,
                    'change_logs' => $original_change_logs
                ]);
        }
    }

    private function UpdateOrderStatusToThirdPartyOnProcess($target_order_id, $third_party_order_no, $third_party_response)
    {
        $queryExist = WithdrawalOrder::where('id', $target_order_id)->first();

        if (is_object($queryExist)) {
            //將狀態更新為代付處理中
            $queryExist->update(
                [
                    'third_party_response' =>  $third_party_response,
                    'third_party_order_no' => $third_party_order_no,
                    'status' => 22,   //代付處理中
                    'operator' => $this->operator
                ]);
        }
    }
}
