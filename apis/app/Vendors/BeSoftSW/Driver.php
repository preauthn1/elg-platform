<?php

declare(strict_types=1);

namespace App\Vendors\BeSoftSW;

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
use App\Helpers\Common;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\BeSoftSWAccount;
use App\Vendors\Models\BeSoftSWBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = BeSoftSWAccount::class;

    protected string $betRecordModel = BeSoftSWBetRecord::class;

    protected string $actionTitle = 'BeSoft';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 besoft 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/besoft';
    }

    public function setInfo(array $info): void
    {
        // info 透過 $this->vendorInfo->info 直接存取，無需額外解析
    }

    /**
     * 遊戲清單（三方無類型欄位，一律 SLOTS；圖檔以 IMAGE_URL 拼 gid 組成）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $info = $this->vendorInfo->info;
        $url = rtrim($info['API_URL'], '/') . '/api/game/subgamelist';

        $response = Http::logAction($this->actionTitle . '_getGameList')
            ->get($url, ['extension1' => $info['Merchant_Code']])
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException('Third party error: not json response');
        }

        $imageUri = $info['IMAGE_URL'] ?? '';

        $data = [];
        foreach ($response['gamelist'] ?? [] as $game) {
            $gameCode = (string) $game['gid'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => sprintf('%s/%s_game_icon_300x300_en.png', $imageUri, $gameCode),
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);
            $gameInfo->setGameNames(LanguageEnum::EN->value, $game['name']);
            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $info = $this->vendorInfo->info;

        $postData = [
            'cert' => $info['Security_key'],
            'user' => $this->vendorAccount->username,
            'extension1' => $info['Merchant_Code'],
        ];

        $action = $this->actionTitle . '_getKey';
        $url = rtrim($info['API_URL'], '/') . '/api/game/getKey';

        $response = Http::logAction($action, $postData)
            ->asForm()
            ->post($url, $postData)
            ->throw()
            ->json();

        if (!is_array($response) || ($response['status'] ?? null) != 1) {
            $status = $response['status'] ?? 'null';
            throw new ApiException("[BeSoft][getGameUrl] getKey failed, status: {$status}");
        }

        $key = $response['key'] ?? '';

        if (empty($key)) {
            throw new ApiException('[BeSoft][getGameUrl] getKey returned empty key');
        }

        $gameUrl = rtrim($info['GAME_URL'], '/') . '/?' . http_build_query([
            'key' => $key,
            'gid' => $params->gameCode,
            'lang' => $this->getLanguage($params->language->value),
        ]);

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function getLanguage(int $languageId): string
    {
        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'zh',
            LanguageEnum::MS_MY->value => 'ms',
            LanguageEnum::ID_ID->value => 'id',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::MY_MM->value => 'my',
            default => 'en',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_besoft_account）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 本 driver 的 setInfo 為空實作，info 一律走 $this->vendorInfo->info
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
     *
     * BeSoft 無獨立註冊 API：getKey 首次帶入新 user 即隱式建號；
     * 與 getGameUrl 同 endpoint、同參數，建號送的 user 與登入鍵天然一致。
     */
    private function createVendorAccount(string $username): bool
    {
        $info = $this->vendorInfo->info;
        $action = $this->actionTitle . '_CreateAccount';
        $url = rtrim($info['API_URL'], '/') . '/api/game/getKey';

        $postData = [
            'cert' => $info['Security_key'],
            'user' => $username,
            'extension1' => $info['Merchant_Code'],
        ];

        try {
            $response = Http::logAction($action, $postData)
                ->asForm()
                ->post($url, $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error("[BeSoft][{$action}] request failed", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        // 弱比較（口徑同 getGameUrl）：三方可能回字串 "1"
        if (!is_array($response) || ($response['status'] ?? null) != 1) {
            Log::error("[BeSoft][{$action}] getKey failed", [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}
