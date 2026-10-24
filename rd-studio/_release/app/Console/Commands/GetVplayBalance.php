<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\VplayBetRecord;
use App\Models\VplayAccount;
use App\Models\Member;
use App\Models\TransactionLog;
use App\REDIS;
use DB;

class GetVplayBalance extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vplay_balance {--size=} {--account=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取得vplay錢包紀錄';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendor_id;
    protected $vendor_send;
    protected $api_url;
    protected $platform;
    protected $version;
    protected $username_suffix;

    protected $account_prefix;
    protected $currency;
    protected $rate;
    protected $transfer_min_amount;
    protected $cost;
    protected $transfer_type;

    public function __construct()
    {
        parent::__construct();
        $this->vendor_id = 8;
        $this->transfer_type = 2;
        $this->vendor_send = new VendorSend(['json_post' => true]);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            echo $this->description . PHP_EOL;
            $size = $this->option('size')
                ? $this->option('size')
                : 1000;
            $account = $this->option('account')
                ? $this->option('account')
                : '';

            $setting = VendorInfo::where('vendor_id', $this->vendor_id)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($setting as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->SECRET, $used)) {
                    $this->api_url = $info->API_URL;
                    $this->platform = $info->PLATFORM;
                    $this->version = $info->VERSION;
                    $this->username_suffix = $info->USERNAME_SUFFIX;
                    $this->currency = $info->CURRENCY;

                    $this->account_prefix = $row->account_prefix;
                    $this->rate = $row->rate;
                    $this->transfer_min_amount = $row->transfer_min_amount;
                    $this->cost = $row->cost;
                    if (false) {
                        echo '<INFO>' . PHP_EOL;
                        echo 'api_url: ' . $this->api_url . PHP_EOL;
                        echo 'platform: ' . $this->platform . PHP_EOL;
                        echo 'version: ' . $this->version . PHP_EOL;
                        echo 'username_suffix: ' . $this->username_suffix . PHP_EOL;
                        echo 'currency: ' . $this->currency . PHP_EOL;
                        echo PHP_EOL . '<OTHER>' . PHP_EOL;
                        echo 'vendor_id: ' . $this->vendor_id . PHP_EOL;
                        echo 'account_prefix: ' . $this->account_prefix . PHP_EOL;
                        echo 'rate: ' . $this->rate . PHP_EOL;
                        echo 'transfer_min_amount: ' . $this->transfer_min_amount . PHP_EOL;
                        echo 'cost: ' . $this->cost . PHP_EOL;
                    }

                    $this->getMember($size, $account);
                    $used[] = $info->SECRET;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function getMember($size, $account = '')
    {
        try {
            $page = 1;
            $per_page = $size;

            $data = DB::table('vplay_accounts as c')
                ->join('members as m', function ($join) {
                    $join->on('m.id', 'c.member_id')
                        ->where('m.status', 1);
                })
                ->where('c.member_id', '>', 0);
            if ($account != '') {
                $data = $data->where('c.username', $account);
            } else {
                $data = $data->where('c.username', 'like', 'pro%')
                    #->where('c.balance', '>=', 1);
                    #->where('c.balance', '>=', 0.1);
                    ->where('c.balance', '>=', 0.01);
            }
            $data = $data->select(
                    'm.id as member_id',
                    'm.username',
                    'c.id',
                    'c.username as account',
                    'c.balance'
                )
                ->skip(($page - 1) * $per_page)
                ->take($per_page)
                ->get();
            foreach ($data as $row) {
                $vendor_balance = $this->getBalanceByVendorId($row->account);
                echo ' account: ' . $row->account . ' vendor_balance: ' . $vendor_balance;

                if ($vendor_balance > 0) {
                    //確認餘額是否大於遊戲商轉出入金額
                    if ($this->canVendorPointTransfer($this->vendor_id, $vendor_balance)) {
                        $point = floor(strval($vendor_balance * 10000 / $this->rate)) / 10000; //轉換比例後保留小數四位

                        //如果不足最小轉帳金額就不轉
                        if ($this->getTransferMinAmount($point) != 0) {
                            $result = $this->balanceTransaction(
                                $row->member_id,
                                $this->vendor_id,
                                $row->account,
                                $this->transfer_type,   //類型; 1:轉出, 2:轉入
                                $vendor_balance,        //三方轉出金額
                                $point                  //我方寫入金額
                            );
                        } else {
                            echo ' 不足最小轉帳';
                        }
                    } else {
                        echo ' 小於遊戲商轉出入金額';
                    }
                } else {
                    echo ' Balance小於0';
                }

                echo ' **' . PHP_EOL;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo ' QueryException' . $info[2] . PHP_EOL;
        } catch (\Exception $e) {
            echo ' Exception' . $e->getMessage() . PHP_EOL;
        }
    }

    private function getBalanceByVendorId($account)
    {
        $url = 'MobileApp/GetBalance.ashx';
        $data = array();
        $data['Account'] = $account . $this->username_suffix;
        $result = $this->vplay_post($url, $data);
        $balance = 0;
        if (is_array($result) && $result['ResultCode'] == 1) {
            if ($result['Point'] > 0) {
                if ($result['Point'] < 0.0001) {
                    //$balance = -1;
                } else {
                    $balance = $result['Point'];
                }
            //} else {
                //$balance = -2;
            }
            //$account = VplayAccount::where('id', $row->id)->first();
            //$account->balance = $balance;
            //$account->save();
        //} else {
            //echo $row->username . $row->account . ' ERROR' . PHP_EOL;
        }
        return $balance;
    }

    private function transfer($account, $type, $vendor_point)
    {
        $url = 'MobileApp/TransferCredit.ashx';
        $data = array();
        $data['Account'] = $account . $this->username_suffix;
        $data['KeyInOutType'] = $type;
        $data['Credit'] = $vendor_point;
        $data['CurrencyName'] = $this->currency;
        $result = $this->vplay_post($url, $data);
        if (is_array($result) && $result['ResultCode'] == 1) {
            echo ':true';
            // 轉帳成功取餘額
            return true;
        } else {
            echo 'transfer fail' . PHP_EOL;
            print_r($result);
            echo PHP_EOL;
            // 轉帳失敗
            return $result['ResultMsg'];
        }
    }

    private function balanceTransaction($member_id, $vendor_id, $account, $transfer_type, $vendor_point, $point)
    {
        try {
            echo '  balanceTransaction: ';
            DB::beginTransaction();
            $member = Member::lockForUpdate()->find($member_id);
            $beforeBalance = $member->balance;

            // 寫入轉點LOG
            $log = array();
            $log['agent_id'] = 0;
            $log['member_id'] = $member_id;
            $log['vendor_id'] = $vendor_id;
            $log['type'] = $transfer_type;
            $log['point'] = $point;
            $log['vendor_point'] = 0;
            $log['rate'] = $this->rate;
            $log['before_balance'] = $beforeBalance;
            $log['balance'] = 0;
            $log['vendor_balance'] = 0;
            $log['status'] = 0;
            $log['remark'] = 'system transfer';
            $log['admin'] = $member_id;
            // 寫入轉點歷程
            $transaction_log = $this->insertLog($log);

            // 轉出
            $rs = $this->transfer($account, $transfer_type, $vendor_point);
            if ($rs === true) {
                // 加入平台餘額
                $data = array(
                    'balance' => 0,
                    'vendor_balance' => 0
                );
                $data['balance'] = ($beforeBalance + $point);
                if ($member->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'member'
                    ));
                    // 更新轉點記錄
                    $log = array();
                    $log['agent_id'] = $member->parent;
                    $log['vendor_point'] = $vendor_point;
                    $log['rate'] = $this->rate;
                    $log['balance'] = $data['balance'];
                    $log['vendor_balance'] = $data['vendor_balance'];
                    $log['status'] = 1000;
                    $log['result'] = json_encode($data);
                    $log['checksum'] = $this->getTransactionLogChecksum(
                        $member_id, $transaction_log->transaction_no, $vendor_id, $transfer_type,
                        $point, $log['balance'], $log['status'], $log['result'], ''
                    );
                    $update_result = $transaction_log->update($log);
                    echo ' Success';

                    $vplay_account = VplayAccount::where('username', $account)->first();
                    $vplay_account->balance = 0;
                    $vplay_account->save();
                    DB::commit();
                } else {
                    echo ' rollback 1';
                    DB::rollback();
                }
            } else {
                echo ' rollback 2';
                DB::rollback();
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            echo ' QueryException:' . $info[2];
        } catch (\Exception $e) {
            DB::rollback();
            echo ' Exception:' . $e->getMessage();
        }
    }


    //最小轉帳金額
    private function getTransferMinAmount($point)
    {
        $point = strval($point * 10000);
        $transfer_min_amount = strval($this->transfer_min_amount * 10000);
        return strval(($point - ($point % $transfer_min_amount)) / 10000);
    }

    // 檢查遊戲商最小可轉額度以及現在是否關閉轉點
    private function canVendorPointTransfer($vendor_id, $point)
    {
        if ($vendor_id == 4 && $point < 0.1) { // 豪龍小於 0.1 不轉
            return false;
        } elseif ($point < 0.01) {
            // 其他小於 0.01 不轉
            return false;
        }
        return true;
    }

    private function insertLog($data)
    {
        // 寫入轉點LOG
        $log = array();
        $log['transaction_no'] = $this->getTransactionNo();
        $log['agent_id'] = $data['agent_id'];
        $log['member_id'] = $data['member_id'];
        $log['vendor_id'] = isset($data['vendor_id']) ? $data['vendor_id'] : 0;
        $log['type'] = $data['type'];
        $log['point'] = $data['point'];
        $log['tax'] = isset($data['tax']) ? $data['tax'] : 0;
        $log['vendor_point'] = isset($data['vendor_point']) ? $data['vendor_point'] : 0;
        $log['rate'] = isset($data['rate']) ? $data['rate'] : 0;
        $log['before_balance'] = $data['before_balance'];
        $log['balance'] = $data['balance'];
        $log['vendor_balance'] = isset($data['vendor_balance']) ? $data['vendor_balance'] : 0;
        $log['status'] = $data['status'];
        $log['remark'] = isset($data['remark']) ? $data['remark'] : '';
        $log['result'] = isset($data['result']) ? $data['result'] : '';
        $log['operator'] =  isset($data['operator']) ? $data['operator'] : '';
        $log['admin'] = $data['member_id'];
        $log['checksum'] = $this->getTransactionLogChecksum(
            $log['member_id'],
            $log['transaction_no'],
            $log['vendor_id'],
            $log['type'],
            $log['point'],
            $log['balance'],
            $log['status'],
            $log['remark'],
            $log['result']
        );
        return TransactionLog::create($log);
    }

    private function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = TransactionLog::whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)
            ->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getTransactionNo();
        } else {
            return $number;
        }
    }

    private function getTransactionLogChecksum($member_id, $transactionNo, $vendorId, $type, $point, $balance, $status, $remark, $result)
    {
        return md5($member_id . $transactionNo . $vendorId . $type . $point . $balance . $status . $remark . $result);
    }

    private function vplay_post($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $url . '?platform=' . $this->platform . '&timestamp=' . date('U') . '&ver=' . $this->version . '&language=zh_CN');
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true);
    }

}