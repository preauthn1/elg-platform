<?php

declare(strict_types=1);

namespace App\Vendors\SexySlotCq9SW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\SexySlotCq9SwAccount;
use App\Vendors\Models\SexySlotCq9SwBetRecord;
use App\Vendors\SexySlotCq9SW\Datas\VendorInfoDetailData;
use App\Vendors\SexySlotCq9SW\Enums\VendorGameTypeEnum;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    public const array ALLOWED_VENDOR_GAME_TYPES = [
        VendorGameTypeEnum::SEXY_SLOT,
        VendorGameTypeEnum::SEXY_FISH,
        VendorGameTypeEnum::SEXY_ARCADE,
    ];

    protected string $accountModel = SexySlotCq9SwAccount::class;

    protected string $betRecordModel = SexySlotCq9SwBetRecord::class;

    private SexySlotCq9SwAccount $account;

    private VendorInfoDetailData $detailInfo;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
        $this->account = $this->vendorAccount;
        $this->detailInfo = VendorInfoDetailData::from($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 sexyslotcq9 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/sexyslotcq9';
    }

    public function setInfo(array $info): void
    {
        $this->detailInfo = VendorInfoDetailData::from($info);
    }

    /**
     * 建立我方遊戲商帳號：CQ9 GameBoy 協定不需要預先向廠商註冊，帳號在本地建立即可
     */
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
        $gameInfo = json_decode($params->info, true);
        if (empty($gameInfo)) {
            throw new \Exception('Game info is empty, please check the value');
        }

        $postData = [
            'account' => $this->account->username,
            'gamehall' => $gameInfo['gamehall'],
            'gamecode' => $gameInfo['gamecode'],
            'gameplat' => 'web',
            'lang' => $this->mapLanguageCode($params->language),
        ];

        $this->check();

        $header = [
            'Authorization' => $this->detailInfo->token,
        ];

        $response = Http::logAction('sexyslotcq9sw_api_call_game_url')
            ->asForm()
            ->withHeaders($header)
            ->post($this->detailInfo->apiUrl . '/gameboy/player/sw/gamelink', $postData)
            ->throw()
            ->json();

        if (!is_array($response) || empty($response)) {
            throw new ApiException('[SexySlotCq9SW][getGameUrl] response is not valid format');
        }

        if ($response['status']['code'] != 0) {
            throw new ApiException('[SexySlotCq9SW][getGameUrl] ' . $response['status']['message']);
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['data']['url'],
            'mobile' => $response['data']['url'],
        ]);
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $this->check();

        $header = [
            'Authorization' => $this->detailInfo->token,
        ];

        $hallsResponse = Http::logAction('sexyslotcq9sw_api_call_game_halls')
            ->withHeaders($header)
            ->get($this->detailInfo->apiUrl . '/gameboy/game/halls')
            ->throw()
            ->json();

        if (!is_array($hallsResponse) || empty($hallsResponse['data'])) {
            Log::error('[SexySlotCq9SW][getGameList] halls response is not valid format');

            return new GetGameListResultData(gameList: []);
        }

        $gameList = [];
        foreach ($hallsResponse['data'] as $hall) {
            $gamehall = $hall['gamehall'] ?? '';
            if ($gamehall === '') {
                continue;
            }

            $listResponse = Http::logAction('sexyslotcq9sw_api_call_game_list')
                ->withHeaders($header)
                ->get($this->detailInfo->apiUrl . '/gameboy/game/list/' . $gamehall)
                ->throw()
                ->json();

            if (!is_array($listResponse) || empty($listResponse['data'])) {
                continue;
            }

            foreach ($listResponse['data'] as $game) {
                $gamecode = $game['gamecode'] ?? '';
                if ($gamecode === '' || ($game['status'] ?? false) !== true) {
                    continue;
                }

                // 廠商同一 hall 混回 CQ9 通用遊戲（slot / fish / arcade 等），只收 sexy 系列
                /** @var VendorGameTypeEnum|null $gameType */
                $gameType = VendorGameTypeEnum::tryFrom($game['gametype'] ?? '');
                if (!in_array($gameType, self::ALLOWED_VENDOR_GAME_TYPES, true)) {
                    continue;
                }

                $gameInfo = GameInfoData::from([
                    'gameCode' => $gamecode,
                    'gameName' => $game['gamename'] ?? $gamecode,
                    'imageUrl' => '',
                    'gameType' => $gameType->toGameType()->value,
                    'status' => 1,
                    'info' => json_encode(['gamehall' => $gamehall, 'gamecode' => $gamecode]),
                ]);

                foreach ($game['nameset'] ?? [] as $nameset) {
                    $langId = $this->mapGameLanguageId($nameset['lang'] ?? '');
                    if ($langId !== null && ($nameset['name'] ?? '') !== '') {
                        $gameInfo->setGameNames($langId, $nameset['name']);
                    }
                }

                $gameList[$gamehall . '_' . $gamecode] = $gameInfo;
            }
        }

        return new GetGameListResultData(gameList: $gameList);
    }

    private function mapGameLanguageId(string $langCode): ?int
    {
        return match ($langCode) {
            'en' => LanguageEnum::EN->value,
            'zh-tw' => LanguageEnum::ZH_TW->value,
            'zh-cn' => LanguageEnum::ZH_CN->value,
            'vn' => LanguageEnum::VI_VN->value,
            'th' => LanguageEnum::TH_TH->value,
            default => null,
        };
    }

    private function check(): void
    {
        if (empty($this->detailInfo)) {
            throw new ApiException('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($this->detailInfo->apiUrl)) {
            throw new ApiException("VendorInfo detail's apiUrl is empty, please check the value");
        }

        if (empty($this->detailInfo->token)) {
            throw new ApiException("VendorInfo detail's token is empty, please check the value");
        }
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-cn',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::VI_VN->value => 'vn',
            LanguageEnum::TH_TH->value => 'th',
            default => 'en',
        };
    }
}
