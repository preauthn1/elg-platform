<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\JokerBaccaratSW;

use App\Vendors\JokerBaccaratSW\JokerBaccaratSWTrait;
use App\Vendors\JokerBaccaratSW\VendorInfoDetail;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    private string $apiToken = [REDACTED]';

    /**
     * 建立一個僅暴露 buildSignature 的薄包裝，避免拉起完整 Driver。
     */
    private function makeSigner(?string $apiToken = [REDACTED] object
    {
        $token = [REDACTED] ?? $this->apiToken;

        return new class($token)
        {
            use JokerBaccaratSWTrait;

            public string $vendorLabel = 'JokerBaccaratSW';

            public VendorInfoDetail $infoDetail;

            public function __construct(string $apiToken)
            {
                $this->infoDetail = new VendorInfoDetail(
                    API_URL: 'https://example.invalid',
                    API_ID: 'guest',
                    API_TOKEN: [REDACTED],
                    CURRENCY: 'USD',
                );
            }

            public function callBuildSignature(array $payload): string
            {
                return $this->buildSignature($payload);
            }
        };
    }

    /**
     * 還原規範描述的 key 衍生邏輯，供 roundtrip 解密測試使用。
     */
    private function deriveKey24(string $apiToken): string
    {
        $key = md5($apiToken, true);

        return $key . substr($key, 0, 8);
    }

    public function test_signature_is_base64_encoded(): void
    {
        $signer = $this->makeSigner();

        $signature = $signer->callBuildSignature([
            'playerID' => 'demo',
            'password' => 'demopass',
        ]);

        $this->assertNotSame('', $signature);
        // strict mode 下 base64_decode 對非法字元會回 false
        $decoded = base64_decode($signature, true);
        $this->assertNotFalse($decoded, 'signature 必須為有效 base64');
        // 3DES block size 8 bytes，PKCS7 後密文長度必為 8 的倍數
        $this->assertSame(0, strlen((string) $decoded) % 8);
    }

    public function test_signature_roundtrip_can_be_decrypted(): void
    {
        $signer = $this->makeSigner();
        $payload = [
            'playerID' => 'demo',
            'password' => 'demopass',
        ];

        $signature = $signer->callBuildSignature($payload);

        $cipherBytes = base64_decode($signature, true);
        $this->assertNotFalse($cipherBytes);

        $plain = openssl_decrypt(
            (string) $cipherBytes,
            'des-ede3',
            $this->deriveKey24($this->apiToken),
            OPENSSL_RAW_DATA,
            '',
        );

        $this->assertNotFalse($plain, 'roundtrip 解密失敗');
        $this->assertSame(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $plain,
        );
    }

    public function test_signature_handles_slashes_unescaped(): void
    {
        $signer = $this->makeSigner();
        $payload = [
            'playerID' => 'demo',
            'redirectURL' => 'https://example.invalid/path/to/page',
        ];

        $signature = $signer->callBuildSignature($payload);

        $plain = openssl_decrypt(
            (string) base64_decode($signature, true),
            'des-ede3',
            $this->deriveKey24($this->apiToken),
            OPENSSL_RAW_DATA,
            '',
        );

        $this->assertNotFalse($plain);
        // 必須保留原始 `/`，不可被 escape 為 `\/`
        $this->assertStringContainsString('https://example.invalid/path/to/page', (string) $plain);
        $this->assertStringNotContainsString('\\/', (string) $plain);

        $decoded = json_decode((string) $plain, true);
        $this->assertSame($payload, $decoded);
    }

    public function test_signature_handles_unicode_unescaped(): void
    {
        $signer = $this->makeSigner();
        $payload = [
            'playerID' => 'demo',
            'nickname' => '玩家一號',
        ];

        $signature = $signer->callBuildSignature($payload);

        $plain = openssl_decrypt(
            (string) base64_decode($signature, true),
            'des-ede3',
            $this->deriveKey24($this->apiToken),
            OPENSSL_RAW_DATA,
            '',
        );

        $this->assertNotFalse($plain);
        // JSON_UNESCAPED_UNICODE：中文不應被轉為 \uXXXX
        $this->assertStringContainsString('玩家一號', (string) $plain);
        $this->assertStringNotContainsString('\\u', (string) $plain);
    }

    public function test_signature_differs_when_api_token_changes(): void
    {
        $payload = ['playerID' => 'demo'];

        $signatureA = $this->makeSigner('token-A')->callBuildSignature($payload);
        $signatureB = $this->makeSigner('token-B')->callBuildSignature($payload);

        $this->assertNotSame($signatureA, $signatureB, '不同 API_TOKEN 必須產生不同密文');
    }
}
