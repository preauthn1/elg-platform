<?php

declare(strict_types=1);

namespace App\Vendors\BoracayFish;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\BoracayFishAccount;
use App\Vendors\Models\BoracayFishBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = BoracayFishAccount::class;

    protected string $betRecordModel = BoracayFishBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 遊戲清單（gamename 為 JSON 字串需再解析成 {語系: 名稱}；
     * 三方標記 CLOSE / 維護中的遊戲以停用狀態同步）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $signature = md5(
            'cert=' . $this->infoDetail->CERT .
            '&currency=' . $this->infoDetail->CURRENCY .
            '&merchantid=' . $this->infoDetail->MERCHANT_ID
        );

        $postData = [
            'cert' => $this->infoDetail->CERT,
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'currency' => $this->infoDetail->CURRENCY,
            'signature' => $signature,
        ];

        $url = trim($this->infoDetail->API_URL, '/') . '/GetGameList';
        $response = Http::logAction('BoracayFish_GetGameList')
            ->asJson()
            ->post($url, $postData)
            ->throw()
            ->json();

        if (($response['error_code'] ?? '') !== '0000') {
            throw new ApiException('Third party error: (' . ($response['error_code'] ?? '') . ') ' . ($response['msg'] ?? ''));
        }

        $typeMap = [
            'fish' => GameTypeEnum::FISHING->value,
        ];

        $langMap = [
            'en' => LanguageEnum::EN->value,
            'zh' => LanguageEnum::ZH_TW->value,
            'cn' => LanguageEnum::ZH_CN->value,
            'my' => LanguageEnum::MS_MY->value,
        ];

        $gameList = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['gamecode'];
            $names = json_decode($game['gamename'] ?? '', true) ?: [];
            $isClosed = ($game['status'] ?? '') === 'CLOSE' || ($game['ismaintain'] ?? '') === 'TRUE';

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $names['en'] ?? $gameCode,
                'imageUrl' => $game['gameimage'] ?? '',
                'gameType' => $typeMap[strtolower($game['gametype'] ?? '')] ?? GameTypeEnum::FISHING->value,
                'status' => $isClosed ? 0 : 1,
                'info' => json_encode($game),
            ]);

            foreach ($langMap as $langCode => $languageId) {
                if (!empty($names[$langCode])) {
                    $gameInfo->setGameNames($languageId, $names[$langCode]);
                }
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function getBalance(): string
    {
        $signature = md5(
            'cert=' . $this->infoDetail->CERT .
            '&currency=' . $this->infoDetail->CURRENCY .
            '&merchantid=' . $this->infoDetail->MERCHANT_ID .
            '&userid=' . $this->vendorAccount->username
        );

        $postData = [
            'cert' => $this->infoDetail->CERT,
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'userid' => $this->vendorAccount->username,
            'currency' => $this->infoDetail->CURRENCY,
            'signature' => $signature,
        ];
        $apiUrl = trim($this->infoDetail->API_URL, '/');

        $retry = 3;
        while ($retry > 0) {
            $retry--;

            $response = Http::logAction('BoracayFish_GetBalance')
                ->post("{$apiUrl}/GetBalance", $postData)
                ->throw()
                ->json();

            $errorCode = $response['error_code'] ?? '';

            if (empty($errorCode)) {
                throw new ApiException('Boracay Fish get balance failed, response error code not found');
            }

            if ($errorCode === '1008') {
                // Your request is too frequent, please try again later.
                sleep(1);

                continue;
            }

            if ($errorCode !== '0000') {
                throw new ApiException($response['msg'] ?? '');
            }

            break;
        }

        return (string) ($response['balance'] ?? '0');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $signature = md5(
            'cert=' . $this->infoDetail->CERT .
            '&currency=' . $this->infoDetail->CURRENCY .
            '&merchantid=' . $this->infoDetail->MERCHANT_ID .
            '&gamecode=' . $params->gameCode .
            '&userid=' . $this->vendorAccount->username
        );

        $postData = [
            'cert' => $this->infoDetail->CERT,
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'userid' => $this->vendorAccount->username,
            'password' => $this->vendorAccount->password,
            'currency' => $this->infoDetail->CURRENCY,
            'language' => $this->mapLanguageCode(),
            'redirecturl' => $params->exitUrl,
            'gamecode' => $params->gameCode,
            'signature' => $signature,
        ];

        $apiUrl = trim($this->infoDetail->API_URL, '/');
        $response = Http::logAction('BoracayFish_Login')
            ->post("{$apiUrl}/Login", $postData)
            ->throw()
            ->json();

        if (!isset($response['error_code'])) {
            throw new ApiException(
                'Boracay Fish sw login failed, response error code not found'
            );
        }
        if ($response['error_code'] !== '0000' || empty($response['gameurl'])) {
            throw new ApiException(
                "Boracay Fish sw login failed, code: {$response['error_code']}, message: {$response['message']}"
            );
        }

        $gameUrl = $response['gameurl'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('Deposit', $params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer('Withdraw', $params);
    }

    private function transfer(string $api, VendorTransferData $params): VendorTransferResultEnum
    {
        $signature = md5(
            'amount=' . $params->vendorAmount .
            '&cert=' . $this->infoDetail->CERT .
            '&currency=' . $this->infoDetail->CURRENCY .
            '&merchantid=' . $this->infoDetail->MERCHANT_ID .
            '&userid=' . $this->vendorAccount->username
        );

        $postData = [
            'cert' => $this->infoDetail->CERT,
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'userid' => $this->vendorAccount->username,
            'currency' => $this->infoDetail->CURRENCY,
            'amount' => $params->vendorAmount,
            'signature' => $signature,
        ];

        $apiUrl = trim($this->infoDetail->API_URL, '/');
        $response = Http::logAction("BoracayFish_{$api}")
            ->post("{$apiUrl}/{$api}", $postData)
            ->throw()
            ->json();

        if (!isset($response['error_code'])) {
            throw new ApiException("Boracay Fish {$api} failed, response error code not found");
        }

        if ($response['error_code'] !== '0000') {
            throw new ApiException($response['msg'] ?? '');
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'cn',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::MS_MY->value => 'my',
            LanguageEnum::VI_VN->value => 'vt',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_boracayfish_account）
     *
     * getGameUrl 直讀帳號列的明文密碼送三方登入，落庫與送三方的密碼必須完全一致。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $password = [REDACTED];

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'password' => $password,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username, $password)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username, string $password): bool
    {
        $signature = md5(
            'cert=' . $this->infoDetail->CERT .
            '&currency=' . $this->infoDetail->CURRENCY .
            '&merchantid=' . $this->infoDetail->MERCHANT_ID .
            '&userid=' . $username
        );

        $postData = [
            'userid' => $username,
            'password' => $password,
            'name' => $username,
            'cert' => $this->infoDetail->CERT,
            'merchantid' => $this->infoDetail->MERCHANT_ID,
            'currency' => $this->infoDetail->CURRENCY,
            'signature' => $signature,
        ];

        $apiUrl = trim($this->infoDetail->API_URL, '/');

        try {
            $response = Http::logAction('BoracayFish_CreateMember')
                ->post("{$apiUrl}/CreateMember", $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[BoracayFish][createAccount] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || ($response['error_code'] ?? '') !== '0000') {
            Log::error('[BoracayFish][createAccount] API error: error_code is not 0000', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}
