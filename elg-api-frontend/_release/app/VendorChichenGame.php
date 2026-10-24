<?php

namespace App;

use DB;
use App\Helpers\Common;

class VendorChichenGame extends Vendor
{
    public function __construct($info)
    {
        $this->name = 'Chichen Game';
        $this->vendor_id = 7;
        $this->rate = isset($info->rate) ? $info->rate : 1;
        $this->transfer_min_amount = $info->transfer_min_amount;
    }

    /**
     * -取帳號資料
     * }
     */
    public function getGameUrl($username, $password)
    {
        try {
            return 'http://0.0.0.0/H5/pages/index.html?username=' . $username . '&password=' . $password . '&txtLang=1';
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
}