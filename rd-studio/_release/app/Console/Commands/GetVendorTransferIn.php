<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\Vendor;
use DB;

class GetVendorTransferIn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get_vendor_transfer_in {--vendor=} {--vendor_info_id=} {--token=}';
    //先在代理后台登入, 取得token再來執行
    //php artisan get_vendor_transfer_in --vendor=22 --vendor_info_id=74 --token

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '遊戲商玩家餘額轉入';

    protected $vendor_send;
    protected $vendor_id;
    protected $vendor_info_id;
    protected $token;
    protected $action = 'vendor_transfer_in';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->vendor_send = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->option('vendor')) {
            echo 'please input option --vendor';
            return;
        }
        if (!$this->option('vendor_info_id')) {
            echo 'please input option --vendor_info_id';
            return;
        }
        $this->vendor_id = $this->option('vendor');
        $this->vendor_info_id = $this->option('vendor_info_id');
        $this->token = [REDACTED]'token');

        $vendor = Vendor::where('id', $this->vendor_id)->first();
        echo $vendor->name . ' ' . $this->description . PHP_EOL;

        $main = null;
        $account_name = 'username';
        switch ($this->vendor_id) {
            case 22: //vpower
                $main = DB::table('vpower_accounts as c');
                break;
            case 40: //Menang88
                $main = DB::table('menang88_accounts as c');
                break;
            default:
                return 'fail';
        }
        $accounts = $main->join('members as m', function ($join) {
            $join->on('m.id', 'c.member_id');
        })
            ->where('c.vendor_info_id', $this->vendor_info_id)
            ->select(
                'm.username',
                DB::raw('c.' . $account_name . ' as account')
            )
            ->get();

        echo '本次預計轉帳人數:' . count($accounts) . PHP_EOL;
        $success = 0;
        $fail = 0;
        $fail_account = '';
        foreach ($accounts as $key => $row) {
            echo $this->vendor_id . ' ' . $this->vendor_info_id . ' ' . $row->account . '-->';
            $result = $this->transferIn($this->vendor_id, $this->vendor_info_id, $row->account, $this->token);
            if (is_array($result) && isset($result['code']) && $result['code'] == 1000) {
                $success++;
                echo 'success ' . json_encode($result);
            } else {
                $fail++;
                $fail_account .= $row->username . '(' .$row->account . '),';
                echo 'fail ' . json_encode($result);
            }
            echo PHP_EOL;
        }
        echo '----------------------------------------' . PHP_EOL;
        echo '本次轉帳成功人數:' . $success . PHP_EOL;
        echo '本次轉帳失敗人數:' . $fail . PHP_EOL;
        echo '失敗帳號:' . $fail . PHP_EOL;
    }

    private function getBalance($vendor_id, $vendor_info_id, $account, $token)
    {
        //call api_external >> api_internal(因為不允許外部server連接), rd-studio-01不在k8s裡, 所以算外部server
        $url = env('API_EXTERNAL_URL') . 'api/rd/balance';
        $data = array();
        $data['vendor_id'] = $vendor_id;
        $data['vendor_info_id'] = $vendor_info_id;
        $data['account'] = $account;
        $headers = array(
            'Authorization: Bearer ' . $token,
        );
        $result = $this->vendor_send->send('rd_vendor_balance', $url, 'POST', $data, $headers);
        return json_decode($result, true);
    }

    private function transferIn($vendor_id, $vendor_info_id, $account, $token)
    {
        //call api_external >> api_internal(因為不允許外部server連接), rd-studio-01不在k8s裡, 所以算外部server
        $url = env('API_EXTERNAL_URL') . 'api/rd/transfer_in';
        $data = array();
        $data['vendor_id'] = $vendor_id;
        $data['vendor_info_id'] = $vendor_info_id;
        $data['account'] = $account;
        $headers = array(
            'Authorization: Bearer ' . $token,
        );
        $result = $this->vendor_send->send('rd_vendor_transfer_in', $url, 'POST', $data, $headers);
        return json_decode($result, true);
    }
}
