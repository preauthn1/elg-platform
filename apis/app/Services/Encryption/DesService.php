<?php

declare(strict_types=1);

namespace App\Services\Encryption;

/**
 * openssl 实现的 DES 加密类，支持各种 PHP 版本
 * DES-EC 不需要 iv
 */
final readonly class DesService
{
    public string $cipher;

    public string $key;

    public string $iv;

    public string $output;

    public int $options;

    public function __construct(
        ?string $cipher = null,
        ?string $key = null,
        ?string $iv = null,
        ?string $output = null,
        ?int $options = null,
    ) {
        $this->cipher = $cipher ?? 'DES-ECB';
        $this->key = $key ?? '';
        $this->iv = $iv ?? '';
        $this->output = $output ?? 'base64';
        $this->options = $options ?? OPENSSL_RAW_DATA | OPENSSL_NO_PADDING;
    }

    public function init(
        ?string $cipher = null,
        ?string $key = null,
        ?string $iv = null,
        ?string $output = null,
        ?int $options = null,
    ): static {
        return new self(
            $cipher ?? $this->cipher,
            $key ?? $this->key,
            $iv ?? $this->iv,
            $output ?? $this->output,
            $options ?? $this->options,
        );
    }

    public function encrypt(string $data): string
    {
        $data = $this->pkcsPadding($data, 8);
        $cryptText = openssl_encrypt($data, $this->cipher, $this->key, $this->options, $this->iv);

        if ($cryptText === false) {
            throw new \RuntimeException('Encryption failed: ' . $this->getOpenSSLError());
        }

        return match ($this->output) {
            'base64' => base64_encode($cryptText),
            'hex' => bin2hex($cryptText),
            default => $cryptText,
        };
    }

    public function decrypt(string $cryptText): string
    {
        $cryptText = match ($this->output) {
            'base64' => base64_decode($cryptText, true),
            'hex' => hex2bin($cryptText),
            default => $cryptText,
        };

        if ($cryptText === false) {
            throw new \RuntimeException('Decryption failed: Invalid encoded data.');
        }

        $decryptedText = openssl_decrypt($cryptText, $this->cipher, $this->key, $this->options, $this->iv);

        if ($decryptedText === false) {
            throw new \RuntimeException('Decryption failed: ' . $this->getOpenSSLError());
        }

        $unpaddedText = $this->unPkcsPadding($decryptedText);

        if ($unpaddedText === false) {
            throw new \RuntimeException('Decryption failed: Invalid padding.');
        }

        return trim($unpaddedText);
    }

    private function getOpenSSLError(): string
    {
        return openssl_error_string() ?: 'Unknown error';
    }

    /**
     * PKCS#7 填充
     */
    private function pkcsPadding(string $str, int $blocksize): string
    {
        $pad = $blocksize - (strlen($str) % $blocksize);

        return $str . str_repeat(chr($pad), $pad);
    }

    /**
     * PKCS#7 去填充
     */
    private function unPkcsPadding(string $str): false|string
    {
        $pad = ord($str[strlen($str) - 1]);
        // 如果填滿位元組數 $pad 大於字串長度，或最後 $pad 個字元不是預期的填滿內容，則傳回 false，表示填入無效或資料被竄改。
        if ($pad > strlen($str) || substr($str, -1 * $pad) !== str_repeat(chr($pad), $pad)) {
            return false;
        }

        return substr($str, 0, -1 * $pad);
    }
}
