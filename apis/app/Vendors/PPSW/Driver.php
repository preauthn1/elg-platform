<?php

declare(strict_types=1);

namespace App\Vendors\PPSW;

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
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\EventPpswAccount;
use App\Vendors\Models\PpswAccount;
use App\Vendors\Models\PpswBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = PpswAccount::class;

    protected string $betRecordModel = PpswBetRecord::class;

    /**
     * 一般會員流程放 {@see PpswAccount}，舊版活動流程放 {@see EventPpswAccount}；
     * 兩者是平行的 AbstractAccount 子類別
     */
    protected AbstractAccount $account;

    protected array $config = [];

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 pp 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/pp';
    }

    public function setInfo(array $info): void
    {
        $config = [
            'apiUrl' => $info['API_URL'] ?? '',
            'secureLogin' => $info['SECURE_LOGIN'] ?? '',
            'secretKey' => $info['SECRET_KEY'] ?? '',
            'currency' => $info['CURRENCY'] ?? 'MYR',
            'country' => $info['COUNTRY'] ?? 'MY',
        ];
        $this->config = $config;
    }

    /**
     * 建構子只在有 member 時才把 vendorInfo/vendorAccount 轉存到本類別的屬性，
     * 舊版活動情境（`member: null` 建構）需在此補做同一件事，見 {@see BaseAbstractVendor::useLegacyEventContext()}
     */
    public function useLegacyEventContext(EventVendorInfo $eventVendorInfo, AbstractAccount $eventAccount): void
    {
        parent::useLegacyEventContext($eventVendorInfo, $eventAccount);

        if (!($eventAccount instanceof EventPpswAccount)) {
            throw new VendorSetupException(static::class . '::useLegacyEventContext requires an EventPpswAccount');
        }

        $this->setInfo($eventVendorInfo->info);
        $this->account = $eventAccount;
    }

    /**
     * 登入遊戲網址
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        /** @var PpswAccount|EventPpswAccount $account */
        $account = $this->account;
        $token = [REDACTED];
        $account->update(['token' => $token]);

        $url = $this->config['apiUrl'] . '/game/url/';

        $data = [
            'secureLogin' => $this->config['secureLogin'],
            'symbol' => $params->gameCode,
            'language' => $this->mapLanguageCode($params->language),
            'token' => $token,
            'externalPlayerId' => $account->username,
            'currency' => $account->currency,
            'country' => $account->country,
            'platform' => $params->deviceType === DeviceTypeEnum::MOBILE ? 'MOBILE' : 'WEB',
            'lobbyUrl' => $params->exitUrl,
        ];

        $data['hash'] = $this->calculateHash($data, $this->config['secretKey']);

        $response = Http::logAction('PPSW_getGameUrl', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response) || (isset($response['error']) && $response['error'] != 0)) {
            throw new ApiException($response['description'] ?? 'Unknown error');
        }

        if (!isset($response['gameURL'])) {
            throw new ApiException($response['description'] ?? $response['error']);
        }

        $gameUrl = $response['gameURL'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 計算 Hash
     */
    private function calculateHash(array $params, string $secretKey): string
    {
        // 複製並移除 hash 欄位（若存在）
        if (array_key_exists('hash', $params)) {
            unset($params['hash']);
        }

        // 按鍵名字母順序排序
        ksort($params);

        // 組成 key=value&key2=value2 的字串（不做額外編碼）
        $baseString = '';
        foreach ($params as $key => $value) {
            $baseString .= $key . '=' . $value . '&';
        }
        $baseString = trim($baseString, '&');

        // 接上密鑰並 MD5
        $toHash = $baseString . $secretKey;

        return md5($toHash);
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
            LanguageEnum::MS_MY->value => 'ms',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::TA->value => 'ta',
            LanguageEnum::TL_PH->value => 'tl',
            // LanguageEnum::MY_MM->value => 'my',
            // LanguageEnum::HI_IN->value => '',
            // LanguageEnum::TPI->value => '',
            // LanguageEnum::HO->value => '',
            default => 'en',
        };
    }

    // ================= 排程任務 =================

    /**
     * 創建遊戲帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 注入遊戲商戶資料
        $vendorInfo = $params->vendorInfo;
        $this->setInfo($vendorInfo->info);

        // username 由 VendorAccountPoolService 以現有最大流水號 +1 組出，直接沿用；
        // 原本在此以 $params->serialNumber（未 +1 的舊流水號）重組會撞出跟既有帳號相同的
        // username，且 ppsw_accounts.username 只有一般 index 非 unique，不會報錯而是無聲產生
        // 重複列，流水號永遠卡在同一個數字
        $username = $params->username;

        // 建立我方玩家帳號
        $data = [
            'vendor_info_id' => $vendorInfo->id,
            'username' => $username,
            'currency' => $this->config['currency'],
            'country' => $this->config['country'],
        ];

        $account = $this->accountModel::create($data);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        // 在遊戲商註冊玩家帳號
        return $this->createVendorAccount($account);
    }

    private function createVendorAccount(PpswAccount $account): CreateAccountResultData
    {
        $url = $this->config['apiUrl'] . '/player/account/create/';

        $data = [
            'secureLogin' => $this->config['secureLogin'],
            'externalPlayerId' => $account->username,
            'currency' => $account->currency,
        ];

        $data['hash'] = $this->calculateHash($data, $this->config['secretKey']);

        $result = Http::logAction('PPSW_createVendorAccount', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result) || !isset($result['error']) || $result['error'] !== '0') {
            $account->update([
                'member_id' => -999999999,
            ]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $account->username,
            ]);
        }

        // ! 紀錄遊戲方的玩家 ID
        $account->update([
            'player_id' => $result['playerId'],
        ]);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $account->username,
        ]);
    }

    /**
     * 更新遊戲資訊
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        // 注入遊戲商戶資料
        $vendorInfo = $params->vendorInfo;
        $this->setInfo($vendorInfo->info);

        $data = [
            'secureLogin' => $this->config['secureLogin'],
        ];

        $data['hash'] = $this->calculateHash($data, $this->config['secretKey']);

        $url = $this->config['apiUrl'] . '/getCasinoGames/';

        $result = Http::logAction('PPSW_getGameList', $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result) || !isset($result['error']) || $result['error'] !== '0') {
            $errorMsg = 'PPSW_getGameList error: ' . ($result['description'] ?? 'Unknown error');
            Log::error($errorMsg);

            return $this->formatGameList([]);
        }

        return $this->formatGameList($result['gameList']);
    }

    private function formatGameList(array $gameList): GetGameListResultData
    {
        Log::info('PPSW_formatGameList started', ['total_games' => count($gameList)]);

        $data = [];
        $inaccessibleCount = 0;

        foreach ($gameList as $game) {
            $gameCode = $game['gameID'];
            $gameType = $this->convertTypeId($game['gameTypeID']);

            /**
             * 只有 NB 有圖
             * https://example.invalid/gs2c/common/lobby/v1/apps/slots-lobby-assets/vs20saiman/vs20saiman_200x200_NB.png
             * https://example.invalid/gs2c/common/lobby/v1/apps/slots-lobby-assets/vs20saiman/vs20saiman_200x200_NB_ZH.png
             */
            $status = 1;
            $size = '200x200'; // 138x138, 160x115, 168x112, 188x83, 200x200, 216x160, 260x350
            $imageUrl = sprintf('https://example.invalid/gs2c/common/lobby/v1/apps/slots-lobby-assets/%s/%s_%s_NB.png', $gameCode, $gameCode, $size);
            // 檢查圖片是否可訪問，如果不可訪問則設為下架狀態
            // if (!Common::isImageAccessible($imageUrl)) {
            //     $status = 0;
            //     $inaccessibleCount++;
            // }

            $newGame = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => $imageUrl,
                'gameType' => $gameType,
                'status' => $status,
            ]);
            $newGame->info = json_encode($game);

            // $newGame->setGameNames(2, $gameInfo['gameName']);
            // $newGame->setGameNames(3, $gameInfo['gameName']);

            $data[$gameCode] = $newGame;
        }

        Log::info('PPSW_formatGameList completed', [
            'total_games' => count($data),
            'inaccessible_images' => $inaccessibleCount,
        ]);

        return GetGameListResultData::from([
            'gameList' => $data,
        ]);
    }

    /**
     * PP 遊戲類型映射：
     * - 視頻／桌面／經典等老虎機：vs, cs, bn, sc -> 2
     * - 真人視訊類：bj, lg, bc, rl -> 1
     * - 虚拟体育：vp-> 18
     * 其他未列出的類型回傳 0
     */
    private function convertTypeId(string $gameType): int
    {
        $key = strtolower(trim($gameType));

        static $map = [
            // 真人視訊
            'bj' => GameTypeEnum::LIVE_CASINO->value,
            'lg' => GameTypeEnum::LIVE_CASINO->value,
            'bc' => GameTypeEnum::LIVE_CASINO->value,
            'rl' => GameTypeEnum::LIVE_CASINO->value,
            // 電子遊戲
            'vs' => GameTypeEnum::SLOTS->value,
            'cs' => GameTypeEnum::SLOTS->value,
            'bn' => GameTypeEnum::SLOTS->value,
            'sc' => GameTypeEnum::SLOTS->value,
            // 體育
            'vp' => GameTypeEnum::SPORTS->value,
        ];

        return $map[$key] ?? 0;
    }
}
