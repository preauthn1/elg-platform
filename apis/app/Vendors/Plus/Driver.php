<?php

declare(strict_types=1);

namespace App\Vendors\Plus;

use App\Data\GameInfoData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\PlusAccount;
use App\Vendors\Models\PlusBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;

class Driver extends AbstractTransferWallet implements ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    protected string $accountModel = PlusAccount::class;

    protected string $betRecordModel = PlusBetRecord::class;

    private VendorInfoDetail $infoDetail;

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
        $this->infoDetail = VendorInfoDetail::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $url = "{$this->infoDetail->API_URL}/m/enterGame";
        $action = 'GetGameUrl';

        $data = [
            'merch' => $this->infoDetail->MERCH,
            'gameId' => $params->gameCode,
            'playerId' => $this->vendorAccount->username,
            'lang' => $this->mapLanguageCode($params->language),
        ];
        $hash = $this->hash($data);
        $url .= "?hash={$hash}";

        $response = Http::logAction("Plus_$action", $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['status'])) {
            throw new ApiException("[Plus][{$action}] API error: bad response");
        }

        if (strtolower((string) $response['status']) !== 'success') {
            throw new ApiException("[Plus][{$action}] API error: failed");
        }

        if (!isset($response['url'])) {
            throw new ApiException("[Plus][{$action}] API error: data wrong");
        }

        return VendorGameUrlResultData::from([
            'pc' => $response['url'],
            'mobile' => $response['url'],
        ]);
    }

    public function getBalance(): string
    {
        $url = "{$this->infoDetail->API_URL}/m/playerBalance";

        $data = [
            'merch' => $this->infoDetail->MERCH,
            'playerId' => $this->vendorAccount->username,
        ];

        $hash = $this->hash($data);
        $url .= "?hash={$hash}";

        $response = Http::logAction('Plus_GetBalance', $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response) || !isset($response['status'])) {
            throw new ApiException('[Plus][GetBalance] API error: bad response');
        }
        if (strtolower((string) $response['status']) !== 'success') {
            throw new ApiException('[Plus][GetBalance] API error: failed');
        }
        if (!isset($response['balance'])) {
            throw new ApiException('[Plus][GetBalance] API error: data wrong');
        }

        return Common::numFloor($response['balance'], 4);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/m/transferRecordById";
        $action = 'CheckTransferStatus';

        $data = [
            'merch' => $this->infoDetail->MERCH,
            'orderId' => $transactionNo,
        ];

        $hash = $this->hash($data);
        $url .= "?hash={$hash}";

        $response = Http::logAction("Plus_$action", $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['status'])) {
            Log::error("[Plus][{$action}] API error: bad response", $logData);

            return VendorTransferResultEnum::TIMEOUT; // 無法驗證結果，不還錢
        }
        if ((string) $response['status'] !== 'success') {
            Log::error("[Plus][{$action}] API error: failed", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'm/deposit', 'Deposit');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'm/withdraw', 'Withdraw', ['takeAll' => '0']); // 轉出時是否全部取出 0 : 依照amount取出  1 : 全部取出
    }

    public function transfer(VendorTransferData $params, string $endPoint, string $action, array $extraData = []): VendorTransferResultEnum
    {
        $url = "{$this->infoDetail->API_URL}/{$endPoint}";

        $data = [
            'merch' => $this->infoDetail->MERCH,
            'playerId' => $this->vendorAccount->username,
            'orderId' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];
        $data = array_merge($data, $extraData);

        $hash = $this->hash($data);
        $url .= "?hash={$hash}";

        $response = Http::logAction("Plus_$action", $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['status'])) {
            Log::error("[Plus][{$action}] API error: bad response", $logData);

            return VendorTransferResultEnum::TIMEOUT; // 無法驗證結果，不還錢
        }
        if ((string) $response['status'] !== 'success') {
            Log::error("[Plus][{$action}] API error: failed", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 遊戲清單（hash 帶在 query、body 為同一份 data；name 為多語 map）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = ['merch' => $this->infoDetail->MERCH];
        $url = "{$this->infoDetail->API_URL}/m/gameList?hash=" . $this->hash($data);

        $response = Http::logAction('Plus_GetGameList', $data)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($response) || strtolower($response['status'] ?? '') !== 'success') {
            throw new ApiException('[Plus][GetGameList] API error: ' . ($response['status'] ?? 'no status'));
        }

        $gameList = [];
        foreach ($response['data'] ?? [] as $game) {
            $gameCode = (string) $game['gameId'];
            $names = $game['name'] ?? [];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $names['en'] ?? $gameCode,
                'imageUrl' => '',
                'gameType' => self::LEGACY_UNCATEGORIZED_TYPE_ID,
                'status' => 1,
                'info' => json_encode($game),
            ]);

            if (!empty($names['en'])) {
                $gameInfo->setGameNames(LanguageEnum::EN->value, $names['en']);
            }
            if (!empty($names['zh-CN'])) {
                $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $names['zh-CN']);
            }

            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    private function hash(array $message): string
    {
        $json_str = json_encode($message, JSON_UNESCAPED_UNICODE);

        return hash_hmac('SHA256', $json_str, $this->infoDetail->API_KEY);
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh-CN',
            LanguageEnum::ZH_CN->value => 'zh-CN',
            default => 'en',
        };
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $API_URL,
        public string $MERCH,
        public string $API_KEY,
        public string $CURRENCY,
    ) {
        $this->API_URl = trim($this->API_URL, '/');
    }
}
