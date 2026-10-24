<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorPost;
use App\Services\TelegramService;
use App\Models\VendorInfo;
use App\SmsInfobipApi;

class WarningGreatWallAdminBalance extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'great_wall_admin_balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '檢查gw99餘額';

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
        try {
            $url = 'Api/IGame/Admin/Credit';
            $settings = VendorInfo::where('vendor_id', 5)->where('status', 1)->get();

            foreach ($settings as $row) {
                //將遊戲商資訊解密
                $info = Common::decryptVendorInfo($row);
                if (is_object($info)) {
                    $result = VendorPost::GreatWallPost($info, $url, ['Admin' => $info->API_AGENT]);
                    if (is_array($result) && isset($result['data'])) {
                        if ($result['data']['CreditAmount'] < 20000) {
                            $content = '【GW99餘額不足】 ' . $info->API_AGENT . '目前餘額為：' . $result['data']['CreditAmount'];
                            $this->tgService->sendMessage($content, 'info');
                            echo $content . PHP_EOL;
                        }
                    }
                } else {
                    echo $row->name . '解密失敗' . PHP_EOL;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }
}