<?php

declare(strict_types=1);

namespace App\Vendors\Pp;

use App\Data\BaseTotalBetRecordData;
use App\Data\GameInfoData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
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
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\PpAccount;
use App\Vendors\Models\PpBetRecord;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * PP 54 (Pragmatic Play)
 *
 * 轉帳逾時單的處理政策（2026-06 決議，只完成第一步）：
 * - 已完成：transfer() 遇網路例外、回應非陣列、缺 error 欄位時回 TIMEOUT（不是 FAILED），
 *   MemberTransferService 據此把本地單記成「三方結果未知」並保留、不退款。
 * - 未完成：冪等重送與退款判定。PP 的 GetTransferStatus 只有 Success／Not found 兩值，
 *   Not found 是「未處理」不是失敗，所以退款依據不能來自狀態查詢；退款的唯一合法依據是以相同
 *   externalTransactionId 重送 Transfer 後拿到「轉帳失敗」回應（PP 冪等、重送不會重複入帳，依 PP 文件，未實測）。
 * - 重送結果對本地單的結清：回 0 或 8 → 標成功且不退本地（本地已扣即正確）；回「轉帳失敗」→
 *   確定未入帳，退本地；error 1 或網路錯誤 → 重試，頻率不超過每分鐘一次、最多一小時。
 * - 順序：先修 transfer() 對 error=8 的判定（現在只認 error=0 為成功，8 會被當失敗；重送拿到 8
 *   要視為「已入帳」，不修就會退錯款）→ 實作重送 → 最後才把 PP 納入 CheckVendorTransferStatus
 *   白名單，且納入時退款判定要改綁重送結果。順序顛倒會把處理中的單當失敗退款，造成雙重入帳。
 * PPPOP 的 Driver 繼承本類且未覆寫 transfer／checkTransferStatus，改這裡會一起改到它。
 *
 * @knowledge rules/bet-record-pull
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList, SupportsBetRecordFetch
{
    /** 三方 DataFeeds 一次只回一個 10 分鐘視窗 */
    private const int FETCH_WINDOW_MINUTES = 10;

    /** 可撈的 dataType：RNG 不帶 dataType 參數，其餘要帶並加 addRoundDetails 才有取消資訊 */
    private const array DATA_TYPES = ['RNG', 'LC'];

    /** 三方回 429 時的等待秒數與重試上限 */
    private const int RETRY_SLEEP_SECONDS = 30;

    private const int MAX_RETRY = 3;

    /** gamerounds/finished 的 status：F＝取消或最終確定（C＝已完成） */
    private const string ROUND_STATUS_CANCELLED_OR_FINALIZED = 'F';

    /** 真人娛樂場的 dataType（bet_id 以 008 結尾） */
    private const string DATA_TYPE_LIVE_CASINO = 'LC';

    protected string $accountModel = PpAccount::class;

    protected string $betRecordModel = PpBetRecord::class;

    protected bool $verboseLog = true;

    protected string $apiUrl;

    protected string $secretKey;

    protected string $secretLogin;

    protected string $lobbyUrl;

    /** 拉注單用的 DataFeeds 網域，與遊戲 API 不同台 */
    protected string $betUrl = '';

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        // Config
        $this->setInfo($this->vendorInfo->info);
    }

    /** 拉單限定的 data type（DATA_TYPE，逗號分隔），空字串 = 全部 */
    private string $dataTypeFilter = '';

    /** @noinspection SpellCheckingInspection */
    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->secretKey = $info['SECRETKEY'];
        $this->secretLogin = $info['SECURELOGIN'];
        $this->lobbyUrl = $info['RETURN_URL'] ?? '';
        $this->betUrl = $info['BET_URL'] ?? '';
        $this->dataTypeFilter = (string) ($info['DATA_TYPE'] ?? '');
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $this->vendorAccount->username,
            'gameId' => $params->gameCode,
            'language' => $this->mapLanguageCode(),
            // 'platform' => $params->deviceType === DeviceTypeEnum::MOBILE ? 'MOBILE' : 'WEB',
            'platform' => 'MOBILE',
            'country' => 'MY', // DB沒有存國家，先固定為 MY
        ];

        if ($this->lobbyUrl) {
            $data['lobbyURL'] = $this->lobbyUrl;
        }

        $data['hash'] = $this->getHash($data);

        $result = $this->sendRequest('/CasinoGameAPI/game/start/', $data);

        if (!isset($result['error']) || $result['error'] !== '0') {
            $this->throwError($result['description'] ?? 'Unknown error', [
                'result' => $result,
            ]);
        }

        $gameUrl = $result['gameURL'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    /**
     * DataFeeds 以 SECURELOGIN 識別商戶：同一組登入回同一批注單，只需拉一次
     *
     * @param  array<string, mixed>  $info
     */
    public function betRecordCredentialKey(array $info): string
    {
        return (string) ($info['SECURELOGIN'] ?? '');
    }

    /**
     * 三方一次只回 timepoint 之後 10 分鐘內的注單（docs/integrations/vendors/Pp/api.md）
     */
    public function betRecordApiWindowMinutes(): int
    {
        return self::FETCH_WINDOW_MINUTES;
    }

    /**
     * 三方文件未載明可回溯的最早時間，不設界線
     */
    public function betRecordMinStartTime(): ?string
    {
        return null;
    }

    /**
     * 三方文件未給 DataFeeds 的呼叫上限，只知超量會回 429，這裡給一個保守預算
     * 節流連續補拉；真的撞到 429 由 fetchWindow() 退避重試。
     *
     * @return array{max_calls: int, per_seconds: int}
     */
    public function betRecordApiRateLimit(): array
    {
        return ['max_calls' => 30, 'per_seconds' => 60];
    }

    /**
     * 拉取單一時間片（切片由 BetRecordFetchService 負責）：RNG 與 LC 是分開的資料源，
     * 各撈一次。
     *
     * 三方的 timepoint 只吃起點、固定回該點之後 10 分鐘內的注單，故窗口不足 10 分鐘時
     * 回傳會略超出 $toDate——寫入端以唯一鍵去重，重疊安全。
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        // 設定錯誤要炸出來（failed_jobs / --direct 逐商回報）：
        // 回空陣列會被當成「該時段無注單」，該片被標記完成而永久缺帳
        if ($this->betUrl === '') {
            throw new ApiException('Pp fetchBetRecords: BET_URL is not configured');
        }

        $from = Carbon::parse($fromDate, 'UTC');
        $records = [];

        foreach ($this->dataTypes() as $dataType) {
            foreach ($this->fetchWindow($from, $dataType) as $row) {
                $records[] = [...$row, 'data_type' => $dataType];
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  string  $rate  商戶匯率：三方金額是放大後的值，取回要除掉
     * @return array<string, mixed>
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';
        $bet = Common::numDiv(Common::numAdd('0', (string) ($raw['bet'] ?? 0), 4), $rate, 4);
        $win = Common::numDiv(Common::numAdd('0', (string) ($raw['win'] ?? 0), 4), $rate, 4);
        $cancelled = $this->isCancelled($raw);

        return [
            'bet_id' => (string) ($raw['playSessionID'] ?? ''),
            'data_type' => (string) ($raw['data_type'] ?? ''),
            'username' => (string) ($raw['extPlayerID'] ?? ''),
            'game_code' => (string) ($raw['gameID'] ?? ''),
            'bet_time' => (string) ($raw['startDate'] ?? ''),
            'end_time' => (string) ($raw['endDate'] ?? ''),
            // 取消單金額直接歸零：狀態變化會反映在金額上，
            // --update 模式只比金額就能認出取消⇄正常的互轉，不必另外懂 bet_status
            'bet' => $cancelled ? '0.0000' : $bet,
            // 三方給的是派彩，輸贏要自己減
            'win_lose' => $cancelled ? '0.0000' : Common::numSub($win, $bet, 4),
            'bet_status' => $cancelled ? 0 : 1,
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 對齊 SP_transfer_bet_record_pp：payout_time 取 end_time
     * （summary_time 由 BetRecordFetchService 取 payout_time 的整點）。
     *
     * 取消單的金額在 formatBetRecord() 就已歸零，這裡照樣轉入 TBR——列要在，
     * 對帳才看得到這筆。
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['username'],
            gameCode: (string) $betRecord['game_code'],
            referenceNo: (string) $betRecord['bet_id'],
            bet: (string) $betRecord['bet'],
            validBet: (string) $betRecord['bet'],
            winLose: (string) $betRecord['win_lose'],
            betTime: (string) $betRecord['bet_time'],
            payoutTime: (string) $betRecord['end_time'],
        );
    }

    public function getBetRecordModelClass(): string
    {
        return $this->betRecordModel;
    }

    public function getAccountModelClass(): string
    {
        return $this->accountModel;
    }

    /**
     * 取消單：roundDetails.result = Betcancelled，或真人（LC）局 status = F
     * PP 確認真人局不會自動完成，F 即取消；RNG 的 F 是自動完成，有真實輸贏照常入帳
     *
     * @param  array<string, mixed>  $raw
     */
    private function isCancelled(array $raw): bool
    {
        $detail = json_decode((string) ($raw['roundDetails'] ?? ''), true);

        if (is_array($detail) && ($detail['result'] ?? null) === 'Betcancelled') {
            return true;
        }

        return ($raw['status'] ?? null) === self::ROUND_STATUS_CANCELLED_OR_FINALIZED
            && ($raw['data_type'] ?? null) === self::DATA_TYPE_LIVE_CASINO;
    }

    /**
     * 商戶可用 info[DATA_TYPE] 限縮要撈的 dataType
     *
     * @return array<int, string>
     */
    private function dataTypes(): array
    {
        $limit = array_filter(array_map('trim', explode(',', $this->dataTypeFilter)));

        if ($limit === []) {
            return self::DATA_TYPES;
        }

        return array_values(array_intersect(self::DATA_TYPES, $limit));
    }

    /**
     * 撈單一 10 分鐘視窗；三方回 429 時等待後重試
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchWindow(Carbon $timepoint, string $dataType): array
    {
        $query = [
            'login' => $this->secretLogin,
            'password' => $this->secretKey,
            'timepoint' => $timepoint->getTimestampMs(),
        ];

        if ($dataType !== 'RNG') {
            // RNG 不吃這兩個參數；addRoundDetails 才會帶回取消狀態
            $query['dataType'] = $dataType;
            $query['options'] = 'addRoundDetails';
        }

        $url = rtrim($this->betUrl, '/') . '/DataFeeds/gamerounds/finished/';

        for ($attempt = 1; $attempt <= self::MAX_RETRY; $attempt++) {
            // 拉單的 driver 由 BetRecordFetchService 建立，沒有帶 vendorInfo，
            // 不能用 getName()（讀 vendorInfo->name）當 log action
            $response = Http::logAction($this->vendor->name . ' fetchBetRecords')->get($url, $query);

            if ($response->status() !== 429) {
                return $this->parseCsv($response->throw()->body());
            }

            Log::warning('Pp fetchBetRecords 429 too many requests', [
                'timepoint' => $timepoint->toDateTimeString(),
                'data_type' => $dataType,
                'attempt' => $attempt,
            ]);

            if ($attempt < self::MAX_RETRY) {
                Sleep::for(self::RETRY_SLEEP_SECONDS)->seconds();
            }
        }

        // 重試耗盡不能回空陣列——會被當成「該時段無注單」，該片被標記完成而永久缺帳
        throw new ApiException('Pp fetchBetRecords: 429 retries exhausted');
    }

    /**
     * 三方回的是 CSV（首列為欄名），非 JSON
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];

        // 第 0 行是三方回的新 timepoint=...，第 1 行才是欄名（api.md「DataFeeds」章節）
        if (str_starts_with((string) ($lines[0] ?? ''), 'timepoint=')) {
            array_shift($lines);
        }

        $header = str_getcsv((string) array_shift($lines));

        if (count($header) === 1 && in_array($header[0], [null, ''], true)) {
            return [];
        }

        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            // 欄數對不上時補齊／截斷，避免 array_combine 直接爆掉
            $values = array_slice(array_pad($values, count($header), null), 0, count($header));
            $rows[] = array_combine($header, $values);
        }

        return $rows;
    }

    public function getBalance(): string
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $this->vendorAccount->username,
        ];

        $data['hash'] = $this->getHash($data);

        $result = $this->sendRequest('/CasinoGameAPI/balance/current/', $data);

        if (!isset($result['error']) || $result['error'] !== '0') {
            $this->throwError($result['description'] ?? 'Unknown error', [
                'result' => $result,
            ]);
        }

        return (string) $result['balance'];
    }

    /**
     * 更新遊戲清單（GetCasinoGames，文件 2.1）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = [
            'secureLogin' => $this->secretLogin,
        ];

        $data['hash'] = $this->getHash($data);

        $result = $this->sendRequest('/CasinoGameAPI/getCasinoGames/', $data, 'getGameList');

        if (!is_array($result) || !isset($result['error']) || (string) $result['error'] !== '0') {
            Log::error($this->getName() . ' getGameList error: ' . ($result['description'] ?? 'Unknown error'));

            return $this->formatGameList([]);
        }

        return $this->formatGameList($result['gameList'] ?? []);
    }

    private function formatGameList(array $gameList): GetGameListResultData
    {
        $data = [];

        foreach ($gameList as $game) {
            $gameCode = $game['gameID'];
            $size = '200x200'; // 138x138, 160x115, 168x112, 188x83, 200x200, 216x160, 260x350
            $imageUrl = sprintf('https://example.invalid/gs2c/common/lobby/v1/apps/slots-lobby-assets/%s/%s_%s_NB.png', $gameCode, $gameCode, $size);

            $newGame = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => $imageUrl,
                'gameType' => $this->convertGameTypeId($game['gameTypeID'] ?? ''),
                'status' => 1,
            ]);
            $newGame->info = json_encode($game);

            $data[$gameCode] = $newGame;
        }

        return GetGameListResultData::from([
            'gameList' => $data,
        ]);
    }

    /**
     * PP 遊戲類型映射（同 PPSW\Driver::convertTypeId，PP 三方分類不因錢包架構而異）：
     * - 真人視訊：bj, lg, bc, rl -> LIVE_CASINO
     * - 電子遊戲：vs, cs, bn, sc -> SLOTS
     * - 體育：vp -> SPORTS
     * 其他未列出的類型回傳 0
     */
    private function convertGameTypeId(string $gameType): int
    {
        static $map = [
            'bj' => GameTypeEnum::LIVE_CASINO->value,
            'lg' => GameTypeEnum::LIVE_CASINO->value,
            'bc' => GameTypeEnum::LIVE_CASINO->value,
            'rl' => GameTypeEnum::LIVE_CASINO->value,
            'vs' => GameTypeEnum::SLOTS->value,
            'cs' => GameTypeEnum::SLOTS->value,
            'bn' => GameTypeEnum::SLOTS->value,
            'sc' => GameTypeEnum::SLOTS->value,
            'vp' => GameTypeEnum::SPORTS->value,
        ];

        return $map[strtolower(trim($gameType))] ?? 0;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $this->vendorAccount->username,
            'externalTransactionId' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];

        return $this->transfer($data, __FUNCTION__);
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $this->vendorAccount->username,
            'externalTransactionId' => $params->transactionNo,
            'amount' => '-' . $params->vendorAmount, // 負數表示提款
        ];

        return $this->transfer($data, __FUNCTION__);
    }

    public function transfer(array $data, string $function): VendorTransferResultEnum
    {
        $data['hash'] = $this->getHash($data);

        try {
            $result = $this->sendRequest('/CasinoGameAPI/balance/transfer/', $data, $function);
        } catch (Exception $e) {
            Log::error($this->getName() . ' transfer timeout', [
                'username' => $this->vendorAccount->username,
                'transactionNo' => $data['externalTransactionId'],
                'vendorAmount' => $data['amount'],
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($result)) {
            Log::error($this->getName() . ' transfer failed: response is not array');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['error'])) {
            Log::error($this->getName() . ' transfer failed: parameter "error" is not found');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if ((string) $result['error'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        Log::error($this->getName() . ' transfer failed: ' . $result['description']);

        return VendorTransferResultEnum::FAILED;
    }

    /**
     * 檢查轉帳結果
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        // $transactionLog = $this->getTransactionLog($transactionNo);

        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $this->vendorAccount->username,
            'externalTransactionId' => $transactionNo,
        ];

        $data['hash'] = $this->getHash($data);

        try {
            $result = $this->sendRequest('/CasinoGameAPI/balance/transfer/status/', $data);
        } catch (ConnectionException $e) {
            Log::error($this->getName() . ' checkTransferStatus timeout', [
                'username' => $this->vendorAccount->username,
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($result)) {
            Log::error($this->getName() . ' checkTransferStatus failed: response is not array');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['status'])) {
            Log::error($this->getName() . ' checkTransferStatus failed: parameter "status" is not found');

            return VendorTransferResultEnum::TIMEOUT;
        }

        // Success=交易成功
        if ($result['status'] === 'Success') {
            return VendorTransferResultEnum::SUCCESS;
        }

        // Not found=處理中。這裡回 FAILED 是現況，也是 PP 不能進 CheckVendorTransferStatus
        // 白名單的原因：進了會把處理中的單當失敗退款（見 class docblock 的逾時單政策）。
        if ($result['status'] === 'Not found') {
            Log::error($this->getName() . ' checkTransferStatus failed', [
                'username' => $this->vendorAccount->username,
                'response' => $result,
            ]);

            return VendorTransferResultEnum::FAILED;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => 'zh',
            default => 'en', // ISO 639-1
        };
    }

    protected function sendRequest(string $endpoint, array $data, string $previousFunction = ''): mixed
    {
        if (!$previousFunction) {
            $backtrace = debug_backtrace();
            $previousFunction = $backtrace[1]['function'];
        }

        $action = $this->getName() . ' ' . $previousFunction;

        $apiUrl = rtrim($this->apiUrl, '/');

        $url = $apiUrl . $endpoint;

        try {
            $result = Http::logAction($action, verbose: $this->verboseLog)
                ->asForm()
                ->post($url, $data)
                ->throw()
                ->json();
        } catch (Exception $e) {
            $this->throwError('API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    protected function throwError(string $message, array $context = []): void
    {
        $vendorName = $this->getName();

        $context = array_merge([
            'vendor_id' => $this->vendor->id,
            'username' => $this->vendorAccount->username,
        ], $context);

        Log::error("$vendorName $message", $context);

        throw new ApiException($message, HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * Hash 簽名規則
     */
    protected function getHash(array $data): string
    {
        // 字段按照 参数名称 进行自然排序
        ksort($data);

        // 排序后的将参数名称按照 key=value 的方式组合，再由 & 字符进行拼接得出参数部分。
        $hash = http_build_query($data);

        // 有序组合将 参数部分 与给定的 Secret Key 组合。
        $hash .= $this->secretKey;

        // 最后将组合后的字符串进行MD5 加密。
        $hash = md5($hash);

        return $hash;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_pp_account）
     *
     * PP 全程不用密碼；playerId 僅在該 externalPlayerId 首次建立時回傳。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
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

        if (!$this->createVendorAccount($account)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    protected function createVendorAccount(PpAccount $account): bool
    {
        $data = [
            'secureLogin' => $this->secretLogin,
            'externalPlayerId' => $account->username,
            'currency' => (string) ($this->vendorInfo->info['CURRENCY'] ?? $this->vendorInfo->currency),
        ];
        $data['hash'] = $this->getHash($data);

        try {
            // sendRequest 失敗會走 throwError()，其中讀 vendorAccount（建號情境未初始化），故整段包 try
            $result = $this->sendRequest('/CasinoGameAPI/player/account/create/', $data, 'createAccount');
        } catch (Throwable $e) {
            Log::error('[Pp][createAccount] request failed', [
                'username' => $account->username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || (string) ($result['error'] ?? '') !== '0') {
            Log::error('[Pp][createAccount] Third party error', [
                'username' => $account->username,
                'response' => $result,
            ]);

            return false;
        }

        if (isset($result['playerId'])) {
            $account->update(['player_id' => (string) $result['playerId']]);
        }

        return true;
    }
}
