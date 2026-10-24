<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AFBSW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\Group;

/**
 * AFB Seamless 回調的 API Key 驗證（target/op_tickets#3077）
 *
 * AFB 的回調沒有簽名機制（api.md「驗證方式」節：HMAC 簽章只用在我方 → AFB 的方向），
 * 修正前唯一防線是 IP 白名單——擋不住白名單內來源的重放。body 帶的 `key` 與商戶設定的
 * `vendor_infos.info.API_KEY` 是同一個值，補上比對即為第二道。
 *
 * php artisan test tests/Feature/External/SingleWallets/AFBSW/AFBSwApiKeyTest.php --compact
 */
class AFBSwApiKeyTest extends AFBSwTestCase
{
    /**
     * 商戶設定的 API Key（明顯假值）
     */
    private string $apiKey = [REDACTED]';

    private function configureApiKey(?string $apiKey): void
    {
        $info = $this->vendorInfo->info;

        if ($apiKey === null) {
            unset($info['API_KEY']);
        } else {
            $info['API_KEY'] = $apiKey;
        }

        $this->vendorInfo->info = $info;
        $this->vendorInfo->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function getBalancePayload(?string $key): array
    {
        $payload = [
            'method' => 'getBalance',
            'sid' => $this->siteId,
            'username' => $this->username,
            'gameCode' => $this->gameCode,
        ];

        if ($key !== null) {
            $payload['key'] = $key;
        }

        return $payload;
    }

    /**
     * 情境：商戶已設定 API_KEY，AFB 帶正確的 key
     * 預期：照常處理
     */
    #[Group('rules/vendor-afbsw#R-6')]
    public function test_callback_is_accepted_when_api_key_matches(): void
    {
        $this->configureApiKey($this->apiKey);
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl, $this->getBalancePayload($this->apiKey));

        $response->assertStatus(200)->assertJson(['status' => 0, 'balance' => 100.0]);
    }

    /**
     * 情境：商戶已設定 API_KEY，來源帶了錯的 key（IP 白名單內的重放或偽造）
     * 預期：拒收，不吐餘額
     */
    #[Group('rules/vendor-afbsw#R-5')]
    public function test_callback_is_rejected_when_api_key_does_not_match(): void
    {
        $this->configureApiKey($this->apiKey);
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl, $this->getBalancePayload('wrong-key'));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Invalid API key']);
        $response->assertJsonMissingPath('balance');
    }

    /**
     * 情境：商戶已設定 API_KEY，但請求完全沒帶 key
     * 預期：拒收——缺憑證不得視同通過
     */
    #[Group('rules/vendor-afbsw#R-5')]
    public function test_callback_is_rejected_when_key_is_absent(): void
    {
        $this->configureApiKey($this->apiKey);
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl, $this->getBalancePayload(null));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Invalid API key']);
    }

    /**
     * 情境：商戶設定沒有 API_KEY 這個鍵
     * 預期：拒收。
     *
     * 2026-09-10 推翻原本的「缺鍵即放行」：那個決策的代價是讓「設定漏填」等同於無聲地
     * 拆掉這道防線，而本 vendor 的 vendors.whitelist 在 TPower 與 Alfawin77 兩站皆為空
     * ——缺鍵時就是兩道皆無。改動前實查五站（TPower／VWorld／Yes2Win／Alfawin77／Me77），
     * 實際接入的兩個組合 API_KEY 皆有值，故改為拒收對現況零影響。
     */
    #[Group('rules/vendor-afbsw#R-5')]
    public function test_callback_is_rejected_when_api_key_is_not_configured(): void
    {
        $this->configureApiKey(null);
        $this->wallet->update(['balance' => '100.0000']);

        $response = $this->postJson($this->baseurl, $this->getBalancePayload('anything'));

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Invalid API key']);
    }

    /**
     * 情境：帶錯 key 的 refund 回調
     * 預期：擋在憑證驗證，注單與錢包都不動
     */
    #[Group('rules/vendor-afbsw#R-5')]
    public function test_refund_with_wrong_api_key_does_not_touch_bet_record(): void
    {
        $this->configureApiKey($this->apiKey);

        $betRecord = $this->createPlacedBetRecord('1788623295743231077', '6.0000');
        $this->wallet->update(['balance' => '94.0000']);

        $payload = $this->refundPayload('1788623295743231077', '6.00');
        $payload['key'] = 'wrong-key';

        $response = $this->postJson($this->baseurl, $payload);

        $response->assertStatus(200)->assertJson(['status' => 1, 'message' => 'Invalid API key']);

        $this->wallet->refresh();
        $this->assertSame('94.0000', Common::numAdd('0', (string) $this->wallet->balance, 4));

        $betRecord->refresh();
        $this->assertSame('6.0000', Common::numAdd('0', (string) $betRecord->bet, 4));
    }
}
