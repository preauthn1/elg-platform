<?php

declare(strict_types=1);

namespace App\Vendors\SexySlotSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\SexyslotswAccount;
use App\Vendors\Models\SexyslotswBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = SexyslotswAccount::class;

    protected string $betRecordModel = SexyslotswBetRecord::class;

    protected SexyslotswAccount $account;

    protected array $config = [];

    protected string $vendorLabel = 'SexySlotSW';

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 sexyslotsw 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/sexyslotsw';
    }

    public function setInfo(array $info): void
    {
        $this->config = $info;
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
        $token = [REDACTED]'-', '', (string) Str::uuid()));

        $this->account->update(['session_token' => $token]);

        $response = Http::logAction($this->vendorLabel . '_gameLaunch')
            ->withToken($this->getAccessToken())
            ->asForm()
            ->post(rtrim($this->config['API_URL'], '/') . '/game/launchurl', [
                'client_id' => $this->config['CLIENT_ID'],
                'client_player_id' => $this->account->username,
                'username' => $this->account->username,
                'game_code' => $params->gameCode,
                'game_provider' => 'SexySlot',
                'country_code' => 'MY',
                'token' => $token,
                'lang' => $this->mapLanguageCode($params->language),
                'device' => $params->deviceType === DeviceTypeEnum::PC ? 'desktop' : 'mobile',
                'exitUrl' => $params->exitUrl,
            ])
            ->throw()
            ->json();

        if (($response['game_launch'] ?? false) !== true) {
            throw new ApiException(
                sprintf('[%s] Game launch failed: %s', $this->vendorLabel, $response['message'] ?? 'Unknown error')
            );
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['url'],
            'mobile' => $response['url'],
        ]);
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $token = [REDACTED];
        $url = rtrim($this->config['API_URL'], '/') . '/game/list?client_id=' . $this->config['CLIENT_ID'];

        $result = Http::logAction($this->vendorLabel . '_getGameList')
            ->withToken($token)
            ->get($url)
            ->throw()
            ->json();

        if (!is_array($result)) {
            Log::error($this->vendorLabel . '_getGameList error: invalid response');

            return new GetGameListResultData(gameList: []);
        }

        $gameList = [];
        foreach ($result as $provider) {
            $games = $provider['game_list'] ?? $provider['games_list'] ?? [];
            foreach ($games as $game) {
                $gameCode = $game['game_code'] ?? '';
                if ($gameCode === '') {
                    continue;
                }

                $gameList[$gameCode] = GameInfoData::from([
                    'gameCode' => $gameCode,
                    'gameName' => $game['game_name'] ?? $gameCode,
                    'imageUrl' => $game['game_icon'] ?? '',
                    'gameType' => $this->mapGameType($game['game_type'] ?? ''),
                    'status' => 1,
                ]);
            }
        }

        return new GetGameListResultData(gameList: $gameList);
    }

    private function getAccessToken(): string
    {
        $response = Http::logAction($this->vendorLabel . '_oauth')
            ->asForm()
            ->post(rtrim($this->config['API_URL'], '/') . '/oauth/access_token', [
                'username' => $this->config['USERNAME'],
                'password' => $this->config['PASSWORD'],
                'client_id' => $this->config['CLIENT_ID'],
                'client_secret' => $this->config['CLIENT_SECRET'],
                'grant_type' => 'password',
            ])
            ->throw()
            ->json();

        return $response['access_token'] ?? '';
    }

    private function mapGameType(string $gameType): int
    {
        return match ($gameType) {
            'Slots' => GameTypeEnum::SLOTS->value,
            'Arcade' => GameTypeEnum::ARCADE->value,
            'Fish' => GameTypeEnum::FISHING->value,
            default => 0,
        };
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'zh',
            // 廠商遊戲端收到 ms 仍顯示泰文（廠商 bug），暫導向英文避免玩家看到泰文（target/tickets#2438）
            LanguageEnum::MS_MY->value => 'en',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::ID_ID->value => 'id',
            default => 'en',
        };
    }
}
