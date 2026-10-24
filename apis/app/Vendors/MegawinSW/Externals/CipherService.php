<?php

declare(strict_types=1);

namespace App\Vendors\MegawinSW\Externals;

use App\Vendors\MegawinSW\Datas\MegaWinSWVendorInfoDetailData;

class CipherService
{
    /**
     * @var MegaWinSWVendorInfoDetailData
     */
    private $info;

    public function __construct(MegaWinSWVendorInfoDetailData $info)
    {
        $this->info = $info;
    }

    public function encrypt(array $data): array
    {
        $secKey = $this->generateRandomSecretKey();

        $encryptKey = $this->rsaEncrypt($secKey); // MW平台公鑰 加密 AESKEY 獲得 EC加密KEY

        $jsonStr = json_encode($data); // DATA 轉成 JSON字串
        $encryptData = $this->aesEncrypt($secKey, $jsonStr); // AESKEY 加密 JSON字串 獲得 EC加密DATA

        return [
            'key' => $encryptKey,
            'data' => $encryptData,
        ]; // 發送 EC加密KEY、EC加密DATA 給 MW
    }

    public function decrypt(string $encryptKey, string $encryptData): array
    {
        $secKey = $this->rsaDecrypt($encryptKey); // EC方私鑰 解密 MW加密KEY 獲得 AESKEY
        $decryptData = $this->aesDecrypt($secKey, $encryptData); // AESKEY 解密 MW加密DATA 獲得 DATA

        $ret = json_decode($decryptData, true);

        if (!$ret) {
            throw new \Exception('Decrypt data failed');
        }

        return $ret;
    }

    private function generateRandomSecretKey(): string
    {
        $strPol = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz';
        $max = strlen($strPol) - 1;

        $ret = '';
        for ($i = 0; $i < 16; $i++) {
            $ret .= $strPol[rand(0, $max)];
        }

        return $ret;
    }

    private function rsaEncrypt(string $aesKey): string
    {
        $pubKey = $this->info->MW_PUBLIC_KEY;

        $encryptKey = '';
        openssl_public_encrypt($aesKey, $encryptKey, $pubKey);
        $encryptKey = base64_encode($encryptKey);

        return $encryptKey;
    }

    private function aesEncrypt(string $key, string $str): string
    {
        $encrypted = openssl_encrypt($str, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        $data = base64_encode($encrypted);

        return $data;
    }

    private function rsaDecrypt(string $ciphertext): string
    {
        $receiveKey = base64_decode($ciphertext);
        $ecPrivateKey = openssl_pkey_get_private($this->info->EC_PRIVATE_KEY);
        openssl_private_decrypt($receiveKey, $decryptAesKey, $ecPrivateKey);

        return $decryptAesKey;
    }

    private function aesDecrypt(string $key, string $str): string
    {
        $encryptedData = base64_decode($str);
        $decrypted = openssl_decrypt($encryptedData, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);

        return $decrypted;
    }
}
