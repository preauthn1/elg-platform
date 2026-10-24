<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

/**
 * A3Pay 測試共用：動態 RSA key pair、與 A3PayTrait 相同邏輯的 AES／RSA 工具、agent_cash_flows.info 範本。
 */
trait A3PayCryptoHelpers
{
    private string $privateKeyPem;

    private string $publicKeyPem;

    private string $aesKey = 'test_aes_key_for_a3pay_unit';

    private function generateRsaKeyPair(): void
    {
        // 512-bit 讓 signature base64 ~88 字元，符合 DB sign varchar(200)
        $keyPair = openssl_pkey_new([
            'digest_alg' => 'sha1',
            'private_key_bits' => 512,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = openssl_pkey_get_details($keyPair)['key'];
    }

    private function buildInfo(array $extra = []): array
    {
        return array_merge([
            'API_URL' => 'https://mapi.a3pay.test',
            'MERCHANT_CODE' => 'SGD00001',
            'AES_KEY' => $this->aesKey,
            'RSA_PRIVATE_KEY' => $this->stripPem($this->privateKeyPem),
            'RSA_PUBLIC_KEY' => $this->stripPem($this->publicKeyPem),
            'CURRENCY' => 'SGD',
        ], $extra);
    }

    /** 從 PEM 提取 base64 主體（去 header/footer 與換行），對齊 info 儲存格式 */
    private function stripPem(string $pem): string
    {
        $lines = explode("\n", trim($pem));
        array_shift($lines);
        array_pop($lines);

        return implode('', $lines);
    }

    private function aesEncrypt(string $plainText): string
    {
        $key = hash_pbkdf2('sha1', $this->aesKey, $this->aesKey, 1000, 32, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $encrypted);
    }

    private function aesDecrypt(string $encryptedBase64): string
    {
        $raw = base64_decode($encryptedBase64);
        $key = hash_pbkdf2('sha1', $this->aesKey, $this->aesKey, 1000, 32, true);

        return (string) openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }

    private function rsaSign(string $plainText): string
    {
        $signature = '';
        openssl_sign($plainText, $signature, $this->privateKeyPem);

        return base64_encode($signature);
    }

    /** 模擬 A3Pay 回調信封：Data 為 AES 密文、Signature 對 JSON 明文簽 */
    private function buildCallbackEnvelope(array $data, array $envelope = []): array
    {
        $json = json_encode($data);

        return array_merge([
            'MerchantTransactionId' => $data['MerchantTransactionId'] ?? '',
            'Data' => $this->aesEncrypt($json),
            'Signature' => $this->rsaSign($json),
            'Version' => 0,
        ], $envelope);
    }
}
