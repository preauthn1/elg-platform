<?php

declare(strict_types=1);

namespace App\Vendors\WESW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\WeswAccount;
use App\Vendors\Models\WeswBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    protected string $accountModel = WeswAccount::class;

    protected string $betRecordModel = WeswBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 we 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/we';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        // 不需要 gamecode, 直接進大廳
        $info = $this->vendorInfo->info;
        $username = $this->vendorAccount->username;
        $exitUrl = $params->exitUrl;
        $ip = $params->ip;

        $url = $info['apiUrl'] . '/player/launch';
        $language = $this->getLanguage($params->language->value);

        $timestamp = (string) time();
        $postData = [
            'playerID' => $username,
            'clientIP' => $ip,
            'token' => $this->getToken($username),
            'redirectUrl' => $exitUrl,
            'lang' => $language,
            'operatorID' => $info['operatorId'],
            'requestTime' => $timestamp,
        ];
        $signature = hash('sha256', $info['appSecret'] . $timestamp);

        $headers = [
            'signature' => $signature,
        ];
        //
        $response = Http::logAction('WESW_getGameUrl')
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $postData)
            ->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        $url = $json['url'];

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    private function getToken($data): string
    {
        // 创建初始化向量（IV）
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));

        // 加密数据
        $ciphertext = openssl_encrypt($data, 'AES-256-CBC', $this->vendorInfo->info['appSecret'], 0, $iv);

        // 将密文和初始化向量一起传递
        $ciphertextWithIV = base64_encode($iv . $ciphertext);

        return $ciphertextWithIV;
    }

    private function getLanguage(int $languageId): string
    {
        // en 英文
        // zh 繁體中文
        // cn 簡體中文
        // th 泰文
        // vi 越南文
        // ko 韓文
        // ja 日文
        // id 印尼文
        // pt 葡萄牙文
        // tl 菲律賓文
        // my 緬甸文
        // in 印度文
        // es 西班牙文
        // tr 土耳其文

        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'ch',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::MY_MM->value => 'my',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TL_PH->value => 'tl',
            default => 'en',
        };
    }

    /**
     * info 透過 $this->vendorInfo->info 直接存取，無需額外解析；
     * 必須覆寫，createAccount 走 setVendorInfo 時才不會落到 base 的 not implemented
     */
    public function setInfo(array $info): void {}

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_we_single_wallet_account）
     *
     * WE 沒有獨立的註冊 API，建號＝以 playerID 打一次 /player/launch；
     * token 沿用 getToken()（AES 密文），與登入路徑同一把鑰匙。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username): bool
    {
        $info = $this->vendorInfo->info;
        $timestamp = (string) time();

        $postData = [
            'playerID' => $username,
            'clientIP' => '0.0.0.0',
            'token' => $this->getToken($username),
            'operatorID' => $info['operatorId'],
            'requestTime' => $timestamp,
        ];

        try {
            $json = Http::logAction('WESW_createAccount')
                ->asForm()
                ->withHeaders(['signature' => hash('sha256', $info['appSecret'] . $timestamp)])
                ->post($info['apiUrl'] . '/player/launch', $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[WESW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || isset($json['error'])) {
            Log::error('[WESW][createAccount] failed', ['username' => $username, 'response' => $json]);

            return false;
        }

        return true;
    }
}
