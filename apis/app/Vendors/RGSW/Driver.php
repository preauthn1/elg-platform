<?php

declare(strict_types=1);

namespace App\Vendors\RGSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\RgswAccount;
use App\Vendors\Models\RgswBetRecord;
use App\Vendors\RGSW\Datas\VendorInfoDetailData;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = RgswAccount::class;

    protected string $betRecordModel = RgswBetRecord::class;

    private RgswAccount $account;

    private VendorInfoDetailData $detailInfo;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->detailInfo = VendorInfoDetailData::from($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 rg 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/rg';
    }

    public function setInfo(array $info): void
    {
        $this->detailInfo = VendorInfoDetailData::from($info);
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setInfo($params->vendorInfo->info);

        $apiUrl = rtrim($this->detailInfo->apiUrl, '/') . '/gameList';

        $response = Http::logAction('RGSW_getGameList')
            ->asForm()
            ->post($apiUrl, $this->buildSignedRequest(['appKey' => $this->detailInfo->appKey]))
            ->throw()
            ->json();

        if (!is_array($response) || ($response['success'] ?? false) !== true) {
            throw new ApiException('[RGSW][getGameList] ' . ($response['message'] ?? 'Unknown error'));
        }

        $langMap = [
            'zh_TW' => LanguageEnum::ZH_TW->value,
            'zh_CN' => LanguageEnum::ZH_CN->value,
            'vi' => LanguageEnum::VI_VN->value,
            'en' => LanguageEnum::EN->value,
            'th' => LanguageEnum::TH_TH->value,
        ];

        $typeMap = [
            'slot' => GameTypeEnum::SLOTS->value,
            'fishing' => GameTypeEnum::FISHING->value,
            'chess' => GameTypeEnum::POKER->value,
        ];

        $gameList = [];
        foreach ($response['data'] ?? [] as $item) {
            $gameCode = (string) ($item['code'] ?? '');
            $names = $item['name'] ?? [];
            $gameName = $names['en'] ?? $gameCode;

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $gameName,
                'imageUrl' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/games/RG/' . $gameCode . '.png',
                'gameType' => $typeMap[$item['type'] ?? ''] ?? GameTypeEnum::SLOTS->value,
                'status' => 1,
                'info' => json_encode($item),
            ]);

            foreach ($langMap as $langKey => $langId) {
                if (!empty($names[$langKey])) {
                    $gameInfo->setGameNames($langId, $names[$langKey]);
                }
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $params->username,
        ]);

        return CreateAccountResultData::from([
            'status' => (bool) $account,
            'username' => $params->username,
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $langPath = $this->mapLanguageToPath($params->language);

        $postData = [
            'appKey' => $this->detailInfo->appKey,
            'userName' => $this->account->username,
            'realName' => $this->account->username,
            'platId' => 2,
            'gameCode' => $params->gameCode,
        ];

        $signedData = $this->buildSignedRequest($postData);

        $apiUrl = rtrim($this->detailInfo->apiUrl, '/') . $langPath . '/gameUrlSW';

        $response = Http::logAction('RGSW_api_call_game_url')
            ->asForm()
            ->post($apiUrl, $signedData)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['success'])) {
            throw new ApiException('[RGSW][getGameUrl] Invalid response format');
        }

        if ($response['success'] !== true) {
            throw new ApiException('[RGSW][getGameUrl] ' . ($response['message'] ?? 'Unknown error'));
        }

        $url = $response['data']['url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    private function buildSignedRequest(array $params): array
    {
        ksort($params);
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        $paramsString = '';
        foreach ($params as $key => $value) {
            $paramsString .= "{$key}={$value}&";
        }

        $verifyStr = strtolower(md5($paramsString . $this->detailInfo->appSecret));
        $paramsString .= "verifyStr={$verifyStr}";

        $encrypted = openssl_encrypt($paramsString, 'aes-256-ecb', $this->detailInfo->appSecret, OPENSSL_RAW_DATA);
        $p = base64_encode($encrypted);

        return [
            'ak' => $this->detailInfo->appKey,
            'p' => $p,
        ];
    }

    private function mapLanguageToPath(?LanguageEnum $language): string
    {
        return match ($language) {
            LanguageEnum::VI_VN => '/vi',
            LanguageEnum::TH_TH => '/th',
            LanguageEnum::ZH_CN => '/zh_CN',
            default => '',
        };
    }
}
