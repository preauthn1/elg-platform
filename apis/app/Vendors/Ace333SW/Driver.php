<?php

declare(strict_types=1);

namespace App\Vendors\Ace333SW;

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
use App\Models\Game;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Ace333SwAccount;
use App\Vendors\Models\Ace333SwBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use phpseclib3\Crypt\DES;
use Throwable;

/**
 * Ace333SW 109
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = Ace333SwAccount::class;

    protected string $betRecordModel = Ace333SwBetRecord::class;

    private string $secretKey;

    private string $currency;

    private string $gameUrl;

    private string $delimiter;

    private string $encryptKey;

    private string $md5EncryptKey;

    private string $token;

    private string $authUrl;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 ace333sw 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/ace333sw';
    }

    public function setInfo(array $info): void
    {
        $this->secretKey = $info['CLIENT_H5_SECRET_KEY'];
        $this->currency = $info['CURRENCY'];
        $this->gameUrl = $info['CLIENT_GAME_URL'];
        $this->delimiter = $info['CLIENT_H5_DEKIMITER'];
        $this->encryptKey = $info['CLIENT_H5_ENCRYPT_KEY'];
        $this->md5EncryptKey = $info['CLIENT_H5_MD5_KEY'];
        $this->token = [REDACTED]'CLIENT_SECRET'];
        $this->authUrl = $info['CLIENT_H5_AUTH_URL'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        /** @var Ace333SwAccount $account */
        $account = $this->vendorAccount;
        $lang = $this->mapLanguageCode();

        $data = [
            'key' => $this->secretKey,
            'time' => now()->format('YmdHis'),
            'userName' => $account->vendor_username,
            'password' => $account->password,
            'currency' => $this->currency,
            'nickName' => $account->username,
        ];

        $accessToken = [REDACTED];

        // 需要更新帳戶 session_token
        $account->update([
            'session_token' => $this->newSessionToken(),
        ]);

        // 查詢遊戲名稱
        $game = Game::query()
            ->select('name')
            ->where('vendor_id', $this->vendor->id)
            ->where('game_type', $params->gameCode)
            ->where('status', 1)
            ->first();

        if (!$game) {
            throw new ApiException('Game not found', 404);
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        $gameUrl = $this->gameUrl . '/' . $game->name . '/?';
        $gameUrl .= 'actk=' . $accessToken;
        $gameUrl .= '&lang=' . $lang;
        $gameUrl .= '&userName=' . $account->vendor_username;
        $gameUrl .= '&returnUrl=' . $referer;

        Log::debug('$gameUrl => ' . $gameUrl);

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * 遊戲清單（DES-CBC 加密 QS + md5 簽章走 GET；三方僅提供英文名 gname）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $currTime = now('UTC')->format('YmdHis');
        $queryString = "key={$this->secretKey}{$this->delimiter}time={$currTime}{$this->delimiter}gameType=1,2,3,4,5,6,7";

        $des = new DES('CBC');
        $des->setKey($this->encryptKey);
        $des->setIV($this->encryptKey);
        $encryptedQuery = base64_encode($des->encrypt($queryString));

        $signature = md5($queryString . $this->md5EncryptKey . $currTime . $this->secretKey);

        $url = $this->authUrl . '/api/Game/GameList/';
        $response = Http::logAction($this->getName() . 'getGameList')
            ->withHeaders(['token' => $this->token])
            ->get($url, [
                'q' => $encryptedQuery,
                's' => $signature,
                'accessToken' => $this->token,
            ])
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException('Third party error: not json response');
        }

        if (($response['status'] ?? 0) != 1) {
            throw new ApiException('Third party error: ' . ($response['description'] ?? 'Unknown error'));
        }

        // gtyp: 1=Slot, 2=Arcade, 3=Table, 4=Fishing, 5=Marvel slot, 7=Classic Slot（混用字串與數字型別）
        $typeMap = [
            'live' => GameTypeEnum::LIVE_CASINO->value,
            1 => GameTypeEnum::SLOTS->value,
            5 => GameTypeEnum::SLOTS->value,
            7 => GameTypeEnum::SLOTS->value,
            3 => GameTypeEnum::POKER->value,
            'lottery' => GameTypeEnum::LOTTERY->value,
            4 => GameTypeEnum::FISHING->value,
            'cockfighting' => GameTypeEnum::COKE_FIGHTING->value,
            2 => GameTypeEnum::ARCADE->value,
            'sports' => GameTypeEnum::SPORTS->value,
            'runlight' => GameTypeEnum::RUN_LIGHT->value,
        ];

        $gameIconUrl = $this->vendorInfo->info['CLIENT_GAME_ICON_URL'] ?? '';

        $data = [];
        foreach ($response['gamelist'] ?? [] as $game) {
            $gameCode = (string) $game['gId'];
            $gameName = $game['gname'] ?? $gameCode;
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $gameName,
                'imageUrl' => $gameIconUrl . '/' . $gameName . '.png',
                'gameType' => $typeMap[$game['gtyp'] ?? ''] ?? GameTypeEnum::EVENT_AND_OTHERS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function getGameToken(array $data): string
    {
        Log::debug($this->getName() . ' getGameToken data', $data);

        // 組合查詢字串
        $queryString = '';
        foreach ($data as $key => $value) {
            $queryString .= $key . '=' . $value;
            if (end($data) != $value) {
                $queryString .= $this->delimiter;
            }
        }

        $des = new DES('CBC');
        $des->setKey($this->encryptKey);
        $des->setIV($this->encryptKey);
        $encryptedQuery = $des->encrypt($queryString);
        $encryptedQuery = base64_encode($encryptedQuery);
        $encodedQuery = urlencode($encryptedQuery);

        // 產生簽章
        $signature = md5($queryString . $this->md5EncryptKey . $data['time'] . $this->secretKey);

        // 組合 POST 資料
        $postData = [
            'q' => $encodedQuery,
            's' => $signature,
            'accessToken' => $this->token,
        ];

        $url = $this->authUrl . '/api/Acc/Login';

        // 發送請求
        $response = Http::logAction($this->getName() . 'getGameToken')
            ->asJson()
            ->post($url, $postData)
            ->throw()
            ->json();

        // 回傳 token
        if (is_array($response) && $response['status'] == 1) {
            return $response['actk'];
        }

        return '';
    }

    private function newSessionToken(): string
    {
        return str_replace('-', '', Str::uuid()->toString()) . bin2hex(random_bytes(32));

    }

    private function mapLanguageCode(): int
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value) ?? 0;

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 2,
            LanguageEnum::ID_ID->value => 5,
            LanguageEnum::VI_VN->value => 7,
            LanguageEnum::TH_TH->value => 3,
            default => 1,
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ace333sw_account）
     *
     * getGameUrl 與三方回打 authenticate 都比對帳號列的明文密碼，
     * 密碼算式必須與登入路徑一致（getDefaultPassword）；
     * vendor_uid 是三方 callback 定位帳號的鍵，成功後必須寫回。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $info = $params->vendorInfo->info;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'vendor_username' => $username . ($info['CLIENT_CLASS'] ?? ''),
            'session_token' => $this->newSessionToken(),
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update([
            'password' => $this->getDefaultPassword($username, $account->created_at->toDateTimeString()),
        ]);

        $playerId = $this->createVendorAccount($username);

        if ($playerId === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['vendor_uid' => $playerId, 'member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號，成功回傳 playerID，失敗回傳 null
     *
     * 舊系統建號用 info 的 AUTH_URL，與登入用的 CLIENT_H5_AUTH_URL 不同，缺值時退回後者
     */
    private function createVendorAccount(string $username): ?string
    {
        $url = rtrim((string) ($this->vendorInfo->info['AUTH_URL'] ?? $this->authUrl), '/') . '/api/createplayer';

        $data = [
            'accountID' => $username,
            'nickname' => $username,
            'currency' => $this->currency,
        ];

        try {
            $response = Http::logAction($this->getName() . '_createAccount')
                ->withHeaders(['token' => $this->token])
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error($this->getName() . '_createAccount failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        if (!is_array($response) || (string) ($response['error'] ?? '') !== '0' || empty($response['playerID'])) {
            Log::error($this->getName() . '_createAccount failed: error is not 0', [
                'username' => $username,
                'response' => $response,
            ]);

            return null;
        }

        return (string) $response['playerID'];
    }
}
