<?php

declare(strict_types=1);

namespace App\Vendors\SpadegamingSW;

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
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\SpadegamingswAccount;
use App\Vendors\Models\SpadegamingswBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = SpadegamingswAccount::class;

    protected string $betRecordModel = SpadegamingswBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    private SpadegamingswAccount $account;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 spadegaming 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/spadegaming';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $token = [REDACTED] . $this->infoDetail->Merchant_Code . microtime(true));

        $postData = [
            'merchantCode' => $this->infoDetail->Merchant_Code,
            'acctInfo' => [
                'acctId' => $this->account->username,
                'currency' => $this->infoDetail->CURRENCY,
            ],
            'language' => $this->mapLanguageCode(),
            'token' => $token,
            'game' => $params->gameCode,
            'acctIp' => $params->ip,
            'exitUrl' => $params->exitUrl,
            'fun' => 'false',
            'mobile' => 'true',
            'menuMode' => 'true',
            'fullScreen' => 'true',
            'serialNo' => time() . rand(10, 99),
        ];

        $digest = md5(json_encode($postData) . $this->infoDetail->Security_key);

        $response = Http::logAction('SpadeGamingSW_api_call_getAuthorize')
            ->withHeaders([
                'API' => 'getAuthorize',
                'DataType' => 'JSON',
                'Digest' => $digest,
                'Content-Type' => 'application/json',
            ])
            ->post($this->infoDetail->API_URL . 'getAuthorize', $postData)
            ->throw()
            ->json();

        if ((int) $response['code'] !== 0) {
            throw new ApiException('[SpadeGamingSW][GetGameUrl] response code invalid, msg: ' . ($response['msg'] ?? 'unknown'));
        } elseif (!isset($response['serialNo'])) {
            throw new ApiException('[SpadeGamingSW][GetGameUrl] response serialNo is empty');
        } elseif ($response['serialNo'] !== $postData['serialNo']) {
            throw new ApiException('[SpadeGamingSW][GetGameUrl] serialNo not matched');
        } elseif (!isset($response['token']) || $response['token'] !== $token) {
            throw new ApiException('[SpadeGamingSW][GetGameUrl] response token verify failed');
        }

        return new VendorGameUrlResultData(
            pc: $response['gameUrl'],
            mobile: $response['gameUrl'],
        );
    }

    /**
     * 遊戲清單（endpoint 由 API header 指定，直接 POST API_URL；
     * 類型依 gameCode 首字母判斷；thumbnail 為相對路徑，需拼 API_URL 的 domain）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $postData = [
            'merchantCode' => $this->infoDetail->Merchant_Code,
            'serialNo' => time() . rand(10, 99),
        ];
        $digest = md5(json_encode($postData) . $this->infoDetail->Security_key);

        $response = Http::logAction('SpadeGamingSW_api_call_getGames')
            ->withHeaders([
                'API' => 'getGames',
                'DataType' => 'JSON',
                'Digest' => $digest,
                'Content-Type' => 'application/json',
            ])
            ->post($this->infoDetail->API_URL, $postData)
            ->throw()
            ->json();

        if (!isset($response['serialNo']) || $response['serialNo'] !== $postData['serialNo']) {
            throw new ApiException('[SpadeGamingSW][GetGameList] serialNo not matched');
        }

        $typeMap = [
            'S' => GameTypeEnum::SLOTS->value,
            'F' => GameTypeEnum::FISHING->value,
            'A' => GameTypeEnum::ARCADE->value,
            'C' => GameTypeEnum::TABLE->value,
        ];

        $scheme = parse_url($this->infoDetail->API_URL, PHP_URL_SCHEME);
        $host = parse_url($this->infoDetail->API_URL, PHP_URL_HOST);
        $domain = "{$scheme}://{$host}";

        $data = [];
        foreach ($response['games'] ?? [] as $game) {
            $gameCode = (string) $game['gameCode'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => empty($game['thumbnail']) ? '' : $domain . $game['thumbnail'],
                'gameType' => $typeMap[$gameCode[0] ?? ''] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            LanguageEnum::EN->value => 'en_US',
            LanguageEnum::ZH_TW->value => 'zh_CN',
            LanguageEnum::ZH_CN->value => 'zh_CN',
            LanguageEnum::ID_ID->value => 'id_ID',
            LanguageEnum::VI_VN->value => 'vi_VN',
            LanguageEnum::TH_TH->value => 'th_TH',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_spadegaming_account_single_wallet）
     *
     * SG 沒有獨立建號 API：以 getAuthorize（帶 acctInfo）觸發自動開號，
     * 建號後再以 checkAcct 回查確認落地（同舊系統三段式）。
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

        try {
            // 已存在視為建號成功（帳號池重跑冪等）
            if (!$this->vendorAccountExists($username)) {
                $this->createVendorAccount($username);

                // getAuthorize 回 Success 不代表帳號真的落地，回查一次確認
                if (!$this->vendorAccountExists($username)) {
                    throw new ApiException("[SpadeGamingSW][createAccount] creation done but account not exists: {$username}");
                }
            }
        } catch (Throwable $e) {
            Log::error('[SpadeGamingSW][createAccount] failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 三方是否已存在此帳號
     */
    private function vendorAccountExists(string $username): bool
    {
        $response = $this->sendApi('checkAcct', [
            'acctId' => $username,
            'merchantCode' => $this->infoDetail->Merchant_Code,
        ]);

        return (int) ($response['code'] ?? -1) === 0 && ($response['msg'] ?? '') === 'Success';
    }

    /**
     * 建立三方遊戲商帳號（getAuthorize 帶 acctInfo 觸發自動開號）
     */
    private function createVendorAccount(string $username): void
    {
        $token = [REDACTED] . $this->infoDetail->Merchant_Code);

        $response = $this->sendApi('getAuthorize', [
            'merchantCode' => $this->infoDetail->Merchant_Code,
            'acctInfo' => [
                'acctId' => $username,
                'currency' => $this->infoDetail->CURRENCY,
            ],
            'language' => 'en_US',
            'token' => $token,
            'lobby' => 'SG',
            'acctIp' => '0.0.0.0',
            'fun' => 'false',
            'mobile' => 'true',
            'menuMode' => 'true',
            'fullScreen' => 'true',
        ]);

        if (($response['token'] ?? null) !== $token) {
            throw new ApiException('[SpadeGamingSW][createAccount] response token verify failed');
        }

        if ((int) ($response['code'] ?? -1) !== 0 || ($response['msg'] ?? '') !== 'Success') {
            throw new ApiException(sprintf(
                '[SpadeGamingSW][createAccount] failed, username: %s, code: %s, msg: %s',
                $username,
                $response['code'] ?? '',
                $response['msg'] ?? '',
            ));
        }
    }

    /**
     * 與 getGameUrl 相同的送出慣例：動作放 header API，Digest = md5(json_body + Security_key)，
     * 回應 serialNo 必須回填一致
     *
     * @param  array<string, mixed>  $postData
     * @return array<string, mixed>
     */
    private function sendApi(string $api, array $postData): array
    {
        $postData['serialNo'] = time() . rand(10, 99);
        $digest = md5(json_encode($postData) . $this->infoDetail->Security_key);

        $response = Http::logAction('SpadeGamingSW_api_call_' . $api)
            ->withHeaders([
                'API' => $api,
                'DataType' => 'JSON',
                'Digest' => $digest,
                'Content-Type' => 'application/json',
            ])
            ->post($this->infoDetail->API_URL . $api, $postData)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("[SpadeGamingSW][{$api}] response is not array");
        }

        if (($response['serialNo'] ?? null) !== $postData['serialNo']) {
            throw new ApiException("[SpadeGamingSW][{$api}] serialNo not matched");
        }

        return $response;
    }
}
