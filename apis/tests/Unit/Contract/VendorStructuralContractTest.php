<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 遊戲商結構合約。
 *
 * 以 config/vendor.php 的 driver 註冊表為單一事實來源，以「唯一 Driver
 * class」為單位驗證（聚合 entry —— 如多個 TITAN_* 共用同一 Driver ——
 * 只驗一次）。資料來源直接 require config 檔（autoload 已就緒、不需 boot
 * app / DB）；單一錢包 vs 轉帳錢包以 instanceof 靜態分類，不依賴 DB seed。
 */
final class VendorStructuralContractTest extends TestCase
{
    /**
     * 每個遊戲商 Driver 必須是 AbstractSingleWallet 或 AbstractTransferWallet
     * 的「具體」子類，且只能其一。
     */
    #[DataProvider('vendorDriverProvider')]
    public function test_driver_is_concrete_wallet_subclass(string $fqcn): void
    {
        $this->assertTrue(class_exists($fqcn), "遊戲商 Driver 類別不存在：{$fqcn}");

        $reflection = new ReflectionClass($fqcn);
        $this->assertFalse(
            $reflection->isAbstract(),
            "遊戲商 Driver [{$fqcn}] 不可為 abstract（必須可實例化）"
        );

        $isSingle = $reflection->isSubclassOf(AbstractSingleWallet::class);
        $isTransfer = $reflection->isSubclassOf(AbstractTransferWallet::class);

        $this->assertTrue(
            $isSingle xor $isTransfer,
            "遊戲商 Driver [{$fqcn}] 必須繼承 AbstractSingleWallet 或 AbstractTransferWallet（且只能其一）"
        );
    }

    #[DataProvider('vendorDriverProvider')]
    public function test_driver_declares_strict_types(string $fqcn): void
    {
        $source = (string) file_get_contents((new ReflectionClass($fqcn))->getFileName());
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $source,
            "遊戲商 Driver [{$fqcn}] 必須宣告 declare(strict_types=1)"
        );
    }

    /**
     * 每個遊戲商 Driver 必須自行給定錢包與注單模型（BaseAbstractVendor 合約）。
     *
     * 🔴 判準是「有沒有給值」，不是 hasProperty()：BaseAbstractVendor 已宣告
     * `protected string $accountModel;`／`$betRecordModel;`（typed 但未初始化），
     * 所有 Driver 都繼承得到，hasProperty() 對完全沒宣告的 Driver 也恆為 true，
     * 等於這條斷言永遠不會紅。改以 getDefaultProperties() 取實際預設值並驗類別存在，
     * 順帶擋住「指向不存在的 model class」（例如腳手架佔位符展開錯誤）。
     */
    #[DataProvider('vendorDriverProvider')]
    public function test_driver_declares_required_models(string $fqcn): void
    {
        $defaults = (new ReflectionClass($fqcn))->getDefaultProperties();

        foreach (['accountModel', 'betRecordModel'] as $property) {
            $value = $defaults[$property] ?? null;

            $this->assertIsString(
                $value,
                "遊戲商 Driver [{$fqcn}] 必須自行宣告 \${$property} 並給定 model class（繼承基底的未初始化屬性不算）"
            );
            $this->assertNotSame('', $value, "遊戲商 Driver [{$fqcn}] 的 \${$property} 不可為空字串");
            $this->assertTrue(
                class_exists($value),
                "遊戲商 Driver [{$fqcn}] 的 \${$property} 指向不存在的類別：{$value}"
            );
        }
    }

    /**
     * 以唯一 Driver FQCN 去重列舉（聚合 entry 共用 Driver 只算一次）。
     *
     * @return array<string, array{0: string}>
     */
    public static function vendorDriverProvider(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/vendor.php';
        $map = $config['driver'] ?? $config;

        $cases = [];
        foreach ($map as $fqcn) {
            if (is_string($fqcn) && str_contains($fqcn, '\\') && !isset($cases[$fqcn])) {
                $cases[$fqcn] = [$fqcn];
            }
        }

        return $cases;
    }
}
