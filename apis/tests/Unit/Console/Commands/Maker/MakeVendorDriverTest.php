<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Maker;

use App\Console\Commands\Maker\MakeVendorDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * make:vendor 的守衛與腳手架安全預設。
 *
 * 這兩組斷言都不碰檔案系統、不寫 config：stub 那組只讀專案檔內容，
 * enum 那組驗的是純解析邏輯。真正會寫檔的整段命令流程不在此測。
 */
final class MakeVendorDriverTest extends TestCase
{
    private const STUB_DIR = __DIR__ . '/../../../../../app/Console/Stubs';

    /**
     * 🔴 轉帳錢包的 TODO 方法不得預設回傳成功。
     *
     * 轉帳結果直接決定要不要動玩家餘額，「沒實作就當成功」等於在對接期間憑空承認轉分成功
     * （Step 1 已 commit 扣款，MemberTransferService 對此不退款）。安全預設只能是拋例外。
     */
    public function test_transfer_wallet_stub_never_defaults_to_success(): void
    {
        $stub = (string) file_get_contents(self::STUB_DIR . '/DriverTransferWallet.stub');

        $this->assertStringNotContainsString(
            'return VendorTransferResultEnum::SUCCESS;',
            $stub,
            'DriverTransferWallet.stub 不得讓未實作的方法回傳 SUCCESS'
        );

        foreach (['transferToVendor', 'transferToLocal'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function ' . $method . '\([^)]*\)[^{]*\{[^}]*throw new LogicException/s',
                $stub,
                "DriverTransferWallet.stub 的 {$method}() 未實作時必須拋例外"
            );
        }
    }

    /**
     * model 引用必須用 {{$modelName}} 佔位符。
     *
     * MakeVendorDriver::createModels() 以 ucfirst(strtolower()) 決定 model 檔名，
     * 與 {{$name}}（Str::studly 後的原名）不同。用錯佔位符時，只有名稱本身無內部
     * 大寫（如 Fuma）才會巧合對上，GreatWall 這類會產出 class not found 的 Driver。
     *
     * @param  string  $stub  stub 檔名
     */
    #[DataProvider('driverStubProvider')]
    public function test_driver_stub_uses_model_name_placeholder_for_models(string $stub): void
    {
        $content = (string) file_get_contents(self::STUB_DIR . '/' . $stub);

        foreach (['Account', 'BetRecord'] as $model) {
            $this->assertStringContainsString(
                'use App\Vendors\Models\{{$modelName}}' . $model . ';',
                $content,
                "{$stub} 的 {$model} model 引用必須用 {{\$modelName}} 而非 {{\$name}}"
            );
        }

        $this->assertStringNotContainsString(
            'use App\Vendors\Models\{{$name}}',
            $content,
            "{$stub} 不得以 {{\$name}} 引用 model（檔名規則不同）"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function driverStubProvider(): array
    {
        return [
            'single wallet' => ['DriverSingleWallet.stub'],
            'transfer wallet' => ['DriverTransferWallet.stub'],
        ];
    }

    /**
     * enum case 解析不分大小寫，但回傳一律是 enum 上的原字面。
     *
     * class constant 大小寫敏感，寫錯一個字母就是載入 config 時 fatal；
     * 而 case 命名並非一律全大寫（GreatWall／TITAN_gfg／Nex4DSW 皆為既有反例），
     * 所以不能用 strtoupper() 猜。
     *
     * @param  string  $input  命令拿到的遊戲商名稱
     * @param  string|null  $expected  期望解析到的 case 名稱
     */
    #[DataProvider('enumCaseProvider')]
    public function test_resolves_enum_case_case_insensitively(string $input, ?string $expected): void
    {
        $method = new ReflectionMethod(MakeVendorDriver::class, 'resolveVendorIdEnumCase');
        $command = (new ReflectionClass(MakeVendorDriver::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $method->invoke($command, $input));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function enumCaseProvider(): array
    {
        return [
            '全大寫 case，輸入 StudlyCase' => ['AiGamingSW', 'AIGAMINGSW'],
            '全大寫 case，輸入原字面' => ['AIGAMINGSW', 'AIGAMINGSW'],
            '非全大寫 case，輸入原字面' => ['GreatWall', 'GreatWall'],
            '非全大寫 case，輸入全大寫' => ['GREATWALL', 'GreatWall'],
            '含底線且混大小寫的 case' => ['titan_gfg', 'TITAN_gfg'],
            '含數字的混合 case' => ['NEX4DSW', 'Nex4DSW'],
            '不存在的遊戲商' => ['NoSuchVendorXyz', null],
        ];
    }
}
