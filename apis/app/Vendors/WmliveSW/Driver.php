<?php

declare(strict_types=1);

namespace App\Vendors\WmliveSW;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\General\ContextKeyEnum;
use App\Exceptions\ApiException;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Models\WmliveswAccount;
use App\Vendors\Models\WmliveswBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class Driver extends AbstractSingleWallet implements HasCallbackPath
{
    protected string $accountModel = WmliveswAccount::class;

    protected string $betRecordModel = WmliveswBetRecord::class;

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 wmlive 單一入口路由
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/wmlive';
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $gameCode = $params->gameCode;

        $mode = $this->mapGameMode($gameCode);
        $lang = $this->mapLanguageCode();

        /** @var WmliveswAccount $account */
        $account = $this->vendorAccount;
        $config = $this->vendorInfo->info;

        $data = [
            'cmd' => 'LoginGame',
            'vendorId' => $config['VENDOR_ID'],
            'signature' => $config['SIGNATURE'],
            'user' => $account->username,
            'password' => $account->password,
            'mode' => $mode,
            'lang' => $lang,
        ];

        $response = $this->sendRequest('getGameUrl', $config['API_URL'], $data);

        $gameUrl = $response['result'] ?? '';

        if (empty($gameUrl)) {
            throw new ApiException($response['errorMessage'] ?? 'Game url is empty');
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    private function sendRequest(string $action, string $url, array $data): array
    {
        $result = Http::logAction($this->getName() . ' ' . $action)
            ->asForm()
            ->post($url, $data)
            ->throw();

        $result = $result->json();

        if (isset($result['code'])) {
            throw new Exception($result['detail'], $result['code']);
        }

        return $result;
    }

    /**
     * 轉換語系代號, 不要再寫進 languages
     */
    private function mapLanguageCode(): int
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            2 => 9, // 繁體中文
            3 => 0, // 简体中文
            4 => 7, // 馬來語
            5 => 8, // 印尼
            6 => 3, // 越南語
            8 => 2, // 泰米爾語
            default => 1, // 英文
        };
    }

    /**
     * 遊戲 ID 轉換大廳代號
     */
    private function mapGameMode($gameId): string
    {
        return match ($gameId) {
            '101' => 'onlybac', // 百家乐
            '102' => 'onlydgtg', // 龙虎
            '103' => 'onlyrou', // 轮盘
            '104' => 'onlysicbo', // 骰宝
            '105' => 'onlyniuniu', // 牛牛
            '107' => 'onlyfantan', // 番摊
            '108' => 'onlysedie', // 色碟
        };
    }
}
