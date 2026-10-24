<?php

declare(strict_types=1);

namespace App\Console\Commands\Maker;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Support\Str;

class MakeVendorDriver extends MakeCommand
{
    protected $signature = 'make:vendor {name?}';

    protected $description = '建立遊戲商的檔案';

    private array $vendorFileReplacement = [];

    public function handle(): void
    {
        $isSingleWallet = $this->confirm('是否為單錢包？');
        $walletType = $isSingleWallet ? 'SingleWallet' : 'TransferWallet';

        $vendorName = $this->argument('name');
        if (!$vendorName) {
            $vendorName = $this->ask('請輸入遊戲商名稱 (字尾不要加上SW)');
        }

        $vendorName = Str::studly($vendorName); // 轉為 PascalCase
        if ($isSingleWallet) {
            $vendorName .= 'SW'; // 單錢包要加 SW
        }

        $this->createModels($vendorName);
        $this->createVendorFile($vendorName, $walletType);
        $this->updateConfig($vendorName, $walletType);

        $this->info('遊戲商檔案建立完成！');
        $this->info('接著請在 database/data/vendors.php 定義遊戲商資訊與設計 Migration 檔案。');

        if ($isSingleWallet) {
            $this->warn('單錢包：請把 Driver::defaultCallbackPath() 改成 routes/external/sw.php 為這家註冊的路由前綴（DriverCallbackPathDeclarationTest 會檢查）。');
        }
    }

    private function createModels(string $vendorName): void
    {
        $models = ['Account', 'BetRecord'];
        $vendorName = ucfirst(strtolower($vendorName));

        $basePath = base_path('app/Vendors/Models');
        $stubDir = __DIR__ . '/../../Stubs';

        foreach ($models as $model) {
            $modelName = Str::studly($model);
            $vendorName = Str::studly($vendorName);
            $path = $basePath . '/' . $vendorName . $modelName . '.php';
            $stubPath = $stubDir . '/Model' . $modelName . '.stub';

            if ($this->createFileFromStub($path, $stubPath, ['{{$name}}' => $vendorName])) {
                $this->info("Model {$vendorName}{$modelName}.php created successfully.");
            }
        }

        $this->vendorFileReplacement['{{$accountModel}}'] = "{$vendorName}Account";
        $this->vendorFileReplacement['{{$betRecordModel}}'] = "{$vendorName}BetRecord";
    }

    private function createVendorFile(string $vendorName, string $walletType): void
    {
        $path = base_path("app/Vendors/{$vendorName}/Driver.php");
        $stubPath = __DIR__ . "/../../Stubs/Driver{$walletType}.stub";
        $modelName = ucfirst(strtolower($vendorName));

        if (!$this->createFileFromStub($path, $stubPath, ['{{$name}}' => $vendorName, '{{$modelName}}' => $modelName])) {
            return;
        }

        $this->info("Driver.php created successfully at app/Vendors/{$vendorName}/");
    }

    private function updateConfig(string $vendorName, string $walletType): void
    {
        // class constant 大小寫敏感（實測 VendorIdEnum::AiGamingSW 會拋 Undefined constant），
        // 因此不能自行猜測 case 名稱。多數 case 是全大寫，但 GreatWall／TITAN_gfg／Nex4DSW
        // 等既有 case 不是——解析時不分大小寫，寫進 config 時逐字沿用 enum 的實際 case 名。
        $enumKey = $this->resolveVendorIdEnumCase($vendorName);

        if ($enumKey === null) {
            $this->error("VendorIdEnum 找不到對應 {$vendorName} 的 case，已略過 config/vendor.php 註冊。");
            $this->warn('請先在 app/Enums/Vendor/VendorIdEnum.php 新增對應 case（值＝vendors 表的 vendor_id），再重跑本命令。');
            $this->warn('未補 case 就寫入 config，整個應用會在載入 config/vendor.php 時 fatal。');

            return;
        }

        $classPath = "Vendors\\{$vendorName}\\Driver";
        $configPath = config_path('vendor.php');
        $newLine = "VendorIdEnum::{$enumKey}->value => {$classPath}::class,";

        $configContent = file_get_contents($configPath);

        if (preg_match("/VendorIdEnum::{$enumKey}->value/", $configContent)) {
            $this->warn("VendorIdEnum::{$enumKey} already exists in config/vendor.php, skip");

            return;
        }

        if ($walletType === 'TransferWallet') {
            // 找到 // 轉帳錢包 End 的註解
            $updatedContent = preg_replace(
                '/(\/\/\s*轉帳錢包 End)/',
                "$newLine\n        $1",
                $configContent
            );
        } else {
            // 找到 // 單錢包 End 的註解
            $updatedContent = preg_replace(
                '/(\/\/\s*單錢包 End)/',
                "$newLine\n        $1",
                $configContent
            );
        }

        file_put_contents($configPath, $updatedContent);
        $this->info("Vendor configuration added: $newLine");
    }

    /**
     * 解析遊戲商名稱對應的 VendorIdEnum case 名稱，找不到回 null。
     *
     * config/vendor.php 以 VendorIdEnum::XXX->value 為 key，case 不存在時整個應用會在
     * 載入 config 階段 fatal，因此註冊前必須先確認 case 已建立。比對刻意不分大小寫
     * （命令拿到的是 Str::studly() 後的名稱，未必等於 case 的寫法），但回傳一律是
     * enum 上的原字面——class constant 是大小寫敏感的，寫錯一個字母就 fatal。
     */
    private function resolveVendorIdEnumCase(string $vendorName): ?string
    {
        foreach (VendorIdEnum::cases() as $case) {
            if (strcasecmp($case->name, $vendorName) === 0) {
                return $case->name;
            }
        }

        return null;
    }
}
