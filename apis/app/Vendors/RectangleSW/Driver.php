<?php

declare(strict_types=1);

namespace App\Vendors\RectangleSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\RectangleSwAccount;
use App\Vendors\Models\RectangleSwBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = RectangleSwAccount::class;

    protected string $betRecordModel = RectangleSwBetRecord::class;

    private VendorInfoDetailData $infoDetail;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->infoDetail = VendorInfoDetailData::from($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 rectangle 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/rectangle';
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->infoDetail = VendorInfoDetailData::from($params->vendorInfo->info);

        $response = Http::logAction('RectangleSW_getGameList')
            ->get($this->infoDetail->API_URL . '/api/v1/available-games', [
                'oc' => $this->infoDetail->OC,
            ])
            ->throw()
            ->json();

        $gameList = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = $game['code'];
            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['preview'] ?? '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => $game['status'] === 'ACTIVE' ? 1 : 0,
                'info' => json_encode(['url' => $game['url']]),
            ]);
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
        $gameCode = $params->gameCode;
        $exitUrl = $params->exitUrl;

        $game = Game::where('vendor_id', VendorIdEnum::RECTANGLESW->value)
            ->where('game_type', $gameCode)
            ->first();
        $info = json_decode($game->info, true);

        if (!$game || empty($info['url'])) {
            throw new ApiException('Rectangle SW: game not found: ' . $gameCode);
        }

        $sessionToken = [REDACTED];
        $this->vendorAccount->update(['session_token' => $sessionToken]);

        $language = $this->getLanguage($params->language->value);

        $url = $info['url'] . '?' . http_build_query(array_filter([
            'oc' => $this->infoDetail->OC,
            't' => $sessionToken,
            'l' => $language,
            'r' => $exitUrl ?: null,
        ]));

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    private function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_CN->value => 'zh',
            LanguageEnum::ZH_TW->value => 'tzh',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::ID_ID->value => 'id',
            default => 'en',
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $OC,
        public string $SECRET,
        public string $CURRENCY,
    ) {}
}
