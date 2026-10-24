<?php

declare(strict_types=1);

namespace App\Services\Encryption;

/**
 * openssl 实现的 AES 加密类，支持各种 PHP 版本
 */
final readonly class AesService
{
    public string $cipher;

    public string $md5Key;

    public string $iv;

    public function __construct(
        ?string $cipher = null,
        ?string $key = null,
        ?string $iv = null
    ) {
        $this->cipher = $cipher ?? config('encrypt.aes.cipher');
        $this->md5Key = md5($key ?? config('encrypt.aes.key'));
        $this->iv = $iv ?? config('encrypt.aes.iv');
    }

    public function init(
        ?string $cipher = null,
        ?string $key = null,
        ?string $iv = null
    ): static {
        return new self(
            $cipher ?? $this->cipher,
            $key ?? $this->md5Key,
            $iv ?? $this->iv
        );
    }

    public function encrypt(string $data): string
    {
        $cryptText = openssl_encrypt($data, $this->cipher, $this->md5Key, OPENSSL_RAW_DATA, $this->iv);

        if ($cryptText === false) {
            throw new \RuntimeException('Encryption failed: ' . $this->getOpenSSLError());
        }

        return base64_encode($cryptText);
    }

    public function decrypt(string $data): string
    {
        $cryptText = $this->safeBase64Decode($data);

        $decryptedText = openssl_decrypt($cryptText, $this->cipher, $this->md5Key, OPENSSL_RAW_DATA, $this->iv);

        if ($decryptedText === false) {
            throw new \RuntimeException('Decryption failed: ' . $this->getOpenSSLError());
        }

        return trim($decryptedText);
    }

    private function safeBase64Decode(string $data): string
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            throw new \RuntimeException('Decryption failed: Invalid base64 data.');
        }

        return $decoded;
    }

    private function getOpenSSLError(): string
    {
        return openssl_error_string() ?: 'Unknown error';
    }
}
