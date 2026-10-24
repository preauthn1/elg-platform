<?php

declare(strict_types=1);

namespace App\Vendors\Ebet;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\EbetAccount;
use App\Vendors\Models\EbetBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * @knowledge rules/game-list-sync
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = EbetAccount::class;

    protected string $betRecordModel = EbetBetRecord::class;

    private VendorInfoDetailData $detailData;

    private array $slotVendors = ['genesis', 'radi8', 'bunfox'];

    private string $actionPrefix = 'Ebet_';

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $info): void
    {
        $this->detailData = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameUrl = '';
        $timestamp = time();

        // 每次變動降低被盜網址的危險
        $token = [REDACTED], $this->vendorAccount->created_at->toDateTimeString());

        if (in_array($params->remark, $this->slotVendors)) {
            $action = $this->actionPrefix . 'getGameUrl_slot';
            $url = $this->detailData->API_URL . 'loginslot';
            // 老虎機是用其他的API
            $data = [
                'channelId' => $this->detailData->CHANNEL_ID,
                'loginEventType' => 4,
                'timestamp' => $timestamp,
                'gameID' => $params->gameCode,
                'providerId' => $params->remark,
                'signature' => $this->getSign($this->vendorAccount->username . $timestamp),
                'username' => $this->vendorAccount->username,
                'token' => md5($token),
            ];

            $result = Http::logAction($action, $data)
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();

            if (is_array($result) && $result['status'] == 200) {
                $gameUrl = $result['slotURL'];
            }
        } else {
            $action = $this->actionPrefix . 'getGameUrl_live';
            $url = $this->detailData->API_URL . 'launchUrl';
            $urlParams = http_build_query([
                'orientation' => 2,
                'language' => $this->mapLanguageCode($params->language),
                'gameType' => $params->gameCode,
                'username' => $this->vendorAccount->username,
                'accessToken' => md5($token),
            ]);

            if ($this->detailData->USE_API_URL == 1) {
                $data = [
                    'channelId' => $this->detailData->CHANNEL_ID,
                    'timestamp' => $timestamp,
                    'signature' => $this->getSign($this->detailData->CHANNEL_ID . $timestamp),
                    'currency' => $this->detailData->CURRENCY,
                ];

                $result = Http::logAction($action, $data)
                    ->asJson()
                    ->post($url, $data)
                    ->throw()
                    ->json();

                if (is_array($result) && $result['status'] == 200) {
                    $gameUrl = $result['launchUrl'] . '?' . $urlParams;
                }
            } else {
                $gameUrl = $this->detailData->HTML5_URL . '?' . $urlParams;
            }
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $action = $this->actionPrefix . 'getBalance';
        $url = $this->detailData->API_URL . 'userinfo';
        $timestamp = date('U');
        $data = [
            'channelId' => $this->detailData->CHANNEL_ID,
            'subChannelId' => $this->detailData->SUB_CHANNEL_ID,
            'username' => $this->vendorAccount->username,
            'timestamp' => $timestamp,
            'signature' => $this->getSign($this->vendorAccount->username . $timestamp),
            'currency' => $this->detailData->CURRENCY,
        ];

        $response = Http::logAction($action, $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response)) {
            throw new ApiException("$action API call failed: response not array");
        }

        if (isset($response['status']) && $response['status'] != '200') {
            throw new ApiException("$action API call failed");
        }

        if (!isset($response['money'])) {
            throw new ApiException("$action API call failed: money not found");
        }

        if (!is_numeric($response['money'])) {
            throw new ApiException("$action API call failed: money is not numeric");
        }

        return (string) $response['money'];
    }

    /**
     * 遊戲清單（slotgamelist，遷自 RD-Studio get_ebet_games）
     *
     * 三方只提供老虎機清單；真人遊戲不在此 API，沿用舊指令行為不處理。
     * `remark` 必須落 providerId：getGameUrl() 以 `$params->remark` 判斷走 loginslot 並帶給三方。
     * 寫入交給 GameListSyncService（純新增；DB 有、API 無僅偵測提示不停用）。
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $action = $this->actionPrefix . 'getGameList';
        $url = $this->detailData->API_URL . 'slotgamelist';
        $timestamp = time();
        $data = [
            'channelId' => $this->detailData->CHANNEL_ID,
            'timestamp' => $timestamp,
            'signature' => $this->getSign($this->detailData->CHANNEL_ID . $timestamp),
        ];

        $response = Http::logAction($action, $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        // HTTP 層錯誤由上方 throw() 拋出；HTTP 200 但 status 非 200 回空清單並記 log，
        // 呼叫端對空清單會提前 return，不會讓「三方未提供」偵測把整批遊戲誤報
        if (!is_array($response) || (int) ($response['status'] ?? 0) !== 200) {
            Log::error('[Ebet][getGameList] Invalid response: status is not 200', [
                'vendor_id' => $this->vendor->id,
                'vendor_info_id' => $params->vendorInfo->id,
                'status' => $response['status'] ?? null,
            ]);

            return GetGameListResultData::from(['gameList' => []]);
        }

        $gameList = [];
        foreach ($response['slotGames'] ?? [] as $game) {
            // 缺 gameID 的列直接略過：空字串當唯一鍵會互相覆蓋，也會讓「三方未提供」偵測誤判
            if (!isset($game['gameID']) || $game['gameID'] === '') {
                continue;
            }

            $gameCode = (string) $game['gameID'];
            $english = (string) ($game['english'] ?? '');
            $chinese = (string) ($game['chinese'] ?? '');

            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $english,
                'imageUrl' => '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 0,
                'remark' => isset($game['providerId']) ? (string) $game['providerId'] : null,
            ]);

            if ($english !== '') {
                $gameInfo->setGameNames(LanguageEnum::EN->value, $english);
            }
            if ($chinese !== '') {
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $chinese);
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = $this->actionPrefix . 'checkTransferStatus';
        $url = $this->detailData->API_URL . 'rechargestatus';
        $data = [
            'channelId' => $this->detailData->CHANNEL_ID,
            'rechargeReqId' => $transactionNo,
            'signature' => $this->getSign($transactionNo),
            'currency' => $this->detailData->CURRENCY,
        ];

        $loopTime = $this->detailData->API_LOOP_TIME;
        while ($loopTime > 0) {
            $loopTime--;
            $response = Http::logAction($action, $data)
                ->asJson()
                ->post($url, $data)
                ->json();

            // no result yet
            if (!is_array($response) || !isset($response['status'])) {
                sleep($this->detailData->SLEEP_SECOND);

                continue;
            }

            if ($response['status'] == '200') {
                return VendorTransferResultEnum::SUCCESS;
            }

            if ($response['status'] == '5001') {
                return VendorTransferResultEnum::FAILED;
            }

            if ($response['status'] != '-1') { // 其他狀態
                break;
            }

            sleep($this->detailData->SLEEP_SECOND);
        }

        return VendorTransferResultEnum::TIMEOUT; // 不確定狀態
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(1, $params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer(2, $params);
    }

    private function transfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        $url = $this->detailData->API_URL . 'recharge';

        // 轉帳只接受兩位小數
        if ($type == 1) {
            $action = $this->actionPrefix . 'deposit';
            $money = (float) Common::numAdd('0', Common::numFloor($params->vendorAmount, 2));
        } else {
            $action = $this->actionPrefix . 'withdraw';
            $money = (float) Common::numSub('0', Common::numFloor($params->vendorAmount, 2));
        }

        $timestamp = date('U');
        $data = [
            'channelId' => $this->detailData->CHANNEL_ID,
            'subChannelId' => $this->detailData->SUB_CHANNEL_ID,
            'username' => $this->vendorAccount->username,
            'money' => $money,
            'rechargeReqId' => $params->transactionNo,
            'timestamp' => $timestamp,
            'signature' => $this->getSign($this->vendorAccount->username . $timestamp),
            'currency' => $this->detailData->CURRENCY,
            'typeId' => 0,
        ];

        $response = Http::logAction($action, $data)
            ->asJson()
            ->post($url, $data)
            ->json();

        // 不管recharge回傳成功或失敗,檢查ebet此充值紀錄是否存在
        return $this->checkTransferStatus($params->transactionNo);
    }

    private function getSign(string $string)
    {
        $privateKey = openssl_get_privatekey(str_replace('\\n', "\n", $this->detailData->PRIVATE_KEY));
        if (openssl_sign($string, $ret, $privateKey, OPENSSL_ALGO_MD5)) {
            $ret = base64_encode($ret);
        }

        return $ret ?? '';
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        return match ($language) {
            LanguageEnum::ZH_TW => 'zh_tw',
            LanguageEnum::ZH_CN => 'zh_cn',
            LanguageEnum::MS_MY => 'ms_my',
            LanguageEnum::ID_ID => 'in_id',
            default => 'en_us',
        };
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ebet_account）
     *
     * Ebet 登入憑證是 md5(getDefaultPassword(username, created_at)) 即時重算，
     * 不讀 password 欄；建號亦不需送密碼給三方。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // password / admin 皆為 NOT NULL 無 default；登入憑證即時重算不讀本欄
            'password' => '',
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
     * 建立三方遊戲商帳號（syncuser；RSA MD5 簽名，簽名字串只有 username）
     */
    private function createVendorAccount(string $username): bool
    {
        $action = $this->actionPrefix . 'createAccount';
        $url = $this->detailData->API_URL . 'syncuser';

        $data = [
            'channelId' => $this->detailData->CHANNEL_ID,
            'subChannelId' => $this->detailData->SUB_CHANNEL_ID,
            'username' => $username,
            'signature' => $this->getSign($username),
        ];

        try {
            $response = Http::logAction($action, $data)
                ->asJson()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[Ebet][createAccount] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (string) ($response['status'] ?? '') !== '200') {
            Log::error('[Ebet][createAccount] API error: status is not 200', [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $CHANNEL_ID,
        public string $SUB_CHANNEL_ID,
        public string $CURRENCY,
        public string $PUBLIC_KEY,
        public string $PRIVATE_KEY,
        public string $ANDROID_DOWNLOAD_LINK,
        public string $IOS_DOWNLOAD_LINK,
        public string $HTML5_URL,

        public ?int $SLEEP_SECOND = 5,
        public ?int $API_LOOP_TIME = 3,
        public ?int $USE_API_URL = 0
    ) {}
}
