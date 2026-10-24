<?php

namespace App\Singletons;

use App\DTOs\DepositInfoDTO;
use App\DTOs\HomepageInfoDTO;
use App\DTOs\PlatformWalletBalanceDTO;
use App\Enums\ErrorCodeEnum;
use App\Enums\LogTypeEnum;
use App\Enums\PlatformApiCodeEnum;
use App\Helpers\AESHelper;
use App\Helpers\BotHelper;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class PlatformApiCallService
{
    private Client $client;

    private string $language;

    public function __construct(private string $botId, private string $chatId)
    {
        $this->client = app(Client::class);
        $this->language = env('LANGUAGE_DEFAULT', 'zh-TW');
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    public function isAccountExists(): bool
    {
        $responseData = $this->sendRequest('isAccountExist', checkResponse: false);

        if ((int) $responseData['code'] !== PlatformApiCodeEnum::SUCCESS->value) {
            return false;
        }

        return true;
    }

    public function bindMobile(string $mobile): bool
    {
        $responseData = $this->sendRequest('bindMobile', ['mobile' => $mobile]);
        if ((int) $responseData['code'] !== PlatformApiCodeEnum::SUCCESS->value) {
            return false;
        }

        return true;
    }

    public function getHomepageInfo(): HomepageInfoDTO
    {
        $homepageData = $this->sendRequest('homePageWithoutLogin')['data'];

        return new HomepageInfoDTO($homepageData);
    }

    public function setRegister(array $data): array
    {
        $responseData = $this->sendRequest('register', $data, checkResponse: false);

        return $responseData;
    }

    public function getWalletBalance(): PlatformWalletBalanceDTO
    {
        $responseData = $this->sendRequest('balance');

        return new PlatformWalletBalanceDTO($responseData['data']);
    }

    public function getHistory(int $dateRange, int $pageNumber, int $perPage = 10): array
    {
        $responseData = $this->sendRequest('paymentLog', [
            'date_range' => $dateRange,
            'page_number' => $pageNumber,
            'per_page' => $perPage,
        ]);

        return $responseData['data'];
    }

    /**
     * @return DepositInfoDTO[]
     */
    public function getDepositInfos(): array
    {
        $responseData = $this->sendRequest('deposit');

        $ret = [];
        foreach ($responseData['data'] ?? [] as $data) {
            $ret[] = new DepositInfoDTO($data);
        }

        return $ret;
    }

    public function getDepositInfoDetail(string $orderTypeId): array
    {
        $responseData = $this->sendRequest('depositDetail', ['orderTypeId' => $orderTypeId]);

        return $responseData['data'];
    }

    public function depositOrder(int $orderTypeId, int $amount): array
    {
        $responseData = $this->sendRequest('depositOrder', ['order_type_id' => $orderTypeId, 'amount' => $amount]);

        return $responseData['data'];
    }

    public function getWithdrawalFlowLimit(): array
    {
        $responseData = $this->sendRequest('flowLimit');

        return $responseData['data'];
    }

    public function isPaymentPasswordSet(): bool
    {
        $responseData = $this->sendRequest('checkPaymentPassword', checkResponse: false);

        if ((int) $responseData['code'] !== PlatformApiCodeEnum::SUCCESS->value) {
            return false;
        }

        return true;
    }

    public function getGameLobbyUrl(): string
    {
        $responseData = $this->sendRequest('lobby');

        return $responseData['data']['url'];
    }

    public function getRecentlyPlayedGames(): array
    {
        $responseData = $this->sendRequest('recentGame');

        return $responseData['data']['game_list'];
    }

    public function getHotVendorList(): array
    {
        $responseData = $this->sendRequest('getHotVendorList');

        return $responseData['data']['list'];
    }

    /**
     * 取得每日任務列表
     */
    public function getMissions(): array
    {
        $responseData = $this->sendRequest('missions');

        return $responseData['data'] ?? [];
    }

    /**
     * 領取每日任務獎勵
     */
    public function claimMission(int $missionId): array
    {
        $params = [
            'mission_id' => $missionId,
        ];

        $responseData = $this->sendRequest('mission/claim', $params);

        return $responseData;
    }

    /**
     * 取得活動提醒
     *
     * @param  int  $type  1: 每日簽到提醒, 2: 每日任務提醒
     */
    public function getEventRemind(int $type): array
    {
        $responseData = $this->sendRequest('notify-setting/detail', [
            'type' => $type,
        ]);

        return $responseData;
    }

    /**
     * 設定活動提醒
     *
     * @param  int  $type  1: 每日簽到提醒, 2: 每日任務提醒
     * @param  int  $status  1: 開啟, 0: 關閉
     */
    public function setEventRemind(int $type, int $status): array
    {
        $responseData = $this->sendRequest('notify-setting/update', [
            'type' => $type,
            'status' => $status,
        ]);

        return $responseData;
    }

    /**
     * 每日簽到列表
     */
    public function getCheckInList(): array
    {
        $responseData = $this->sendRequest('checkin-list');

        return $responseData['data'] ?? [];
    }

    /**
     * 每日簽到
     */
    public function checkIn(): array
    {
        $responseData = $this->sendRequest('checkin');

        return $responseData;
    }

    /**
     * vip 詳情
     */
    public function getVipDetail(): array
    {
        $responseData = $this->sendRequest('vip');

        return $responseData['data'] ?? [];
    }

    /**
     * 邀請活動
     */
    public function getInviteEvent(): array
    {
        $responseData = $this->sendRequest('invite-event');

        return $responseData['data'] ?? [];
    }

    /**
     * 領取邀請活動獎勵
     */
    public function claimInviteEvent(int $eventId, int $prizeContentId): array
    {
        $responseData = $this->sendRequest('invite-event/claim', [
            'event_id' => $eventId,
            'prize_content_id' => $prizeContentId,
        ]);

        return $responseData;
    }

    /**
     * 取得邀請網址 QR Code
     */
    public function getInviteEventQrCode(): array
    {
        $responseData = $this->sendRequest('invite-event/code');

        return $responseData['data'] ?? [];
    }

    /**
     * 取得frontend api url
     */
    public function getFrontendUrls(): array
    {
        $responseData = $this->sendRequest('alternate_domain');

        return $responseData['data'] ?? [];
    }

    private function sendRequest(string $endPoint, array $params = [], bool $checkResponse = true): array
    {
        $endPoint = ltrim($endPoint, '/');
        $botInfos = BotHelper::getBotInfos($this->botId);

        $url = config('platform_api.url') . "/tgBot/{$endPoint}";
        $headers = [
            'accept-language' => App::getLocale() ?: $this->language,
            'x-bot-tgID' => $this->chatId,
            'x-bot-ID' => $botInfos->code,
        ];

        Log::info(
            "Request, endpoint: {$endPoint}",
            [
                'url' => $url,
                'headers' => $headers,
                'data' => $params,
                'extra' => [
                    'type' => LogTypeEnum::PLATFORM_API->value,
                ],
            ]
        );

        $API_AES_KEY = config('platform_api.aes_key');
        $API_IV = config('platform_api.iv');
        $API_MD5_KEY = config('platform_api.md5_key');

        $json_content = empty($params) ? '' : json_encode($params);
        $aes = new AESHelper($API_AES_KEY, $API_IV);
        $encryptParams = $aes->encrypt($json_content);
        $key = md5($encryptParams . $API_MD5_KEY);

        $response = $this->client->post($url, [
            'headers' => $headers,
            'form_params' => [
                'key' => $key,
                'params' => $encryptParams,
            ],
        ]);

        Log::info(
            "Response, endpoint: {$endPoint}",
            [
                'data' => json_decode($response->getBody()->getContents()),
                'extra' => ['type' => LogTypeEnum::PLATFORM_API->value],
            ]
        );

        $data = json_decode($response->getBody(), true);

        if (!$checkResponse) {
            return $data;
        }

        if ((int) $data['code'] !== PlatformApiCodeEnum::SUCCESS->value) {
            Log::info(
                'Platform api response not success',
                ['data' => $data, 'extra' => ['type' => LogTypeEnum::PLATFORM_API->value]]
            );
            $errMessage = sprintf('Api error: (%s) %s', $data['code'], $data['message'] ?? '');
            throw new \Exception(
                $errMessage,
                ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value
            );
        }

        return $data;
    }
}
