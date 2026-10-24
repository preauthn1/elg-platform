<?php

declare(strict_types=1);

namespace App\Vendors\Ace333;

use App\Data\BaseTotalBetRecordData;
use App\Data\GameInfoData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Ace333Account;
use App\Vendors\Models\Ace333BetRecord;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ace333 6
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldScheduleBetRecordFetch, ShouldSyncGameList
{
    protected string $accountModel = Ace333Account::class;

    protected string $betRecordModel = Ace333BetRecord::class;

    private string $apiUrl;

    private string $secretKey;

    private string $accessToken;

    private string $tokenUrl;

    private string $gameUrl;

    private string $currency;

    private string $encryptKey;

    private string $md5EncryptKey;

    private string $delimiter;

    private string $gamePassword;

    private string $postfix;

    private string $playerId;

    private string $username;

    private string $actionPrefix = 'Ace333';

    public function __construct(int $vendorId, ?Member $member = null)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        // Config
        $this->setInfo($this->vendorInfo->info);

        // Account
        $account = $this->vendorAccount;
        $this->playerId = $account->player_id ?? '';
        $this->username = $account->username ?? '';
    }

    /** Datafeed 拉單 API 基底網址（無 DATAFEED_API_URL 時回落 API_URL） */
    private string $datafeedApiUrl;

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->datafeedApiUrl = rtrim($info['DATAFEED_API_URL'] ?? $info['API_URL'], '/');
        $this->secretKey = $info['H5_SECRETKEY'];
        $this->accessToken = [REDACTED]'TOKEN'];
        $this->tokenUrl = $info['H5_TOKEN_URL'];
        $this->gameUrl = $info['H5_GAME_URL'];
        $this->gamePassword = [REDACTED]'H5_PASSWORD'];
        $this->encryptKey = $info['H5_ENCRYPTKEY'];
        $this->md5EncryptKey = $info['H5_MD5ENCRYPTKEY'];
        $this->delimiter = $info['DELIMITER'];
        $this->postfix = $info['POSTFIX'];
        $this->currency = $info['CURRENCY'];
    }

    public function getBalance(): string
    {
        $data = [
            'playerID' => $this->playerId,
            'currency' => $this->currency,
        ];
        $action = $this->actionPrefix . '_getBalance';

        $result = $this->sendRequest($action, '/api/getbalance', $data, 'get');

        if (isset($result['error']) && (string) $result['error'] !== '0') {
            throw new ApiException($result['description'], HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
        }

        return (string) $result['balance'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $lang = $this->mapLanguageCode();

        $data = [
            'key' => $this->secretKey,
            'time' => date('YmdHis'),
            'userName' => $this->username . $this->postfix,
            'password' => $this->gamePassword,
            'currency' => $this->currency,
            'nickName' => $this->username,
        ];

        $gameToken = [REDACTED];

        $gameUrl = $this->gameUrl . $params->remark . '?actk=' . $gameToken . '&lang=' . $lang . '&userName=' . $data['userName'];

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function resetPassword(VendorResetPasswordData $params): VendorTransferResultEnum
    {
        /** @var Ace333Account $account */
        $account = $this->vendorAccount;

        $account->update([
            'password' => Hash::make($params->newPassword),
        ]);

        return VendorTransferResultEnum::SUCCESS;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->handleTransfer(1, $params);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        // 因為分數常常卡在遊戲內,所以提款前先打登出的API
        $this->handleLogout();

        return $this->handleTransfer(2, $params);
    }

    /**
     * 轉帳處理
     */
    private function handleTransfer(int $type, VendorTransferData $params): VendorTransferResultEnum
    {
        if ($type == 1) {
            $action = $this->actionPrefix . '_Deposit';
            $endpoint = '/api/topup';
            $data = [
                'topUpAmount' => $params->vendorAmount,
                'referenceID' => $params->transactionNo,
                'currency' => $this->currency,
                'playerID' => $this->playerId,
            ];
        } else {
            $action = $this->actionPrefix . '_Withdrawal';
            $endpoint = '/api/withdraw';
            $data = [
                'withdrawAmount' => $params->vendorAmount,
                'referenceID' => $params->transactionNo,
                'currency' => $this->currency,
                'playerID' => $this->playerId,
            ];
        }

        $result = $this->sendRequest($action, $endpoint, $data);

        if (isset($result['error']) && (string) $result['error'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 登出遊戲帳號
     */
    private function handleLogout(): void
    {
        $data = [
            'playerID' => $this->playerId,
            'currency' => $this->currency,
        ];

        $this->sendRequest($this->actionPrefix . '_Logout', '/api/logout', $data);
    }

    /**
     * 查詢轉帳結果需要 timepoint
     */
    private function getTimepoint(string $datetime)
    {
        $data = [
            'datetime' => $datetime,
        ];

        $result = $this->sendRequest($this->actionPrefix . '_GetTimepoint', '/api/gettimepoint', $data, 'get');

        return $result['Timepoint'] ?? null;
    }

    /**
     * 檢查轉帳結果
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $transactionLog = $this->getTransactionLog($transactionNo);

        $timepoint = $this->getTimepoint($transactionLog->created_at->format('Y-m-d H:i:s.v'));

        $data = [
            'referenceId' => $transactionNo,
            'currency' => $this->currency,
            'timepoint' => $timepoint,
        ];

        try {
            $result = $this->sendRequest($this->actionPrefix . '_CheckOrder', '/api/checkorder', $data, 'get');
        } catch (Exception $e) {
            Log::error($this->actionPrefix . '_CheckOrder timeout', [
                'username' => $this->username,
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error($this->actionPrefix . '_CheckOrder failed: response is not array', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        // error=0 以外的都是錯誤
        if (!isset($result['error']) || (string) $result['error'] !== '0') {
            Log::error($this->actionPrefix . '_CheckOrder failed: parameter "error" is "0"', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        /**
         * transStatus
         * Unconfirmed = 170
         * Confirmed = 177
         * Cancelled = 178
         * Removed = 179
         * Pending = 180
         */
        if (!isset($result['transStatus'])) {
            Log::error($this->actionPrefix . '_CheckOrder failed: parameter "transStatus" is missing', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        /**
         * 遊戲方尚未完成交易, 維持現狀稍後再次檢查
         */
        if (in_array((int) $result['transStatus'], [170, 180])) {
            Log::info($this->actionPrefix . '_CheckOrder failed: order not yet completed', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        /**
         * 遊戲方已廢止交易
         */
        if (in_array((int) $result['transStatus'], [178, 179])) {
            return VendorTransferResultEnum::FAILED;
        }

        /**
         * 遊戲方已完成交易, 應更新交易紀錄狀態為成功
         */
        if ((int) $result['transStatus'] === 177) {
            return VendorTransferResultEnum::SUCCESS;
        }

        // 如果沒有符合的狀態，則視為未知錯誤
        return VendorTransferResultEnum::TIMEOUT;
    }

    private function mapLanguageCode(): int
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 2,
            LanguageEnum::ID_ID->value => 4,
            LanguageEnum::VI_VN->value => 5,
            LanguageEnum::TH_TH->value => 3,
            default => 1,
        };
    }

    /**
     * 取得遊戲 token
     *
     * @param  array  $data  請求參數
     * @return string 遊戲 token
     *
     * @throws ApiException
     */
    private function getGameToken(array $data): string
    {
        Log::debug($this->actionPrefix . '_getGameToken data', $data);

        // 組合查詢字串
        $queryString = '';
        foreach ($data as $key => $value) {
            $queryString .= $key . '=' . $value;
            if (end($data) != $value) {
                $queryString .= $this->delimiter;
            }
        }

        // DES-CBC 加密
        $encryptedQuery = openssl_encrypt($queryString, 'DES-CBC', $this->encryptKey, 0, $this->encryptKey);

        // urlencode
        $encodedQuery = urlencode($encryptedQuery);

        // 將 %XX 轉為小寫
        $encodedQuery = preg_replace_callback('/%[0-9A-F]{2}/', function (array $matches) {
            return strtolower($matches[0]);
        }, $encodedQuery);

        // 產生簽章
        $signature = md5($queryString . $this->md5EncryptKey . $data['time'] . $this->secretKey);

        // 組合 POST 資料
        $postData = [
            'q' => $encodedQuery,
            's' => $signature,
            'accessToken' => $this->accessToken,
        ];

        // 發送請求
        $result = Http::logAction($this->actionPrefix . '_getGameToken')
            ->asJson()
            ->post($this->tokenUrl, $postData)
            ->throw()
            ->json();

        // 回傳 token
        if (is_array($result) && (string) $result['status'] === '1') {
            return $result['actk'];
        }

        return '';
    }

    /**
     * 遊戲清單（三方無類型欄位，一律 SLOTS；gameCreditType 存 remark 比照既有資料）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $result = $this->sendRequest($this->actionPrefix . '_GetGameList', '/api/gamelist', [], 'get');

        if (!isset($result['gameList'])) {
            throw new ApiException('Third party error: ' . ($result['error'] ?? 'gameList missing'));
        }

        $data = [];
        foreach ($result['gameList'] as $game) {
            $gameCode = (string) $game['gameId'];
            $data[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => '',
                'gameType' => GameTypeEnum::SLOTS->value,
                'status' => 1,
                'remark' => isset($game['gameCreditType']) ? (string) $game['gameCreditType'] : null,
                'info' => json_encode($game),
            ]);
        }

        return GetGameListResultData::from(['gameList' => $data]);
    }

    public function betRecordApiWindowMinutes(): int
    {
        // AccountTransactions3 以 timepoint 拉取，單次至多 1 小時
        return 60;
    }

    public function betRecordMinStartTime(): ?string
    {
        // 遷移界線：台灣時間 2026-08-01 00:00（= UTC 2026-07-31 16:00）以前紀錄已由舊系統入帳，不可重拉帳
        return '2026-07-31 16:00:00';
    }

    public function betRecordCredentialKey(array $info): string
    {
        // Datafeed 服務以 TOKEN 識別商戶，同 TOKEN 回同一批注單
        return (string) ($info['TOKEN'] ?? '');
    }

    public function betRecordApiRateLimit(): array
    {
        // 三方限制每 5 分鐘不超過 10 次，保留 2 次餘裕給人工查單
        return ['max_calls' => 8, 'per_seconds' => 300];
    }

    /**
     * 完成的投注結果單轉入 total_bet_records；free spin 回合不計投注與有效投注
     *
     * AccountTransactions3 交易類型（API Spec 2.40.1）：
     * - BR（BetResult）／FB（FundBetResult）：一局的結果單，betAmount 為該局總注 → 轉入
     * - JW（JackpotWin）：彩金派彩 → 轉入
     * - BQ（BetRequest）：分注請求，同局總注已含於 BR 的 betAmount，轉入會重複計注 → 不轉
     *   （僅 Stud Poker 2／Multiplayer Games 會產生，此類遊戲已不再營運，防禦性保留）
     * - RF（Refund）／TU（Top up）／WD（Withdraw）：非投注結果 → 不轉
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        if (
            !in_array($betRecord['transactionType'] ?? '', ['BR', 'FB', 'JW'], true)
            || ($betRecord['status'] ?? '') !== 'C'
        ) {
            return null;
        }

        // 只有 roundDetails 完全等於 free spin 的回合注額才歸零：三方回的 free spin 列 betAmount 本就為 0，
        // 而「spin,buy free spin」是玩家付費購買免費旋轉，betAmount 為實際扣款，必須照計
        $bet = (string) ($betRecord['roundDetails'] ?? '') !== 'free spin'
            ? Common::numAdd('0', (string) $betRecord['betAmount'], 4)
            : '0.0000';

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['playerID'],
            gameCode: (string) $betRecord['gameID'],
            referenceNo: (string) $betRecord['referenceID'],
            bet: $bet,
            validBet: $bet,
            winLose: Common::numSub(Common::numAdd('0', (string) $betRecord['winAmount'], 4), $bet, 4),
            betTime: (string) $betRecord['created'],
            payoutTime: (string) $betRecord['created'],
        );
    }

    /**
     * 以 AccountTransactions3（Datafeed 服務）拉取注單
     *
     * timepoint 為 UTC epoch 毫秒；回應為 CSV（Line 1 = 新 timepoint、
     * Line 2 = 標題、Line 3+ = 資料），timepoint 不可早於 14 天前。
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        $timepoint = Carbon::parse($fromDate, 'UTC')->getTimestampMs();

        $response = Http::logAction($this->actionPrefix . '_FetchBetRecord')
            ->withHeaders(['token' => $this->accessToken])
            ->withoutVerifying()
            ->get($this->datafeedApiUrl . '/api/accounttransactions3', ['timepoint' => $timepoint])
            ->throw()
            ->body();

        return $this->parseBetRecordCsv($response, $fromDate);
    }

    /**
     * 轉為 ace333_bet_records 欄位；金額除以 rate 換算平台點數，JW（JackpotWin）的 gameID 統一記 jackpot
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';

        // AccountTransactions3 的 CSV 標題實測為 methodType（與 CheckAccountTransaction3 一致）；
        // transactionType 為後備鍵，供三方改回舊欄位名或單筆查單補拉時使用
        $transactionType = (string) ($raw['methodType'] ?? $raw['transactionType'] ?? '');

        if ($transactionType === '') {
            // 空值會讓 formatBaseTotalBetRecord 的型別白名單全數落空、注單靜默不轉 TBR，
            // 且純新增去重使重拉被跳過而無法補救，故此處必須顯性告警
            Log::warning($this->actionPrefix . ' formatBetRecord: 注單交易類型為空，請確認三方欄位名', [
                'vendor_id' => $this->vendor->id,
                'reference_id' => (string) ($raw['referenceID'] ?? ''),
                'raw_keys' => array_keys($raw),
            ]);
        }

        return [
            'playerID' => (string) ($raw['playerID'] ?? ''),
            'extPlayerID' => (string) ($raw['extPlayerID'] ?? ''),
            'gameID' => $transactionType === 'JW' ? 'jackpot' : (string) ($raw['gameID'] ?? ''),
            'transactionType' => $transactionType,
            'referenceID' => (string) ($raw['referenceID'] ?? ''),
            'status' => (string) ($raw['status'] ?? ''),
            'created' => Carbon::parse((string) $raw['created'], 'UTC')->format('Y-m-d H:i:s'),
            'updated' => Carbon::parse((string) $raw['updated'], 'UTC')->format('Y-m-d H:i:s'),
            'betAmount' => Common::numDiv(Common::numAdd('0', (string) ($raw['betAmount'] ?? '0'), 4), $rate, 4),
            'winAmount' => Common::numDiv(Common::numAdd('0', (string) ($raw['winAmount'] ?? '0'), 4), $rate, 4),
            'jackpotModule' => (string) ($raw['jackpotModule'] ?? ''),
            'jackpotContributionAmt' => Common::numDiv(Common::numAdd('0', (string) ($raw['jackpotContributionAmt'] ?? '0'), 4), $rate, 4),
            'currency' => (string) ($raw['currency'] ?? ''),
            'resultUrl' => (string) ($raw['resultUrl'] ?? ''),
            'roundDetails' => (string) ($raw['roundDetails'] ?? ''),
            'platform' => (string) ($raw['platform'] ?? ''),
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'admin' => 0,
        ];
    }

    public function getBetRecordModelClass(): string
    {
        return Ace333BetRecord::class;
    }

    public function getAccountModelClass(): string
    {
        return Ace333Account::class;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseBetRecordCsv(string $csv, string $fromDate): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));

        // Line 1 = 新 timepoint、Line 2 = 標題，資料至少要有 3 行
        if (!is_array($lines) || count($lines) < 3) {
            return [];
        }

        $header = str_getcsv($lines[1]);
        $headerCount = count($header);
        $rows = [];

        foreach (array_slice($lines, 2) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) !== $headerCount) {
                Log::warning($this->actionPrefix . ' parseBetRecordCsv: 欄位數與標題不符，略過', [
                    'vendor_id' => $this->vendor->id,
                    'from' => $fromDate,
                    'line' => $line,
                ]);

                continue;
            }

            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }

    private function sendRequest(string $action, string $endpoint, array $data, string $httpMethod = 'post')
    {
        $apiUrl = rtrim($this->apiUrl, '/');

        $url = $apiUrl . $endpoint;

        $headers = [
            'token' => $this->accessToken,
        ];

        $result = Http::logAction($action)
            ->withHeaders($headers)
            ->withoutVerifying()
            ->{$httpMethod}($url, $data)
            ->throw()
            ->json();

        if (!$result) {
            throw new ApiException('Third party error: not json response');
        }

        return $result;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ace333_account）
     *
     * 先以停用值佔位，三方建號成功才釋出到帳號池（member_id = 0 才可被綁定）；
     * playerID 是 getBalance／轉帳／注單 JOIN 的鍵，成功後必須寫回。
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

        $playerId = $this->createVendorAccount($username);

        if ($playerId === null) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['player_id' => $playerId, 'member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號，成功回傳 playerID，失敗回傳 null
     */
    private function createVendorAccount(string $username): ?string
    {
        $data = [
            'accountID' => $username,
            'currency' => $this->currency,
            'nickname' => $username,
        ];
        ksort($data);

        try {
            $result = $this->sendRequest($this->actionPrefix . '_createPlayer', '/api/createplayer', $data);
        } catch (Throwable $e) {
            Log::error($this->actionPrefix . '_createPlayer failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return null;
        }

        if (!is_array($result) || (int) ($result['playerID'] ?? 0) <= 0) {
            Log::error($this->actionPrefix . '_createPlayer failed: playerID not found', [
                'username' => $username,
                'response' => $result,
            ]);

            return null;
        }

        return (string) $result['playerID'];
    }
}
