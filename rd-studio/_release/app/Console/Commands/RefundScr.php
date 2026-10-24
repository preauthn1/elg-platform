<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\ScrAccount;
use App\Models\VendorInfo;
use DB;

class RefundScr extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refund_scr';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '回收舊的918餘額';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            //查詢已轉換的帳號
            //狀態2為轉移完成
            $accounts = ScrAccount::where('status', 2)->where('balance', '>', 0)->get();
            foreach ($accounts as $row) {
                //改密碼
                $result = $this->resetPassword($row->username, $row->player_id, $this->getVendorPassword($row->username, $row->created_at));
                if ($result) {
                    //取餘額
                    $balance = $this->getBalance($row->player_id);
                    if (is_array($balance) && isset($balance['code'])) {
                        $balance = $balance['data'];
                        if ($balance > 0) {
                            //解鎖
                            $unlock = $this->unlock($row->player_id);
                            if ($unlock) {
                                //回收餘額
                                $result = $this->transafer($row->player_id, $balance);
                                if ($result === true) {
                                    //狀態3為回收完畢
                                    $msg = $row->username . '成功回收餘額:' . $balance;
                                    ScrAccount::where('id', $row->id)->update(['balance' => $balance, 'status' => 3]);
                                } else {
                                    $msg = $row->username . '回收餘額失敗';
                                }
                            } else {
                                $msg = $row->username . '解鎖失敗';
                            }
                        } else {
                            ScrAccount::where('id', $row->id)->update(['balance' => $balance, 'status' => 3]);
                            $msg = $row->username . '餘額為0';
                        }
                    } else {
                        $msg = $row->username . '取餘額失敗';
                    }
                } else {
                    $msg = $row->username . '改密碼失敗';
                }
                if ($row->remark == null) {
                    ScrAccount::where('id', $row->id)->update(['remark' => $msg]);
                } else {
                    ScrAccount::where('id', $row->id)->update(['remark' => DB::raw('CONCAT(remark,",","' . $msg . '")')]);
                }
                var_dump($msg);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
    private function getVendorPassword($username, $createTime)
    {
        return substr(md5($username . strtotime($createTime)), 8, 12);
    }

    /**
     * -取餘額
     * }
     */
    public function getBalance($playerId, $passwrod = '')
    {
        try {

            $url = 'player';
            $data = array();
            $data['operation'] = 'getplayerinfo';
            $data['playerid'] = $playerId;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['returncode'] == 0) {
                return ['code' => 1000, 'data' => $result['balance']];
            } else {
                return $result;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * -轉帳
     */
    public function transafer($username, $credit)
    {
        try {
            $url = 'funds';
            // 準備轉帳取轉帳編號
            $data = array();
            $data['operation'] = 'prewithdraw';
            $result = $this->post($url, $data);

            if (is_array($result) && $result['returncode'] == 0) {
                // 執行轉帳
                $data['operation'] = 'withdraw';
                $data['playerid'] = $username;
                $data['amount'] = (string) $credit;
                $data['tid'] = $result['tid'];
                $result = $this->post($url, $data);
                if (is_array($result) && $result['returncode'] == 0) {
                    // 轉帳成功
                    return true;
                } else {
                    // 轉帳失敗
                    return $result['message'];
                }
            } else {
                // 準備轉帳失敗
                return $result['message'];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * -修改密碼
     */
    public function resetPassword($username, $playerId, $newPassword)
    {
        try {
            $url = 'player';
            $data = array();
            $data['operation'] = 'updateplayerinfo';
            $data['playername'] = $username;
            $data['playerid'] = $playerId;
            $data['playerpassword'] = 'Ba2' . $newPassword;
            $data['playertelno'] = '';
            $data['playerdescription'] = '';
            $result = $this->post($url, $data);
            if (is_array($result) && $result['returncode'] == 0) {
                return true;
            } else {
                return false;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * -解除鎖定
     */
    public function unlock($playerId)
    {
        try {
            $url = 'player';
            $data = array();
            $data['operation'] = 'unlock';
            $data['playerid'] = $playerId;
            $result = $this->post($url, $data);

            if (is_array($result) && $result['returncode'] == 0) {
                return true;
            } else {
                return false;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return [
                'code' => $info[0],
                'message' => $info[2]
            ];
        } catch (\Exception $e) {
            return [
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ];
        }
    }

    private function post($url, $data)
    {
        $data['apiuserid'] = env('SCR_API_USER');
        $data['apipassword'] = env('SCR_API_PASSWORD');
        Common::log(0, $data, 'SCR_' . $url);
        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SCR_URL') . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        Common::log(0,  ['request' => $data, 'response' => $result], 'SCR_' . $url);
        curl_close($ch);
        return json_decode($result, true);
    }
}