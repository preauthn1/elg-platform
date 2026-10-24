<?php

declare(strict_types=1);

namespace App\Vendors\CosmoPlaySW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\CosmoPlaySWAccount;
use App\Vendors\Models\CosmoPlaySWBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @property CosmoPlaySWAccount $vendorAccount
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = CosmoPlaySWAccount::class;

    protected string $betRecordModel = CosmoPlaySWBetRecord::class;

    protected string $actionPrefix = 'CosmoPlaySW_';

    private string $apiUrl;

    private string $h5Url;

    private string $partnerCode;

    private string $jwtSecret;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 cosmoplay 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/cosmoplay';
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = rtrim($info['API_URL'] ?? '', '/');
        $this->h5Url = rtrim($info['H5_URL'] ?? '', '/');
        $this->partnerCode = $info['PARTNER_CODE'] ?? '';
        $this->jwtSecret = [REDACTED]'JWT_SECRET'] ?? '';
    }

    /**
     * 遊戲啟動 URL（Seamless 模式）
     * H5 URL 格式：{h5url}/?pid={partner_code}-{username}&gid={gameCode}&atk={authToken}&lang={lang}&url={exitUrl}
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $this->setInfo($this->vendorInfo->info);

        $authToken = [REDACTED];
        $this->vendorAccount->update(['session_token' => $authToken]);

        $playerId = $this->partnerCode . '-' . $this->vendorAccount->username;

        $data = [
            'pid' => $playerId,
            'gid' => $params->gameCode,
            'atk' => $authToken,
            'lang' => $this->mapLanguage($params->language),
            'url' => $params->exitUrl ?: null,
        ];

        // For production, The queries of H5 domain should within addition value `&sd=gs-sl
        if ($this->vendorInfo->type > 0) {
            $data['sd'] = 'gs-sl';
        }
        $query = http_build_query(array_filter($data));

        $gameUrl = $this->h5Url . '/?' . $query;

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 建立帳號
     * CosmoPlay 的 /nv/create-account 僅供 Wallet transfer 模式使用，Seamless 模式不需呼叫，只需建立本地帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $username = $params->username;

        $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
            'status' => 1,
        ]);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 取得遊戲清單（呼叫 /nv/games）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $this->setInfo($this->vendorInfo->info);

        $payload = ['PartnerCode' => $this->partnerCode];

        $response = Http::logAction($this->actionPrefix . 'getGameList', $payload)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->generateJwt($payload),
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl . '/nv/games', $payload)
            ->throw()
            ->json();

        $results = $response['Results'] ?? [];

        $gameList = [];
        foreach ($results as $game) {
            $gameCode = (string) ($game['GameID'] ?? '');
            $gameName = (string) ($game['GameName'] ?? '');

            if (empty($gameCode)) {
                continue;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $gameName,
                'imageUrl' => '',
                'gameType' => GameTypeEnum::SLOTS->value, // Slot（預設，遊戲商未提供分類）
                'status' => 1,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    /**
     * 生成用於呼叫遊戲商 API 的 JWT（HS256）
     * 規格：claims 必須帶入完整 payload
     */
    public function generateJwt(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signingInput = $header . '.' . $payloadEncoded;
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $signingInput, $this->jwtSecret, true));

        return $signingInput . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function mapLanguage(?LanguageEnum $language): string
    {
        // English: en
        // Korean: ko
        // Mongolian: mn
        // Chinese (Simplified): zh
        // Hindi: hi
        // Indonesian: id
        // Japanese: ja
        // Thai: th
        // Vietnamese: vi
        // Russian: ru
        // Turkish: tr
        // Spanish: es
        // Portuguese: pt
        return match ($language) {
            LanguageEnum::ZH_TW => 'zh',
            LanguageEnum::ZH_CN => 'zh',
            LanguageEnum::TH_TH => 'th',
            LanguageEnum::MS_MY => 'ms',
            LanguageEnum::ID_ID => 'id',
            LanguageEnum::VI_VN => 'vi',
            LanguageEnum::HI_IN => 'hi',
            LanguageEnum::RU => 'ru',
            default => 'en',
        };
    }
}
