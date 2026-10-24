<?php

declare(strict_types=1);

namespace App\Vendors\Pg;

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
use App\Vendors\Models\PgAccount;
use App\Vendors\Models\PgBetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use SoapClient;
use Spatie\LaravelData\Data;

class Driver extends AbstractTransferWallet
{
    protected string $accountModel = PgAccount::class;

    protected string $betRecordModel = PgBetRecord::class;

    private VendorInfoDetailData $infoDetail;

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
        $this->infoDetail = VendorInfoDetailData::from($info);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $postData = [
            'ExternalUserId' => $this->vendorAccount->username,
        ];

        $result = $this->apiCall('GetTicket', $postData);

        if (!is_object($result) || !isset($result->Ticket)) {
            throw new ApiException('[PG][GetGameUrl] response error');
        }

        $gameUrlParams = [
            'pid' => $this->infoDetail->API_PID,
            'game_id' => $params->gameCode,
            'channel' => 'mobile',
            'lang' => $this->mapLanguageCode($params->language),
            'ticket' => $result->Ticket,
            'practice' => 0,
        ];
        $gameUrl = $this->infoDetail->GAME_URL . '?' . http_build_query($gameUrlParams);

        return new VendorGameUrlResultData(
            pc: $gameUrl,
            mobile: $gameUrl,
        );
    }

    public function getBalance(): string
    {
        $postData = [
            'ExternalUserId' => $this->vendorAccount->username,
        ];

        $result = $this->apiCall('Balance', $postData);

        if (!is_object($result) || !isset($result->UserBalance) || !isset($result->UserBalance->Real)) {
            throw new ApiException('[PG][GetBalance] response error');
        }

        return Common::numFloor($result->UserBalance->Real, 4);
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'CreditAccount');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'DebitAccount');
    }

    private function transfer(VendorTransferData $params, string $endPoint): VendorTransferResultEnum
    {
        $postData = [
            'ExternalUserId' => $this->vendorAccount->username,
            'Amount' => $params->vendorAmount,
            'Currency' => $this->infoDetail->Currency,
            'ExternalTransactionId' => $params->transactionNo,
        ];

        $result = $this->apiCall($endPoint, $postData);

        $errorLogData = [
            'request' => $postData,
            'response' => $result,
        ];

        // 沒有檢查用的 API，也沒有提供狀態，這裡通通給 Timeout
        if (!is_object($result)) {
            Log::error("[PG][{$endPoint}] response error, no response data", $errorLogData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        // 沒有檢查用的 API，也沒有提供狀態，這裡通通給 Timeout
        if (isset($result->UserAccount)) {
            Log::error("[PG][{$endPoint}] error, expected data not given", $errorLogData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    private function apiCall(string $endPoint, array $postData = []): array
    {
        $wsdl = $this->infoDetail->API_URL;
        $info_data = $postData;
        $info_data['wsdl'] = $wsdl;
        $info_data['login'] = $this->infoDetail->API_LOGIN;
        $info_data['password'] = $this->infoDetail->API_PASSWORD;
        Log::info('PG_' . $endPoint, $info_data);

        $option = [
            'ssl' => ['ciphers' => 'RC4-SHA', 'verify_peer' => false, 'verify_peer_name' => false],
            'location' => $wsdl,
            'login' => $this->infoDetail->API_LOGIN,
            'password' => $this->infoDetail->API_PASSWORD,
            'trace' => 1,
        ];
        $client = new SoapClient($wsdl, $option);
        $result = $client->$endPoint($postData);

        Log::info('PG_' . $endPoint, ['request' => $info_data, 'response' => $result]);

        return $result;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh_TW',
            LanguageEnum::ZH_CN->value => 'zh_CN',
            LanguageEnum::MS_MY->value => 'ms_MY',
            LanguageEnum::ID_ID->value => 'id_ID',
            default => 'en_GB',
        };
    }
}

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_LOGIN,
        public string $API_PASSWORD,
        public string $API_PID,
        public string $Currency,
        public ?string $Brand = null,
        public ?string $Country = null,
        public ?string $GAME_URL = 'https://example.invalid/casino/ContainerLauncher' // Default value by Neil
    ) {
        $this->API_URL = trim($this->API_URL, '/');
    }
}
