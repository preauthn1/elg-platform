<?php

declare(strict_types=1);

namespace App\Vendors\BoleSW;

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
use App\Vendors\Models\BoleswAccount;
use App\Vendors\Models\BoleswBetRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    private const string GAME_IMAGE_URL = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/games/Bole/';

    /**
     * 三方無遊戲清單 API，清單為固定套組（[en 名, cn 名, tw 名, code, 類型, 狀態]）
     */
    private const array GAME_LIST = [
        ['Bole Sichuan Mahjong - Bloody Fight', '博乐四川麻将-血战到底', '博樂四川麻將-血戰到底', 'mjxzdd', GameTypeEnum::POKER, 1],
        ['Bole Sichuan Mahjong -River of Blood', '博乐四川麻将-血流成河', '博樂四川麻將-血流成河', 'mjxlch', GameTypeEnum::POKER, 1],
        ['Bole Niu-Niu', '博乐牛牛', '博樂牛牛', 'blnn', GameTypeEnum::POKER, 1],
        ['Bole Sangong', '博乐三公', '博樂三公', 'sangong', GameTypeEnum::POKER, 1],
        ['Bole Golden Flower', '博乐炸金花', '博樂炸金花', 'zjh', GameTypeEnum::POKER, 1],
        ['Bole International Mahjong', '博乐大众麻将', '博樂大眾麻將', 'dzmj', GameTypeEnum::POKER, 1],
        ['Black Jack/Xì dách', '博乐21点', '博樂21點', 'blackjack', GameTypeEnum::POKER, 1],
        ['Bole Guangdong Mahjong', '博乐广东麻将', '博樂廣東麻將', 'gdmj', GameTypeEnum::POKER, 1],
        ['Bole Hunan Mahjong', '博乐湖南转转麻将', '博樂湖南轉轉麻將', 'hnmj', GameTypeEnum::POKER, 0], // 遊戲商下架
        ['Bole Hangzhou Mahjong', '博乐杭州麻将', '博樂杭州麻將', 'hzmj', GameTypeEnum::POKER, 0], // 遊戲商下架
        ['Bole Hubei Mahjong', '博乐卡五星麻将', '博樂卡五星麻將', 'hbmj', GameTypeEnum::POKER, 0], // 遊戲商下架
        ['Bole Guiyang Mahjong', '博乐贵阳捉鸡麻将', '博樂貴陽捉雞麻將', 'gyzjmj', GameTypeEnum::POKER, 0], // 遊戲商下架
        ['Bole 2 Player Mahjong', '博乐二人麻将', '博樂二人麻將', 'ermj', GameTypeEnum::POKER, 1],
        ['Bole Tong Bi Niu-Niu', '博乐通比牛牛', '博樂通比牛牛', 'tbnn', GameTypeEnum::POKER, 1],
        ['Bole Texas Hold\'em Poker/Cược toàn bộ kiểu Texas', '博乐全押德州', '博樂全押德州', 'qydz', GameTypeEnum::POKER, 1],
        ['Badugi', '百得之', '百得之', 'bdz', GameTypeEnum::POKER, 0], // 沒有簡中
        ['BÀI CÀO', '百草', '百草', 'baicao', GameTypeEnum::POKER, 0], // 沒有簡中
        ['Liêng', '百草2', '百草2', 'superbaicao', GameTypeEnum::POKER, 0], // 沒有簡中
        ['Bole Pineapple Poker/Mậu Binh', '十三水', '十三水', 's13', GameTypeEnum::POKER, 1],
        ['Taiwan Mahjong', '台湾十六张', '台灣十六張', 'twmj', GameTypeEnum::POKER, 1],
        ['Taung Paw Tar Shan Koe Mee', '缅甸扑克', '緬甸撲克', 'twone', GameTypeEnum::POKER, 0], // 沒有簡中
        ['Bole Three Cards', '博乐玩三张', '博樂玩三張', 'wsz', GameTypeEnum::POKER, 1],
        ['Red and Black war', '红黑大战', '紅黑大戰', 'rbwar', GameTypeEnum::POKER, 1],
        ['Baccarat', '百家乐', '百家樂', 'baccarat', GameTypeEnum::POKER, 1],
        ['Happy Fishing', '欢乐捕鱼', '歡樂捕魚', 'fish', GameTypeEnum::FISHING, 0], // 遊戲商下架
        ['Jackpot Fishing', '捕鱼大满贯', '捕魚大滿貫', 'fishjk', GameTypeEnum::FISHING, 1],
        ['Jackpot Golden Flower', '百人炸金花', '百人炸金花', 'mjh', GameTypeEnum::POKER, 1],
    ];

    protected string $accountModel = BoleswAccount::class;

    protected string $betRecordModel = BoleswBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 bole 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/bole';
    }

    public function setInfo(array $info): void
    {
        // info 透過 $this->vendorInfo->info 直接存取，無需額外解析
    }

    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = [];
        foreach (self::GAME_LIST as [$enName, $cnName, $twName, $gameCode, $gameType, $status]) {
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $enName,
                'imageUrl' => self::GAME_IMAGE_URL . $gameCode . '.png',
                'gameType' => $gameType->value,
                'status' => $status,
            ]);
            $gameInfo->setGameNames(LanguageEnum::EN->value, $enName);
            $gameInfo->setGameNames(LanguageEnum::ZH_TW->value, $twName);
            $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $cnName);
            $data[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameCode = $params->gameCode;
        $ip = $params->ip;
        $language = $this->getLanguage($params->language->value);

        $postData = [
            'game_code' => $gameCode,
            'player_account' => $this->vendorAccount->username,
            'country' => 'zh',
            'lang' => $language,
            'ip' => $ip,
            'AccessKeyId' => $this->vendorInfo->info['accessKeyId'],
            'Timestamp' => time(),
            'Nonce' => Str::uuid(),
            'op_return_type' => '3', // 返回运营商平台首页类型：1返回大厅，2返回代理游戏，3隐藏返回按钮
            'ui_hot_list_disable' => '1', // 隐藏热门游戏列表 (0显示，1不显示，参数不存在则默认显示
            'ui_category_disable' => '1', // 隐藏游戏筛选列表 (0显示，1不显示，参数不存在则默认显示
        ];

        $postData['Sign'] = $this->sign($postData);

        $action = 'BOLESW_api_call_getGameUrl';
        $url = $this->vendorInfo->info['apiUrl'] . '/v1/player/login';
        $response = Http::logAction($action)->post($url, $postData)->throw();
        $json = $response->json();

        if (!$json) {
            throw new ApiException('Third party error: not json response');
        }

        if (($json['resp_msg']['code'] ?? '') != 200) {
            throw new ApiException($json['resp_msg']['message']);
        }
        $url = $json['resp_data']['url'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    public function getLanguage(int $languageId): string
    {
        // 只支援簡體中文
        return 'zh_CN';
    }

    /**
     * 簽名
     * lowercase(sha1(AccessKeySecret + Nonce + Timestamp))【sha1加密，并转小写】
     *
     * @param  array<string, mixed>  $data
     */
    protected function sign($data): string
    {
        return strtolower(sha1($this->vendorInfo->info['accessKeySecret'] . $data['Nonce'] . $data['Timestamp']));
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_bolesw_account）
     *
     * BoleSW 無專用註冊 API，三方以 /v1/player/login 首次登入即建號。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 本 driver 的 setInfo 為空實作，info 一律走 $this->vendorInfo->info
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
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
     */
    private function createVendorAccount(string $username): bool
    {
        $info = $this->vendorInfo->info;

        $postData = [
            'player_account' => $username,
            'country' => 'zh',
            'lang' => 'zh_CN',
            'ip' => '0.0.0.0',
            'AccessKeyId' => $info['accessKeyId'],
            'Timestamp' => time(),
            'Nonce' => (string) Str::uuid(),
        ];
        $postData['Sign'] = $this->sign($postData);

        try {
            $json = Http::logAction('BOLESW_api_call_register')
                ->post($info['apiUrl'] . '/v1/player/login', $postData)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            Log::error('[BoleSW][createAccount] API error', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($json) || (int) ($json['resp_msg']['code'] ?? 0) !== 200) {
            Log::error('[BoleSW][createAccount] API error: code is not 200', [
                'username' => $username,
                'response' => $json,
            ]);

            return false;
        }

        return true;
    }
}
