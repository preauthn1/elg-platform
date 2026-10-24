<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Vendor;
use App\Models\VendorInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan tool:insert_vendor_info
 */
class InsertVendorInfo extends Command
{
    protected $signature = 'vendor:insert_vendor_info {vendorId?}';

    protected $description = '新增 vendor info';

    protected int $vendorId;

    protected string $vendorInfoCurrency;

    protected int $vendorInfoType; // 遊戲商環境，0:測試，1:正式

    protected string $accountPrefix;

    protected array $currencyOptions = [
        'AUD',
        'CNY',
        'HKD',
        'IDR',
        'MSCP',
        'MYR',
        'PHP',
        'THB',
        'USD',
        'USDT',
        'VND',
    ];

    protected array $typeOptions = [
        0 => '測試',
        1 => '正式',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $vendorId = $this->argument('vendorId');
        if (!$vendorId) {
            $vendorId = $this->ask('請輸入遊戲商ID');
        }
        $this->vendorId = (int) $vendorId;
        $this->vendorInfoCurrency = $this->choice('請選擇幣別', $this->currencyOptions, 'MYR');
        $vendorInfoType = $this->choice('請選擇環境', $this->typeOptions, 0);
        $this->vendorInfoType = (int) array_search($vendorInfoType, $this->typeOptions);
        $this->accountPrefix = $this->ask('請輸入帳號前綴');

        DB::transaction(function () {
            $vendor = Vendor::query()
                ->where('id', $this->vendorId)
                ->first();

            if (!$vendor) {
                $this->error('vendor 不存在, vendor_id:' . $this->vendorId);

                return;
            }

            $this->createVendorInfo($vendor);
        });

        $this->info('執行完成');
    }

    protected function createVendorInfo(Vendor $vendor): ?VendorInfo
    {
        $vendorInfo = VendorInfo::query()
            ->where('vendor_id', $this->vendorId)
            ->first();

        if ($vendorInfo) {
            $this->error(sprintf('vendor_id: %s, 已存在 vendor_info_id: %s', $this->vendorId, $vendorInfo->id));

            return $vendorInfo;
        }

        // info格式
        $info = [
            'API_URL' => '',
        ];

        $vendorInfo = VendorInfo::create([
            'name' => $vendor->name,
            'type' => $this->vendorInfoType,
            'vendor_id' => $vendor->id,
            'currency' => $this->vendorInfoCurrency,
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => $info,
            'prepared_account_number' => 1,
            'account_prefix' => $this->accountPrefix,
            'cost' => 0,
            'status' => 1,
            'rolling_chip_status' => 0,
            'admin' => 0,
        ]);

        $this->line(sprintf('vendor_id: %s, 新增 vendor info id: %s', $vendor->id, $vendorInfo->id));

        return $vendorInfo;
    }
}
