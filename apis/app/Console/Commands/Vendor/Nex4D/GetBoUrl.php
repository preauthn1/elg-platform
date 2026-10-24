<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\Nex4D;

use App\Models\VendorInfo;
use App\Services\VendorService;
use App\Vendors\Nex4DSW\Driver as Nex4DDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * 匯出 dev 環境的遊戲列表到 JSON 文件
 * 再到 prod 環境匯入
 */
class GetBoUrl extends Command
{
    protected $signature = 'nex4d:get_bo_url {vendorInfoId?} ';

    protected $description = '取得登入 Nex 4D 後台的 URL';

    private int $vendorId = 179;

    public function handle(): void
    {
        $vendorInfoId = $this->argument('vendorInfoId');

        if ($vendorInfoId) {
            $vendorInfo = VendorInfo::query()
                ->where('id', $vendorInfoId)
                ->first();
        } else {
            $vendorInfo = VendorInfo::query()
                ->where('vendor_id', $this->vendorId)
                ->first();
        }

        if (!$vendorInfo) {
            $this->error('找不到 Nex 4D 對應的 VendorInfo');

            return;
        }

        $vendorService = App::make(VendorService::class);
        /** @var Nex4DDriver $vendorDriver */
        $vendorDriver = $vendorService->getDriver($this->vendorId);
        $vendorDriver->setVendorInfo($vendorInfo);

        $boUrl = $vendorDriver->getBoUrl();

        $this->info('後台網址: ' . $boUrl);
    }
}
