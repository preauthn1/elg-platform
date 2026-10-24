<?php

declare(strict_types=1);

namespace App\Vendors\CRSW;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Models\CrswAccount;
use App\Vendors\Models\CrswBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * {
 * 'apiUrl': 'https://example.invalid/app/control_API/agents/api_doaction.php',
 * 'cryptKey': 'TkrqMf8HxfVtZwnu',
 * 'token': '356ecfd3m1dc8ld801c3xw',
 * 'username': 'Titantest2',
 * 'password': 'aaa123',
 * 'agid': '3471'
 * }
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath
{
    protected string $accountModel = CrswAccount::class;

    protected string $betRecordModel = CrswBetRecord::class;

    protected array $config = [];

    private CrswAccount $account;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 cr-sport 單一入口路由；
     * 同一 controller 另掛 cr 別名，以 cr-sport 為準（docs/integrations/vendors/CRSW/api.md 標 cr 為別名）
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/cr-sport';
    }

    public function setInfo(array $info): void
    {
        $this->checkInfo($info);
        $this->config = $info;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $password = [REDACTED]'password'] ?? '';
        $machine = $this->config['machine'] ?? 'PC';
        $token = [REDACTED]'token'] ?? '';
        // $currency = $this->config['currency'] ?? '';

        $data = [
            'memname' => $this->account->username,
            'password' => $password,
            'machine' => $machine,
            'langx' => $this->mapLanguageCode(),
            'isSSL' => 'Y',
            'token' => $token,
            'currency' => $this->vendorInfo->currency,
            'remoteip' => $params->ip,
            'timestamp' => time(),
        ];

        $result = $this->sendRequest('LaunchGame', $data);

        if ($result['respcode'] != '0000') {
            throw new ApiException('[CRSW][GetGameUrl] API error: ' . $result['respcode']);
        }

        if (!isset($result['launchgameurl']) || empty($result['launchgameurl'])) {
            throw new ApiException('[CRSW][GetGameUrl] API error: game url is empty');
        }

        $gameUrl = $result['launchgameurl'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function sendRequest(string $method, array $data): array
    {
        $apiUrl = $this->config['apiUrl'];
        $agId = $this->config['agid'] ?? '';

        Log::debug('CRSW API request ' . $method, $data);
        $encodeRequest = $this->encrypt(json_encode($data));

        $postData = [
            'Request' => $encodeRequest,
            'Method' => $method,
            'AGID' => $agId,
        ];

        $response = Http::logAction($this->getName() . '_' . $method)
            ->asJson()
            ->post($apiUrl, $postData)
            ->throw()
            ->body();

        $decryptResponse = $this->decrypt($response);
        $response = json_decode($decryptResponse, true);
        Log::debug('CRSW API response ' . $method, $response);

        return $response;
    }

    private function checkInfo(array $info): void
    {
        if (empty($info)) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($info['apiUrl'])) {
            throw new ApiException("VendorInfo detail's apiUrl is empty, please check the value");
        }

        if (empty($info['cryptKey'])) {
            throw new ApiException("VendorInfo detail's cryptKey is empty, please check the value");
        }

        if (empty($info['token'])) {
            throw new ApiException("VendorInfo detail's token  is empty, please check the value");
        }

        if (empty($info['username'])) {
            throw new ApiException("VendorInfo detail's username  is empty, please check the value");
        }

        if (empty($info['password'])) {
            throw new ApiException("VendorInfo detail's password  is empty, please check the value");
        }

        if (empty($info['agid'])) {
            throw new ApiException("VendorInfo detail's agid  is empty, please check the value");
        }
    }

    private function encrypt($string): string
    {
        $cryptKey = $this->config['cryptKey'] ?? '';

        if (empty($cryptKey)) {
            throw new ApiException("VendorInfo detail's cryptKey is empty, please check the value");
        }

        $data = openssl_encrypt($string, 'AES-128-ECB', $cryptKey, OPENSSL_RAW_DATA);

        if ($data === false) {
            throw new ApiException('Encryption failed: ' . openssl_error_string());
        }

        $data = base64_encode($data);

        return $data;
    }

    private function decrypt($string): false|string
    {
        $cryptKey = $this->config['cryptKey'] ?? '';

        if (empty($cryptKey)) {
            throw new ApiException("VendorInfo detail's cryptKey is empty, please check the value");
        }

        $string = base64_decode($string);
        $decrypted = openssl_decrypt($string, 'AES-128-ECB', $cryptKey, OPENSSL_RAW_DATA);

        if ($decrypted === false) {
            throw new ApiException('Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            // LanguageEnum::EN->value => '',
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            // LanguageEnum::MS_MY->value => '',
            LanguageEnum::ID_ID->value => 'id-id',
            LanguageEnum::VI_VN->value => 'vi-vn',
            LanguageEnum::TH_TH->value => 'th-th',
            // LanguageEnum::TA->value => '',
            // LanguageEnum::TL_PH->value => '',
            LanguageEnum::MY_MM->value => 'my-mm',
            // LanguageEnum::HI_IN->value => '',
            // LanguageEnum::TPI->value => '',
            // LanguageEnum::HO->value => '',
            default => 'en-us',
        };
    }
}
