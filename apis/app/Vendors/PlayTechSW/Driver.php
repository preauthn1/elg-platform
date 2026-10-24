<?php

declare(strict_types=1);

namespace App\Vendors\PlayTechSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\HasEntryGames;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\NeedsGameListSourceUrl;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PlayTech 單一錢包 Driver。
 *
 * 遊戲清單只實作 HasGameList、不標 ShouldSyncGameList：來源是 PT 人工維護的
 * SharePoint 活頁簿（見 GameListSheet），更新頻率低且連結可能失效，
 * 不適合掛 hourly 排程，改由 update_game_list --vendor-id 或 Olympus 系統操作頁手動觸發。
 *
 * 活頁簿連結不落 config／env（見 NeedsGameListSourceUrl），由觸發者以 --sheet-url 帶入。
 *
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, HasEntryGames, HasGameList, NeedsGameListSourceUrl, ShouldScheduleAccountCreation
{
    /**
     * 真人大廳入口代碼。
     *
     * `ubal` 是 PT 官方的真人大廳代碼（Direct to Live Lobby），我方以一列 games
     * （type_id = LIVE_CASINO、name = PlayTech）當作前台進入 PT 真人的入口。
     * 本 Driver 的 getGameList 只回電子與爆點分頁，它永遠不會出現在清單裡，
     * 不排除的話每次同步都會被判成「三方未提供但仍啟用」而固定誤報一次——
     * 假警報看久了就會連真的下架也一起忽略。
     */
    private const array ENTRY_GAME_CODES = ['ubal'];

    protected string $accountModel = PtswAccount::class;

    protected string $betRecordModel = PtswBetRecord::class;

    protected string $actionTitle = 'PTSW';

    protected array $config = [];

    /** @var array<string, GameInfoData>|null 本次同步的遊戲清單（逐商戶呼叫共用，見 getGameList） */
    private ?array $gameList = null;

    /** 本次同步的活頁簿連結；由 setGameListSourceUrl() 於同步前注入 */
    private ?string $gameListSourceUrl = null;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 pt 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/pt';
    }

    public function setInfo(array $info): void
    {
        $this->config = $info;
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $account = $this->vendorAccount;
        $serverName = $this->config['SERVER_NAME'];
        $kioskKey = $this->config['KIOSK_KEY'];
        $isMobile = $params->deviceType === DeviceTypeEnum::MOBILE;
        $languageId = $this->getLanguage($params->language->value);
        $gameCode = $params->gameCode;

        $username = $account->username;
        $prefix = explode('_', $username)[0];
        if (!$account->token || !Str::startsWith($account->token, $prefix)) {
            $account->token = [REDACTED] . '_' . Str::random(32);
            $account->save();
        }

        $headers = [
            'x-auth-kiosk-key' => $kioskKey,
        ];

        $postData = [
            'requestId' => Str::uuid()->toString(),
            'serverName' => $serverName,
            'username' => $account->username,
            'gameCodeName' => $gameCode,
            'clientPlatform' => $isMobile ? 'mobile' : 'web',
            'externalToken' => $account->token,
            'language' => $languageId,
        ];

        $action = $this->actionTitle . '_api_call_getGameUrl';
        $url = $this->config['API_URL'] . '/from-operator/getGameLaunchUrl';
        $response = Http::logAction($action)->withHeaders($headers)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['code'] ?? '') !== 200) {
            $code = $json['code'] ?? '';
            $error = $json['message'] ?? '';
            $error = 'Third party error: (' . $code . ') ' . $error;
            throw new ApiException($error);
        }
        $url = $json['data']['url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    public function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::EN->value => 'en',
            LanguageEnum::ZH_TW->value => 'ch',
            LanguageEnum::ZH_CN->value => 'zh-cn',
            LanguageEnum::MS_MY->value => 'ms',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::TA->value => 'ta',
            LanguageEnum::TL_PH->value => 'tl',
            LanguageEnum::MY_MM->value => 'my',
            default => 'en',
        };
    }

    /**
     * DB 儲存的帳號 prefix 帶底線分隔（createAccount 以 `<account_prefix>_<8 位流水號>` 建號），
     * 不覆寫會讓流水號解析成 `_00000001` 而 CAST 成 0，每輪都重建同一組帳號撞 username 唯一鍵。
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
    {
        return $vendorInfo->account_prefix . '_';
    }

    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 取目前帳號最大值
        $latestNum = $params->serialNumber;

        // 注入遊戲商戶資料
        $vendorInfo = $params->vendorInfo;
        $this->setInfo($vendorInfo->info);
        $prefix = $vendorInfo->account_prefix;

        // 帳號規則，前綴加上8位數字
        $username = $prefix . '_' . str_pad((string) ($latestNum + 1), 8, '0', STR_PAD_LEFT);
        $password = [REDACTED] . '_' . Str::random(12);

        $data = [
            'vendor_info_id' => $vendorInfo->id,
            'username' => $username,
            'token' => $password,
        ];
        $this->accountModel::create($data);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 取得遊戲清單（來源為 PT 官方 SharePoint 活頁簿，非 API）
     *
     * 目前取「電子」與「爆點」兩個分頁，分頁對應的我方遊戲類型見
     * GameListSheet::SHEET_GAME_TYPES；要多開類型在那裡加分頁即可。
     *
     * 活頁簿連結未注入即拋出：靜默回空清單會讓 GameListSyncService 誤判「三方未提供」。
     *
     * 清單與商戶無關，每個商戶都是同一份；GameListSyncService::syncVendor() 會逐商戶
     * 呼叫本方法，故以實例層 memo 快取，同一次同步只下載與解析一次。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        if ($this->gameListSourceUrl === null) {
            throw new ApiException('PTSW game list: source url is required (pass --sheet-url)');
        }

        $this->gameList ??= (new GameListSheet($this->gameListSourceUrl))->readGames();

        return GetGameListResultData::from(['gameList' => $this->gameList]);
    }

    public function setGameListSourceUrl(string $url): void
    {
        $this->gameListSourceUrl = $url;
    }

    /**
     * @return list<string>
     */
    public function entryGameCodes(): array
    {
        return self::ENTRY_GAME_CODES;
    }
}
