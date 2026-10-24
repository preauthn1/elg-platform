<?php

declare(strict_types=1);

namespace App\Vendors\Joker;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\GameType;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\JokerAccount;
use App\Vendors\Models\JokerBetRecord;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    /**
     * 三方 GameType 字串未涵蓋 game_types 表已知拼法或分類，一律收斂/警告
     * （target/op_tickets#2866：Slot 對不上 Slots；Funky/ECasino/Bingo 表內無對應項目，
     * 依歷史人工分類慣例——Belangkai/Sicbo/Plinko/*Bingo 等同名遊戲皆歸 Arcade）
     */
    private const array GAME_TYPE_ALIASES = [
        'Slot' => 'Slots',
    ];

    private const array GAME_TYPE_FALLBACK = [
        'Funky' => GameTypeEnum::ARCADE->value,
        'ECasino' => GameTypeEnum::ARCADE->value,
        'Bingo' => GameTypeEnum::ARCADE->value,
    ];

    protected string $accountModel = JokerAccount::class;

    protected string $betRecordModel = JokerBetRecord::class;

    private string $api_url;

    private string $app_id;

    private string $secret;

    private string $game_url;

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
        $this->api_url = $info['API_URL'];
        $this->game_url = $info['GAME_URL'];
        $this->app_id = $info['APP_ID'];
        $this->secret = [REDACTED]'SECRET'];
    }

    // TODO: 5.5.验证转移信 需要實作

    public function getBalance(): string
    {
        $data = [
            'Method' => 'GC',
            'Timestamp' => date('U'),
            'Username' => $this->vendorAccount->username,
        ];
        ksort($data);

        $result = $this->request($data);

        if ($result === false || !is_array($result)) {
            throw new ApiException(__('error_message.9995'));
        }

        return (string) ($result['Credit'] ?? '0');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = [
            'Method' => 'RT',
            'Timestamp' => date('U'),
            'Username' => $this->vendorAccount->username,
        ];
        ksort($data);
        $result = $this->request($data);

        if (!isset($result['Token'])) {
            throw new ApiException(__('error_message.9996'));
        }

        $url = $this->game_url . '?token=' . $result['Token'] . '&game=' . $params->gameCode . '&redirecrUrl=#';

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $data = [
            'Method' => 'TCH',
            'Timestamp' => date('U'),
            'RequestID' => $transactionNo,
        ];

        ksort($data);

        try {
            $result = $this->request($data);
        } catch (RequestException $e) {
            // 如果沒有找到結果，代表失敗
            return VendorTransferResultEnum::FAILED;
        }

        if ($result === false || !is_array($result)) {
            Log::error('[Joker][CheckTransferStatus] API call failed: response not array', [
                'request' => $data,
                'response' => $result,
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $params->vendorAmount = Common::numMul($params->vendorAmount, -1);

        return $this->transfer($params);
    }

    private function transfer(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'Method' => 'TC',
            'Timestamp' => date('U'),
            'Username' => $this->vendorAccount->username,
            'Amount' => $params->vendorAmount,
            'RequestID' => $params->transactionNo,
        ];

        ksort($data);
        $result = $this->request($data);

        if ($result === false || !is_array($result)) {
            Log::error('[Joker][Transfer] API call failed: response not array', [
                'request' => $data,
                'response' => $result,
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($result['Credit'])) {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error('Joker transfer failed', [
            'request' => $data,
            'response' => $result,
        ]);

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 遊戲清單（GameType 為分類名稱字串，以 game_types.name 查表對應 type_id，
     * 查無時再查 GAME_TYPE_ALIASES／GAME_TYPE_FALLBACK；
     * 遊戲代碼寫入 GameCode、平台代碼 Code 存 remark，皆比照既有資料）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $result = $this->request([
            'Method' => 'ListGames',
            'Timestamp' => date('U'),
        ]);

        if (!isset($result['ListGames'])) {
            throw new ApiException('Third party error: ListGames missing');
        }

        $typeIdByName = GameType::query()->where('status', 1)->pluck('id', 'name');

        $data = [];
        $unknownTypes = [];
        foreach ($result['ListGames'] as $game) {
            $gameCode = (string) $game['GameCode'];
            $rawType = (string) ($game['GameType'] ?? '');
            $normalizedType = self::GAME_TYPE_ALIASES[$rawType] ?? $rawType;
            $gameType = (int) (
                $typeIdByName[$normalizedType]
                ?? self::GAME_TYPE_FALLBACK[$rawType]
                ?? self::LEGACY_UNCATEGORIZED_TYPE_ID
            );

            if ($gameType === self::LEGACY_UNCATEGORIZED_TYPE_ID && $rawType !== '') {
                $unknownTypes[$rawType] = $gameCode;
            }

            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['GameName'],
                'imageUrl' => $game['Image1'] ?? '',
                'gameType' => $gameType,
                'status' => 1,
                'remark' => $game['Code'] ?? null,
                'info' => json_encode($game),
            ]);
        }

        if (!empty($unknownTypes)) {
            Log::warning('Joker 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_types' => $unknownTypes,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    private function request(array $data)
    {
        $method = $data['Method'];
        $url = $this->api_url . '?AppID=' . $this->app_id . '&Signature=' . $this->getSign($data);
        $result = Http::logAction("Joker_post_{$method}")
            ->asJson()
            ->withoutVerifying()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * -取簽名
     */
    private function getSign($data): string
    {
        ksort($data);
        $rawData = urldecode(http_build_query($data));

        $hash = base64_encode(hash_hmac('sha1', $rawData, $this->secret, true));
        $hash = urlencode($hash);

        return $hash;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_joker_account）
     *
     * Joker 無密碼機制，登入是 Method=RT 換 Token。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
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

        $created = $this->createVendorAccount($username);

        // 三方一秒內連續呼叫會回 Invalid signature（比照舊系統逐筆間隔）
        sleep(1);

        if (!$created) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（Method = CU / Create User）
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'Method' => 'CU',
            'Timestamp' => date('U'),
            'Username' => $username,
        ];
        ksort($data);

        try {
            $result = $this->request($data);
        } catch (Throwable $e) {
            Log::error('[Joker][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (is_array($result) && (string) ($result['Status'] ?? '') === 'Created' && isset($result['Data'])) {
            return true;
        }

        Log::error('[Joker][createAccount] Status is not Created', [
            'username' => $username,
            'response' => $result,
        ]);

        return false;
    }
}
