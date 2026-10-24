<?php

declare(strict_types=1);

namespace App\Vendors\FFFSW;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Models\FffswAccount;
use App\Vendors\Models\FffswBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet
{
    protected string $accountModel = FffswAccount::class;

    protected string $betRecordModel = FffswBetRecord::class;

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        /** @var FffswAccount $account */
        $account = $this->vendorAccount;

        // 只有大廳, 不需要帶 gameCode
        $data = [
            'xusername' => $account->username,
            'xpassword' => $account->password,
            'cry' => strtolower($this->vendorInfo->currency),
            'lang' => $this->mapLanguageCode(),
            '_t' => time(),
        ];

        $response = $this->sendRequest('getGameUrl', '/open/v2/user/login', $data);

        $gameUrl = $response['data']['game_url'];

        if (empty($gameUrl)) {
            throw new ApiException($response['message'] ?? 'Game url is empty');
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function sendRequest(string $action, string $url, array $data): array
    {
        // 取得遊戲配置
        $config = $this->vendorInfo->info;
        $url = $config['API_URL'] . $url;
        $aesKey = $config['AES_KEY'];
        $md5Key = $config['MD5_KEY'];

        // 過濾參數
        $data = $this->makeParams($data);
        // 加密參數
        $encryptParams = $this->encrypt($data, $aesKey);
        // 生成簽名
        $sign = $this->produceSign($data, $md5Key);

        $postData = [
            'params' => $encryptParams,
            'sign' => $sign,
            'key' => $md5Key,
        ];

        $options = [
            'timeout' => 5,
            'verify' => false,
        ];

        $result = Http::logAction($this->getName() . ' ' . $action)
            ->withOptions($options)
            ->asForm()
            ->post($url, $postData)
            ->throw();

        $result = $result->json();

        if (isset($result['code']) && $result['code'] !== 0) {
            throw new Exception($result['message'], $result['code']);
        }

        return $result;
    }

    /**
     * 生成參數
     */
    private function makeParams($params): string
    {
        if (empty($params)) {
            return '';
        }
        ksort($params);
        $tmp = [];
        foreach ($params as $k => $param) {
            $tmp[] = $k . '=' . $param;
        }

        return implode('&', $tmp);
    }

    /**
     * 加密參數
     */
    private function encrypt($plainText, $aesKey): string
    {
        $cipher = 'AES-256-CBC';
        $ivLen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $ciphertextRaw = openssl_encrypt($plainText, $cipher, $aesKey, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertextRaw);
    }

    /**
     * 生成簽名
     */
    private function produceSign($data, $signature): string
    {
        return md5($data . $signature);
    }

    /**
     * 轉換語系代號, 不要再寫進 languages
     */
    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            2 => 'tw', // 繁體中文
            3 => 'zh', // 简体中文
            6 => 'vn', // 越南語
            7 => 'td', // 泰文
            default => 'en', // 英文
        };
    }
}
