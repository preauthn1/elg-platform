<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Services\TelegramService;
use App\Models\VendorRequestFail;
use App\Models\VendorMaintenance;
use App\Models\Agent;
use App\SmsApi;
use DB;

class VendorDetect extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vendor_detect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢測遊戲商是否可取餘額';

    protected $tgService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->tgService = New TelegramService();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Common::log(0, 'start detect', 'vendor_detect');

        $vendor_detects = json_decode(env('VENDOR_DETECT'), false) ? : [];
        $agent_id = 0;
        foreach($vendor_detects as $vendor_detect) {
            echo $vendor_detect->account . PHP_EOL;
            $agent = DB::table('members as m')
               ->join('agents as a', 'a.id', 'm.parent')
                ->where('m.username', $vendor_detect->account)
                ->select('a.*')
                ->first();

            $domain = $this->getDomain($agent);

            $agent_vendor = DB::table('agent_vendors')
                ->where('agent_id', $agent->id)
                ->where('status', 1)
                ->orderBy('vendor_id', 'ASC')
                ->pluck('vendor_id')
                ->toArray();
            //先抓取所有暫停轉點的遊戲商
            $vendors = DB::table('vendor_request_fails as vrf')
                ->join('vendors as v', 'v.id', 'vrf.vendor_id')
                ->where('vrf.status', '<', 2)
                ->where('vrf.general_agent_id', $agent->id)
                ->select('vrf.id',
                    'vrf.vendor_id',
                    'v.name',
                    DB::raw('MAX(vrf.status) as status'),
                    'vrf.created_at'
                )
                ->groupBy('vrf.vendor_id')
                ->get();

            if (count($vendors) > 0) {
                //確認是否能取到餘額，直接打前端api，這樣才不用有新增就要加
                //檢查帳號登入
                $info = [
                    'username' => $vendor_detect->account,
                    'password' => $vendor_detect->password,
                    'domain' => $domain->domain
                ];
                $login = $this->post('login', $info);
                if (is_array($login) && $login['code'] == 1000 && isset($login['data']['token'])) {
                    $token = [REDACTED]'data']['token'];
                    $message = '';
                    //檢查帳號取餘額
                    foreach ($vendors as $row) {
                        if (in_array($row->vendor_id, $agent_vendor)) {
                            echo 'vendor:' . $row->vendor_id . PHP_EOL;
                            $balance = $this->post('getVendorBalance', ['vendor_id' => $row->vendor_id], $token);

                            //查看時間內有沒有設定維護資訊
                            $now = date('Y/m/d H:i:s');
                            $maintenance = VendorMaintenance::where('vendor_id', $row->vendor_id)
                                ->where('end_time', '>=', $now)
                                ->where('admin', 0)
                                ->where('agent_id', $agent->id)
                                ->first();
                            if (is_array($balance) && $balance['code'] == 1000) {
                                //如果還在維護中就把維護關閉
                                if (is_object($maintenance) && $maintenance->status == 1) {
                                    $maintenance->update(['status' => 0]);
                                }
                                VendorRequestFail::where('vendor_id', $row->vendor_id)
                                    ->where('status', '<', 2)
                                    ->where('general_agent_id', $agent->id)
                                    ->update(['status' => 2]);
                                //成功取到餘額，發簡訊
                                if ($row->status == 1) {
                                    //寄送成功的通知簡訊
                                    $message .= '【' . $agent->username . '-' . $row->name . '】已恢復轉點；' . PHP_EOL;
                                    $message .= '遊戲維護已關閉';
                                }
                            } else {
                                //如果有就開啟沒有就新增
                                if (is_object($maintenance)) {
                                    if ($maintenance->status == 0) {
                                        $maintenance->update(['status' => 1]);
                                    }
                                } else {
                                    $data = array(
                                        'vendor_id' => $row->vendor_id,
                                        'type' => 1,
                                        'start_time' => $now,
                                        'end_time' => date('Y-m-d H:i:s', strtotime($now . '+1hour')),
                                        'agent_id' => $agent->id,
                                        'status' => 1,
                                        'admin' => 0
                                    );
                                    VendorMaintenance::create($data);
                                }
                                //失敗就下次繼續查, 沒發送過訊息的就發訊息
                                if ($row->status == 0) {
                                    //查看之前有沒有寄過簡訊，如果有寄過簡訊就不再寄了
                                    $old_log = VendorRequestFail::where('vendor_id', $row->vendor_id)
                                        ->where('status', 1)
                                        ->where('general_agent_id', $agent->id)
                                        ->get();

                                    if (count($old_log) == 0) {
                                        //寄送失敗的通知簡訊
                                        $message .= '【' . $agent->username . '-' . $row->name . '】無法取得餘額，暫時關閉轉點；' . PHP_EOL;
                                        $message .= '已設定遊戲維護 ' . date('Y-m-d H:i:s', strtotime($now . '+8hour')) . ' 到 ';
                                        $message .= date('Y-m-d H:i:s', strtotime($now . '+9hour'));
                                    }
                                    VendorRequestFail::where('vendor_id', $row->vendor_id)
                                        ->where('status', 0)
                                        ->where('general_agent_id', $agent->id)
                                        ->update(['status' => 1]);
                                }
                            }
                            Common::log(0, 'detect vendor id:' . $row->vendor_id, 'vendor_detect');
                        }
                    }

                    //有更新就發送telegram通知
                    if ($message != '') {
                        $this->tgService->sendMessage($message, 'info', '', true);
                        echo $message . PHP_EOL;
                    }
                } else {
                    $message = "【" . $agent->username . "】" . PHP_EOL;
                    $message .= $vendor_detect->account . "登入失敗 " . PHP_EOL;
                    $message .= "response:" . json_encode($login) . PHP_EOL;
                    $this->tgService->sendMessage($message, 'info', '', true);
                    echo $message . PHP_EOL;
                }
            }
        }

        Common::log(0, 'end detect', 'vendor_detect');
    }

    private function getDomain($agent)
    {
        $domain = DB::table('agent_domains')
            ->where('agent_id', $agent->id)
            ->where('status', 1)
            ->first();
        if (isset($domain->domain)) {
            return $domain;
        } else {
            return $this->getDomain($this->getAgent($agent->parent));
        }
    }

    private function getAgent($agent_id)
    {
        return Agent::where('id', $agent_id)->first();
    }

    private function encrypt($data)
    {
        $cryptText = openssl_encrypt(json_encode($data), "aes-256-cbc", md5(env('API_AES_KEY')), OPENSSL_RAW_DATA, env('API_IV'));
        $params =  base64_encode($cryptText);
        $array = array();
        $array['params'] = $params;
        $array['key'] = md5($params . env('API_MD5_KEY'));
        return $array;
    }

    private function post($url, $data, $token = '')
    {
        $host = env('FRONTEND_API_URL');
        Common::log(0, $data, 'call_frontend_api_' . $url);
        $data = $this->encrypt($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $host . $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        if ($token != '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $token,
            ));
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        Common::log(0, ['request' => $data, 'response' => $result], 'call_frontend_api_' . $url);
        return json_decode($result, true);
    }
}