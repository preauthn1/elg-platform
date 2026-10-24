<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\FumaSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\VendorInfo;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\FumaSW\FumaSWService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Exception;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/External/SingleWallets/FumaSW/DcLookupBindingTest.php --compact
 *
 * 回調的 dc 參數用來查 vendor_infos 取 KEY / IV，這一步發生在解密驗證之前，
 * 等於未經任何憑證即可觸達；dc 必須以綁定參數查詢，不得改變 SQL 語意（op_tickets#3097）。
 */
class DcLookupBindingTest extends TestCase
{
    private const DC = 'T01';

    private const KEY = '0123456789abcdef';

    private const IV = 'fedcba9876543210';

    private Agent $agent;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::FUMASW->value);
        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = ['DC' => self::DC, 'KEY' => self::KEY, 'IV' => self::IV, 'API_URL' => '', 'PARENT' => 'p'];
        $this->vendorInfo->save();
    }

    /**
     * 依三方規格組回調密文：AES-128-CBC ＋ PKCS7，base64 後改 URL-safe 字元
     */
    private function encrypt(array $payload): string
    {
        $cipher = openssl_encrypt(json_encode($payload), 'AES-128-CBC', self::KEY, OPENSSL_RAW_DATA, self::IV);

        return str_replace(['+', '/'], ['-', '_'], base64_encode($cipher));
    }

    private function callbackRequest(string $dc, string $x): Request
    {
        return Request::create('/external/api/sw/fuma', 'POST', ['dc' => $dc, 'x' => $x]);
    }

    private function decodeExpectingVendorNotFound(string $dc): void
    {
        $request = $this->callbackRequest($dc, $this->encrypt(['action' => 1]));

        $thrown = null;
        try {
            (new FumaSWService)->decodeRequestAndValidateSignature($request);
        } catch (Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'dc 查不到商戶時必須拋出例外');
        $this->assertSame('Vendor not found', $thrown->getMessage());
        $this->assertSame(ErrorCodeEnum::SYSTEM_ERROR, $thrown->getCode());
    }

    #[Group('rules/vendor-fumasw#R-4')]
    public function test_合法的dc可解密並把明文參數合併進請求(): void
    {
        $request = $this->callbackRequest(self::DC, $this->encrypt(['action' => 1, 'uid' => 'FUMATEST_00000001']));

        $result = (new FumaSWService)->decodeRequestAndValidateSignature($request);

        $this->assertTrue($result);
        $this->assertSame(1, $request->input('action'));
        $this->assertSame('FUMATEST_00000001', $request->input('uid'));
    }

    #[Group('rules/vendor-fumasw#R-4')]
    public function test_dc帶入OR恆真子句時不得命中任何商戶(): void
    {
        // 未綁定時會拼成 ... = 'X' OR '1'='1'，命中第一筆商戶並拿其 KEY / IV 進入解密流程；
        // 綁定後整串只是一個不存在的 DC 值
        $this->decodeExpectingVendorNotFound("X' OR '1'='1");
    }

    #[Group('rules/vendor-fumasw#R-4')]
    public function test_dc含單引號時不得改變SQL語意(): void
    {
        // 未綁定時單引號會截斷字面值造成 SQL 語法錯誤（QueryException）；綁定後只是查無此商戶
        $this->decodeExpectingVendorNotFound(self::DC . "'");
    }
}
