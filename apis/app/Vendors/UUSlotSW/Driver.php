<?php

declare(strict_types=1);

namespace App\Vendors\UUSlotSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventUuslotswAccount;
use App\Vendors\Models\UUSlotswAccount;
use App\Vendors\Models\UUSlotswBetRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = UUSlotswAccount::class;

    protected string $betRecordModel = UUSlotswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    /**
     * 一般會員流程放 {@see UUSlotswAccount}，舊版活動流程放 {@see EventUuslotswAccount}；
     * 兩者是平行的 AbstractAccount 子類別，此處只取用 `username`（Eloquent magic property）
     */
    private AbstractAccount $account;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 uuslot 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/uuslot';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建構子只在有 member 時才把 vendorInfo/vendorAccount 轉存到本類別的嚴格型別屬性，
     * 舊版活動情境（`member: null` 建構）需在此補做同一件事，見 {@see BaseAbstractVendor::useLegacyEventContext()}
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        parent::useLegacyEventContext($eventVendorInfo, $eventAccount);

        if (!($eventAccount instanceof EventUuslotswAccount)) {
            throw new VendorSetupException(static::class . '::useLegacyEventContext requires an EventUuslotswAccount');
        }

        $this->setInfo($eventVendorInfo->info);
        $this->account = $eventAccount;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $postData = [
            'PlayerId' => $this->account->username,
            'Ip' => $params->ip,
            'GameCode' => $params->gameCode,
            'Currency' => $this->infoDetail->CURRENCY,
            'RedirectUrl' => $params->exitUrl,
            'Lang' => $this->mapLanguageCode(),
        ];
        $response = $this->send('GameLogin', $postData);

        if ((int) $response['Status'] !== 200) {
            throw new ApiException("UUSlot GameLogin failed: Status={$response['Status']} {$response['Description']}");
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['Url'],
            'mobile' => $response['Url'],
        ]);
    }

    /**
     * 遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->send('GetGameList', []);

        if ((int) ($response['Status'] ?? 0) !== 200) {
            $code = $response['Status'] ?? '';
            $error = $response['Description'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $langMap = [
            'en-us' => LanguageEnum::EN->value,
            'zh-tw' => LanguageEnum::ZH_TW->value,
            'zh-cn' => LanguageEnum::ZH_CN->value,
            'ml-my' => LanguageEnum::MS_MY->value,
            'id-id' => LanguageEnum::ID_ID->value,
            'vi-vn' => LanguageEnum::VI_VN->value,
            'th-th' => LanguageEnum::TH_TH->value,
        ];

        $data = [];
        foreach ($response['Game'] ?? [] as $game) {
            $gameCode = (string) $game['GameCode'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => str_replace('Web', 'mobile', $game['ImageUrl'] ?? ''),
                'gameType' => $this->convertGameType((int) $game['GameType']),
                'status' => 1,
                'info' => json_encode($game),
            ]);

            // OtherName 格式為 "語系碼|名稱"
            foreach ($game['OtherName'] ?? [] as $otherName) {
                [$langCode, $name] = array_pad(explode('|', $otherName, 2), 2, '');
                $languageId = $langMap[trim($langCode)] ?? null;
                if ($languageId !== null && trim($name) !== '') {
                    $gameInfo->setGameNames($languageId, trim($name));
                }
            }

            $data[$gameCode] = $gameInfo;
        }

        // 三方清單不含 CashBonus / Jackpot 兩款特殊遊戲，未注入會被差集比對誤停用
        foreach (['CashBonus', 'Jackpot'] as $specialGameCode) {
            $data[$specialGameCode] = GameInfoData::from([
                'gameCode' => $specialGameCode,
                'gameName' => $specialGameCode,
                'imageUrl' => '',
                'gameType' => GameTypeEnum::REWARD->value,
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function convertGameType(int $type): int
    {
        // UUSlot Game Type: 0=Others, 1=Slots, 2=Arcade, 3=Table, 4=Event, 5=Mini Game, 6=Fish Arcade
        return match ($type) {
            1 => GameTypeEnum::SLOTS->value,
            2 => GameTypeEnum::ARCADE->value,
            6 => GameTypeEnum::FISHING->value,
            default => GameTypeEnum::EVENT_AND_OTHERS->value,
        };
    }

    /**
     * @throws ApiException
     * @throws ConnectionException
     * @throws RequestException
     */
    public function send(string $api, array $postData, string $action = ''): ?array
    {
        $action = empty($action) ? "UUSlot_SW_api_call_{$api}" : $action;

        $this->check();

        $postData['RequestDateTime'] = date('Y-m-d H:i:s');
        $postData['OperatorId'] = $this->infoDetail->Merchant_Code;
        $postData['Signature'] = $this->makeSignature($api, $postData);

        $apiUrl = trim($this->infoDetail->API_URL, '/');
        $api = trim($api, '/');
        $endpoint = "{$apiUrl}/{$api}";

        $response = Http::logAction($action)
            ->asJson()
            ->post($endpoint, $postData)
            ->throw()
            ->json();

        return $response;
    }

    private function check(): void
    {
        if (empty($this->infoDetail)) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($this->infoDetail->API_URL)) {
            throw new ApiException("VendorInfo detail's API_URL is empty, please check the value");
        }

        if (empty($this->infoDetail->Security_key)) {
            throw new ApiException("VendorInfo detail's Security_key is empty, please check the value");
        }

        if (empty($this->infoDetail->Merchant_Code)) {
            throw new ApiException("VendorInfo detail's Merchant_Code is empty, please check the value");
        }
    }

    private function makeSignature(string $api, array $postData): string
    {
        $FunctionName = $api;
        $RequestDateTime = $postData['RequestDateTime'];
        $OperatorId = $postData['OperatorId'];
        $SecurityKey = $this->infoDetail->Security_key;
        $PlayerId = $postData['PlayerId'] ?? '';

        return md5($FunctionName . $RequestDateTime . $OperatorId . $SecurityKey . $PlayerId);
    }

    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-tw',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::ID_ID->value => 'id-id',
            LanguageEnum::TH_TH->value => 'th-th',
            LanguageEnum::MY_MM->value => 'my-mm',
            default => 'en-us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_uuslot_single_wallet_account）
     *
     * UUSlot 沒有建號 API：以 GameLogin + GameCode=0（大廳）觸發自動開號。
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
        try {
            $response = $this->send('GameLogin', [
                'PlayerId' => $username,
                'Ip' => '0.0.0.0',
                'GameCode' => '0',
                'Currency' => $this->infoDetail->CURRENCY,
            ]);
        } catch (Throwable $e) {
            Log::error('[UUSlotSW][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (int) ($response['Status'] ?? 0) !== 200) {
            Log::error('[UUSlotSW][createAccount] rejected', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}
