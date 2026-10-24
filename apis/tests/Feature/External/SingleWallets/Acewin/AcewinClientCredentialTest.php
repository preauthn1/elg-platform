<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Acewin;

use App\Helpers\Common;
use App\Vendors\Models\AcewinBetRecord;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;

/**
 * Acewin 回調的商戶憑證（target/op_tickets#3077 → target/op_tickets#3085）
 *
 * AcewinSW 的回調不帶簽名，改以 HTTP Basic Auth 送商戶憑證（api.md 附錄 E），本平台
 * `parseOperatorId()` 解碼後由 `getVendorInfo()` 比對 `vendor_infos.info.CLIENT_AUTH_ACCOUNT`。
 * `getVendorInfo('')` 的語意是「退回該玩家所屬商戶」，會整段跳過比對——所以不帶憑證時不能交給它，
 * 必須在入口就拒收；生產 log 已實證三方一律帶 Authorization，拒收不會打斷正式流量。
 * 本測試守住三件事：帶錯拒收、不帶拒收（且留 warning 供觀察）、帶對照常處理。
 *
 * php artisan test tests/Feature/External/SingleWallets/Acewin/AcewinClientCredentialTest.php --compact
 */
class AcewinClientCredentialTest extends AcewinTestCase
{
    /**
     * 情境：回調帶錯誤的 Basic Auth 憑證
     * 預期：比對不到商戶，拒收（既有行為守衛）
     */
    #[Group('rules/vendor-acewin#R-2')]
    public function test_callback_with_wrong_basic_auth_is_rejected(): void
    {
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson(
            $this->baseurl . '/bet',
            $this->betPayload('100029128001', '10', '0'),
            ['Authorization' => 'Basic ' . base64_encode('acewin-test:wrong-secret')],
        );

        $response->assertStatus(200)->assertJson(['errorCode' => 5, 'message' => 'Invalid client']);

        $this->assertWalletAndBetUntouched('100029128001');
    }

    /**
     * 情境：回調完全不帶 Authorization
     * 預期：與憑證錯誤同一回應拒收，錢包與注單都不動，並留下 warning 供觀察三方行為
     */
    #[Group('rules/vendor-acewin#R-2')]
    public function test_callback_without_basic_auth_is_rejected(): void
    {
        Log::spy();

        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl . '/bet', $this->betPayload('100029128002', '10', '0'));

        $response->assertStatus(200)->assertJson(['errorCode' => 5, 'message' => 'Invalid client']);

        $this->assertWalletAndBetUntouched('100029128002');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'missing_client_credential'))
            ->once();
    }

    /**
     * 情境：Authorization 存在但不是 Basic（parseOperatorId 解不出憑證）
     * 預期：同「不帶」處理，拒收
     */
    #[Group('rules/vendor-acewin#R-2')]
    public function test_callback_with_non_basic_authorization_is_rejected(): void
    {
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson(
            $this->baseurl . '/bet',
            $this->betPayload('100029128004', '10', '0'),
            ['Authorization' => 'Bearer [REDACTED]'],
        );

        $response->assertStatus(200)->assertJson(['errorCode' => 5, 'message' => 'Invalid client']);

        $this->assertWalletAndBetUntouched('100029128004');
    }

    /**
     * 情境：回調帶正確的 Basic Auth 憑證
     * 預期：照常處理，且不記缺憑證的 warning
     */
    public function test_callback_with_valid_basic_auth_does_not_log_warning(): void
    {
        Log::spy();

        $this->wallet->update(['balance' => '100.0000']);

        $this->postJson($this->baseurl . '/bet', $this->betPayload('100029128003', '10', '0'), $this->authHeaders())
            ->assertStatus(200)->assertJson(['errorCode' => 0]);

        $this->wallet->refresh();
        $this->assertSame('90.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        Log::shouldNotHaveReceived('warning');
    }

    private function assertWalletAndBetUntouched(string $round): void
    {
        $this->wallet->refresh();
        $this->assertSame('100.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));
        $this->assertSame(0, AcewinBetRecord::query()->where('bet_id', $round)->count());
    }
}
