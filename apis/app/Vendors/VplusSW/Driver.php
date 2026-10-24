<?php

declare(strict_types=1);

namespace App\Vendors\VplusSW;

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
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\VplusswAccount;
use App\Vendors\Models\VplusswBetRecord;
use App\Vendors\VplusSW\Datas\VendorInfoDetailData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $accountModel = VplusswAccount::class;

    protected string $betRecordModel = VplusswBetRecord::class;

    private VplusswAccount $account;

    private VendorInfoDetailData $infoDetail;

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
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 vplus 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/vplus';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    /**
     * 建立我方遊戲帳號：先落 DB 佔用流水號（member_id = -999999999 表未成功），
     * 三方建號成功才存回 ext_uid，帳號生效（舊系統 RD-Studio `vplussw:create_account` 同行為）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
        ]);

        $extUid = $this->createVendorAccount($username);

        if ($extUid === null) {
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        $account->update(['ext_uid' => $extUid]);

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 呼叫三方建立遊戲帳號，成功回傳三方 uid；三方對已存在的帳號回傳 code=12
     * 「user already exist」，視為成功（沿用舊系統判斷）
     */
    private function createVendorAccount(string $username): ?string
    {
        try {
            $response = $this->send('createAccount', ['username' => $username]);
        } catch (Throwable $e) {
            Log::error('[VplusSW][CreateAccount] Request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $code = (int) ($response['code'] ?? 0);
        $isAlreadyExists = $code === 12 && ($response['msg'] ?? '') === 'user already exist';

        if ($code !== 200 && !$isAlreadyExists) {
            Log::error('[VplusSW][CreateAccount] Invalid response: code is not 200', [
                'username' => $username,
                'code' => $code,
                'message' => $response['msg'] ?? null,
            ]);

            return null;
        }

        return (string) ($response['data']['uid'] ?? '');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {

        $loginResponse = $this->send('player/login', [
            'username' => $this->account->username,
        ]);

        $openGameData = [
            'appId' => $this->infoDetail->APP_ID,
            'timestamp' => (int) (microtime(true) * 1000),
            'back' => base64_encode($params->exitUrl),
            'token' => $loginResponse['data']['token'],
            'id' => $params->gameCode,
            'closeBack' => 1,
            'lang' => $this->mapLanguageCode($params->language),
        ];
        $gameUrl = $this->infoDetail->GAME_LAUNCH_URL . '?' . http_build_query($openGameData) . '&sign=' . $this->makeSign($openGameData);

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh',
            LanguageEnum::ZH_CN->value => 'cn',
            LanguageEnum::MS_MY->value => 'my',
            LanguageEnum::ID_ID->value => 'ida',
            LanguageEnum::VI_VN->value => 'vi',
            LanguageEnum::TH_TH->value => 'th',
            LanguageEnum::MY_MM->value => 'mya',
            default => 'en',
        };
    }

    /**
     * 遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $response = $this->send('gameList');

        if ((int) ($response['code'] ?? 0) !== 200) {
            $code = $response['code'] ?? '';
            $error = $response['msg'] ?? '';
            throw new ApiException('Third party error: (' . $code . ') ' . $error);
        }

        $data = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['id'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['name'],
                'imageUrl' => $game['squareIcon'] ?? '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 1,
                'info' => json_encode($game),
            ]);

            if (!empty($game['nameZh'])) {
                $gameInfo->setGameNames(LanguageEnum::ZH_TW->value, $game['nameZh']);
            }
            if (!empty($game['nameCn'])) {
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $game['nameCn']);
            }

            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function send(string $path, array $postData = [], string $action = ''): ?array
    {
        $action = empty($action) ? "vplussw_SW_api_call_{$path}" : $action;

        $this->check();

        $postData = array_merge($postData, [
            'appId' => $this->infoDetail->APP_ID,
            'timestamp' => (int) (microtime(true) * 1000),
        ]);
        $postData['sign'] = $this->makeSign($postData);

        $header = [
            'Content-Type: application/json',
        ];

        $response = Http::logAction($action)
            ->asJson()
            ->post(trim($this->infoDetail->API_URL, '/') . '/' . trim($path, '/'), $postData)
            ->throw()
            ->json();

        if (empty($response)) {
            throw new \Exception('VendorSend send response is empty');
        }

        return $response;
    }

    private function check(): void
    {
        if (empty($this->infoDetail)) {
            throw new \Exception('VendorInfo detail is empty, use setVendorInfo() to set the value');
        }

        if (empty($this->infoDetail->API_URL)) {
            throw new \Exception("VendorInfo detail's API_URL is empty, please check the value");
        }

        if (empty($this->infoDetail->GAME_LAUNCH_URL)) {
            throw new \Exception("VendorInfo detail's GAME_LAUNCH_URL is empty, please check the value");
        }

        if (empty($this->infoDetail->APP_ID)) {
            throw new \Exception("VendorInfo detail's APP_ID is empty, please check the value");
        }

        if (empty($this->infoDetail->APP_SECRET)) {
            throw new \Exception("VendorInfo detail's APP_SECRET is empty, please check the value");
        }
    }

    private function makeSign(array $postData): string
    {
        unset($postData['sign']);
        ksort($postData);

        $sign = '';
        foreach ($postData as $key => $value) {
            $sign .= $key . '=' . $value . '&';
        }
        $sign = trim($sign, '&');
        $sign .= $this->infoDetail->APP_SECRET;

        return md5($sign);
    }
}
