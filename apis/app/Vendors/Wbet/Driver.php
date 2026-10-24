<?php

declare(strict_types=1);

namespace App\Vendors\Wbet;

use App\Data\BaseTotalBetRecordData;
use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\WbetAccount;
use App\Vendors\Models\WbetBetRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @knowledge rules/bet-record-pull
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, SupportsBetRecordFetch
{
    /** 三方查詢/標記時間皆為 GMT+8 */
    private const string API_TIMEZONE = '+08:00';

    /** 文件規定對帳/報表 API 頻率限制：1 分鐘最多 2 次、每次間隔 30 秒 */
    private const int REPORT_API_CALL_INTERVAL_SECONDS = 30;

    /** 三方資料保留期限（天），標記後不可再取 */
    private const int RETENTION_DAYS = 150;

    /**
     * WBET 為體育博彩單一大廳，注單不分遊戲代碼；沿用舊系統固定值 'TP01'
     * （見 database/procedures/SP_transfer_bet_record_wbet.sql 的 g.game_type = b.game_code JOIN），
     * 需與 vendor_id=102 的 games.game_type 配置一致，不可自行改動
     */
    private const string GAME_CODE = 'TP01';

    /**
     * log 用商戶標籤：拉單流程（BetRecordFetchService）以 vendorId 建構 driver、不帶 member，
     * $this->vendorInfo 未初始化，不能用 getName()（會讀 $this->vendorInfo->name 噴
     * "must not be accessed before initialization"）；固定字串沿用 Oppo/E88Sport 同慣例
     */
    private const string VENDOR_LABEL = 'Wbet';

    protected string $accountModel = WbetAccount::class;

    protected string $betRecordModel = WbetBetRecord::class;

    protected array $infoDetail;

    /** 序列化節流用計數器，每次 fetchBetRecords() 重置 */
    private int $reportApiCallCount = 0;

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
        $this->infoDetail = $info;
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function getBalance(): string
    {
        try {
            return $this->fetchBalance();
        } catch (AccountNotExistsException) {
            // 遊戲商端沒這個帳號, 用同帳號補建後重查一次
            $this->registerAccount();

            return $this->fetchBalance();
        }
    }

    /**
     * 查詢餘額, 帳號不存在時原樣拋出 AccountNotExistsException (不自動補建)
     *
     * 供 vendor:wbet:register-account 探測遊戲商端帳號是否存在
     *
     * @throws AccountNotExistsException|ApiException|ConnectionException
     */
    public function fetchBalance(): string
    {
        $result = $this->sendRequest('getBalance', '/api/getbalance', $this->prepareRequestData());

        $balance = $result['value'][0]['available_balance'] ?? 0;

        return Common::numFloor($balance, 2);
    }

    /**
     * @throws ApiException|ConnectionException
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = $this->prepareRequestData([
            'lang' => $this->mapLanguageCode(),
            'type' => $this->getDeviceType(),
        ]);

        $setBetLimit = $this->vendorInfo->info['SET_BET_LIMIT'] ?? 0;

        if ($setBetLimit == 1) {
            $this->setBetLimit();
        }

        $response = $this->sendRequest('getGameUrl', '/api/launchsports', $data);

        $gameUrl = $response['url'] ?? '';

        if (empty($gameUrl)) {
            throw new ApiException($response['detail'] ?? 'Game url is empty');
        }

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->handleTransfer('/api/newwithdrawal', $params);
    }

    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->handleTransfer('/api/newdeposit', $params);
    }

    private function handleTransfer(string $endpoint, VendorTransferData $params): VendorTransferResultEnum
    {
        $data = $this->prepareRequestData([
            'lang' => $this->mapLanguageCode(),
            'type' => $this->getDeviceType(),
            'transaction_id' => $params->transactionNo ?? null,
            'amount' => $params->vendorAmount ?? null,
        ]);

        $result = $this->sendRequest('transfer', $endpoint, $data);

        return (string) $result['status'] === '1' ? VendorTransferResultEnum::SUCCESS : VendorTransferResultEnum::FAILED;
    }

    /**
     * 在遊戲商端補建目前帳號 (欄位需求同舊系統 create_wbet_account: 只需簽名四參數)
     *
     * 除 getBalance 自動補建外, 亦供 vendor:wbet:register-account 手動補建使用
     *
     * @throws ApiException|ConnectionException
     */
    public function registerAccount(): void
    {
        $this->sendRequest('registerAccount', '/api/createmember', $this->prepareRequestData());
    }

    /**
     * 封裝請求
     *
     * @throws ApiException|ConnectionException
     */
    private function sendRequest(string $action, string $endpoint, array $data): mixed
    {
        $url = $this->vendorInfo->info['AUTH_URL'] . $endpoint;

        $result = Http::logAction($this->getName() . ' ' . $action)
            ->asJson()
            ->post($url, $data)
            ->throw();

        // {
        //     'status': 1,
        //     'statusdesc': 'OK',
        //     'value': [
        //         {
        //             'available_balance': 0.0000,
        //             'frozen_balance': 0.0000,
        //             'currency_code': 'MYR'
        //         }
        //     ]
        // }

        $json = $result->json();

        if (!is_array($json)) {
            throw new ApiException('Third party error: not json response');
        }

        // TODO 缺少文檔比對邏輯
        if ($action !== 'setBetLimit' && (string) $json['status'] !== '1') {
            // 遊戲商端查無此帳號 (status 200 accountNotExists), 讓呼叫端可決定是否補建帳號
            if (($json['statusdesc'] ?? '') === 'accountNotExists') {
                throw new AccountNotExistsException($json['statusdesc'], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
            }

            throw new ApiException($json['statusdesc'], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
        }

        // 調整限紅API允許的status 1: 成功 3: 沒有變更(已調整) -110: 遊戲商設定120秒內只能打一次
        if ($action === 'setBetLimit' && !in_array((string) $json['status'], ['1', '3', '-110'], true)) {
            throw new ApiException($json['statusdesc'], HttpCode::HTTP_GATEWAY_TIMEOUT->value);
        }

        return $json;
    }

    private function generateSignature(string $ukey, string $username): string
    {
        return md5($this->infoDetail['CLIENT_ID'] . $ukey . $username . $ukey . $this->infoDetail['CLIENT_SECRET']);
    }

    private function prepareRequestData(array $additionalData = []): array
    {
        $username = $this->vendorAccount->username;
        $ukey = Str::random(8);

        return array_merge([
            'account_id' => $username,
            'operator_id' => $this->infoDetail['CLIENT_ID'],
            'ukey' => $ukey,
            'signature' => $this->generateSignature($ukey, $username),
        ], $additionalData);
    }

    /**
     * 轉換語系代號, 不要再寫進 languages
     */
    private function mapLanguageCode(): string
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return match ($languageId) {
            2, 3 => 'cn',
            5 => 'id',
            6 => 'vi',
            7 => 'th',
            default => 'en',
        };
    }

    /**
     * 取得裝置類型
     *
     * @return int 1:PC, 2:Mobile
     */
    private function getDeviceType(): int
    {
        $deviceType = $_SERVER['HTTP_X_DEVICE_TYPE'] ?? 'Mobile';

        return match ($deviceType) {
            'Desktop' => 1,
            default => 2,
        };
    }

    /**
     * 設定限紅
     */
    private function setBetLimit(): void
    {
        $betLimitData = $this->prepareBetLimitData();
        $data = $this->prepareRequestData($betLimitData);
        $this->sendRequest('setBetLimit', '/api/setbetlimit', $data);
    }

    private function prepareBetLimitData(): array
    {
        $config = $this->vendorInfo->info;
        $betLimit = $config['BET_LIMIT'];
        $defaultMin = $betLimit['default_min'] ?? 10;
        $defaultMax = $betLimit['default_max'] ?? 500;
        $defaultMaxPerMatch = $betLimit['default_max_per_match'] ?? 500;

        return [
            'soccer_min_bet' => $betLimit['soccer_min_bet'] ?? $defaultMin,
            'soccer_max_bet' => $betLimit['soccer_max_bet'] ?? $defaultMax,
            'soccer_max_per_match' => $betLimit['soccer_max_per_match'] ?? $defaultMaxPerMatch,
            'basketball_min_bet' => $betLimit['basketball_min_bet'] ?? $defaultMin,
            'basketball_max_bet' => $betLimit['basketball_max_bet'] ?? $defaultMax,
            'basketball_max_per_match' => $betLimit['basketball_max_per_match'] ?? $defaultMaxPerMatch,
            'tennis_min_bet' => $betLimit['tennis_min_bet'] ?? $defaultMin,
            'tennis_max_bet' => $betLimit['tennis_max_bet'] ?? $defaultMax,
            'tennis_max_per_match' => $betLimit['tennis_max_per_match'] ?? $defaultMaxPerMatch,
            'baseball_min_bet' => $betLimit['baseball_min_bet'] ?? $defaultMin,
            'baseball_max_bet' => $betLimit['baseball_max_bet'] ?? $defaultMax,
            'baseball_max_per_match' => $betLimit['baseball_max_per_match'] ?? $defaultMaxPerMatch,
            'parlay_min_bet' => $betLimit['parlay_min_bet'] ?? $defaultMin,
            'parlay_max_bet' => $betLimit['parlay_max_bet'] ?? $defaultMax,
            'parlay_max_per_match' => $betLimit['parlay_max_per_match'] ?? $defaultMaxPerMatch,
            'esports_min_bet' => $betLimit['esports_min_bet'] ?? $defaultMin,
            'esports_max_bet' => $betLimit['esports_max_bet'] ?? $defaultMax,
            'esports_max_per_match' => $betLimit['esports_max_per_match'] ?? $defaultMaxPerMatch,
            'others_min_bet' => $betLimit['others_min_bet'] ?? $defaultMin,
            'others_max_bet' => $betLimit['others_max_bet'] ?? $defaultMax,
            'others_max_per_match' => $betLimit['others_max_per_match'] ?? $defaultMaxPerMatch,
            'cs_min_bet' => $betLimit['cs_min_bet'] ?? $defaultMin,
            'cs_max_bet' => $betLimit['cs_max_bet'] ?? $defaultMax,
            'cs_max_per_match' => $betLimit['cs_max_per_match'] ?? $defaultMaxPerMatch,
        ];
    }

    /**
     * 4~20 碼交易編號
     */
    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        $rand = str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT);
        // 到毫秒
        $datetime = Carbon::now()->format('ymdHisv');

        return $datetime . $rand;
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_wbet_account）
     *
     * 三方建號重用既有的 registerAccount()（/api/createmember，欄位同舊系統只需簽名四參數）。
     * 注單以 vendor_username 做 JOIN，建號時必寫。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'vendor_username' => $username . $this->infoDetail['CLIENT_CLASS'],
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // registerAccount() 走 prepareRequestData()，需先注入帳號
        $this->setVendorAccount($account);

        try {
            $this->registerAccount();
        } catch (Throwable $e) {
            Log::error('[Wbet][createAccount] failed', [
                'vendor_info_id' => $params->vendorInfo->id,
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    // -------------------------------------------------------------------------
    // SupportsBetRecordFetch
    //
    // 遷自舊系統 RD-Studio `php artisan wbet_bet_record`（GetWbetBetRecord）。
    // WBET 對帳 API 採「拉取 → 標記」佇列模式，不支援依時間查詢：每次固定回傳最舊未標記的
    // 至多 300 筆，標記後才不再重複回傳（見 docs/integrations/vendors/Wbet/api.md）。
    // 故 $fromDate/$toDate 窗口參數在此無意義，忽略後直接撈目前佇列內容。
    //
    // ⚠️ 已知風險（經與需求方確認後接受，維持與舊系統一致的作法）：文件要求「務必先確認
    // 資料已成功入庫後才標記」，但 SupportsBetRecordFetch 契約下 fetchBetRecords() 是在
    // BetRecordFetchService 寫入 DB 之前呼叫，無法保證後續寫入一定成功。此處 fetch 完立即
    // 標記；若標記後寫入 DB 失敗（例如 DB 例外），該批注單將對三方永久遺失
    // （150 天保留期亦救不回）。
    //
    // ⚠️ getresult / getbonusresult 的部分欄位假設（working_date 作為 payout_time、
    // bet_on 作為缺 bet_time 時的備援欄位）沿用舊系統寫法，尚未對照 WBET 現行 sandbox
    // 回應重新驗證。
    //
    // betRecordAccountColumn() 沿用 BaseAbstractVendor 預設（對應 WbetAccount.vendor_username）與
    // GAME_CODE = 'TP01' 皆已對照舊系統 database/procedures/SP_transfer_bet_record_wbet.sql 確認：
    // 該 SP 轉 TBR 時拿 wbet_bet_records.vendor_username 對 wbet_accounts.vendor_username（非
    // username）、拿 game_code 對 games.game_type，且同樣以 status = 1 篩選轉入列，
    // 與本 Driver 的判斷一致。
    //
    // formatBetRecord() 的 vendor_username 需補 CLIENT_CLASS 後綴才能與 createAccount() 寫入
    // wbet_accounts.vendor_username 的值（username . CLIENT_CLASS）對上：三方回傳的 member_id
    // 是不含後綴的原始 username，直接拿來比對會全部落空，TBR 永遠轉不出去（合併 master 的
    // createAccount() 時發現並修正，非本來就已驗證過的部分）。
    // -------------------------------------------------------------------------

    public function betRecordCredentialKey(array $info): string
    {
        return (string) ($info['CLIENT_ID'] ?? '');
    }

    /**
     * 三方無時間窗口查詢能力（佇列式拉取-標記），窗口大小不影響實際行為；
     * 設較大值單純是讓手動補拉整段區間時少切幾片
     */
    public function betRecordApiWindowMinutes(): int
    {
        return 1440;
    }

    public function betRecordMinStartTime(): ?string
    {
        return Carbon::now('UTC')->subDays(self::RETENTION_DAYS)->toDateTimeString();
    }

    /**
     * 文件規定的對帳 API 頻率上限；fetchBetRecords() 內部最多會打 4 支查詢 + 4 支標記，
     * 全部計入同一預算但以 postReportApi() 序列化間隔，不會真的違反三方限流
     */
    public function betRecordApiRateLimit(): array
    {
        return ['max_calls' => 2, 'per_seconds' => 60];
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException|ConnectionException
     */
    public function fetchBetRecords(string $fromDate, string $toDate): array
    {
        $this->reportApiCallCount = 0;

        // 4 支查詢 + 最多 4 支標記、每支間隔 30 秒節流，全程可能長達數分鐘且中途無 console
        // 輸出；先留一筆 log 標記起點，避免看起來像卡住（詳見 postReportApi 內的節流 log）
        Log::info(self::VENDOR_LABEL . ' 開始拉單', ['from' => $fromDate, 'to' => $toDate]);

        $bets = $this->postReportApi('/api/getbetlist', [], 'getBetList');
        $parlays = $this->postReportApi('/api/getparlaybetlist', [], 'getParlayBetList');
        $results = $this->postReportApi('/api/getresult', [], 'getResult');
        $bonuses = $this->postReportApi('/api/getbonusresult', [], 'getBonusResult');

        $rows = [
            ...$this->onlyAccepted($bets),
            ...$this->onlyAccepted($parlays),
            ...$results,
            ...array_map(function (array $bonus): array {
                // 房間玩法無獨立「有效投注」欄位，比照舊系統以 bet_amount 代入
                $bonus['valid_bet_amount'] = $bonus['bet_amount'] ?? 0;

                return $bonus;
            }, $bonuses),
        ];

        $this->markFetched(
            betIds: $this->fetchableIds($bets, 'bet_id'),
            parlayIds: $this->fetchableIds($parlays, 'bet_id'),
            resultIds: array_column($results, 'result_id'),
            bonusIds: array_column($bonuses, 'result_id'),
        );

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function formatBetRecord(array $raw, string $rate): array
    {
        $rate = Common::numCompare($rate, '0', 4) > 0 ? $rate : '1';

        $betId = (string) ($raw['result_id'] ?? $raw['bet_id'] ?? '');
        // wbet_accounts.vendor_username 建號時是 username . CLIENT_CLASS（見 createAccount()），
        // 三方回傳的 member_id 是不含後綴的原始 username，這裡要補上後綴才能與帳號表 JOIN 得上
        $vendorUsername = (string) ($raw['member_id'] ?? '') . $this->infoDetail['CLIENT_CLASS'];

        $betTime = self::toDbTime($raw['bet_time'] ?? $raw['bet_on'] ?? null);

        if ($betTime === null) {
            throw new ApiException('Third party error: bet_time is missing');
        }

        $bet = Common::numDiv(Common::numAdd('0', (string) ($raw['bet_amount'] ?? 0), 4), $rate, 4);
        $validBet = '0.0000';
        $winLose = '0.0000';
        $jackpot = '0.0000';
        $payoutTime = null;
        $status = 0;

        // 已結算：getresult / getbonusresult 才有 result_time / result_on
        if (isset($raw['result_time']) || isset($raw['result_on'])) {
            $payoutTime = self::toDbTime($raw['working_date'] ?? null);
            $validBet = Common::numDiv(Common::numAdd('0', (string) ($raw['valid_bet_amount'] ?? 0), 4), $rate, 4);
            $winLose = Common::numDiv(Common::numAdd('0', (string) ($raw['winlose'] ?? 0), 4), $rate, 4);
        }

        $resultStatus = $raw['result_status'] ?? null;

        if ($payoutTime !== null && $resultStatus !== null) {
            switch ($resultStatus) {
                case 'END':
                    $resultType = $raw['result_type'] ?? null;

                    if ($resultType === null) {
                        $status = 1;

                        break;
                    }

                    // 錦標賽 / 房間玩法（getbonusresult 特有）
                    switch ($resultType) {
                        case 'RESULT':
                            $jackpot = $winLose;
                            $bet = '0.0000';
                            $validBet = '0.0000';
                            $winLose = '0.0000';
                            $status = 1;
                            break;
                        case 'CREATEROOM':
                            $status = 2;
                            break;
                        case 'JOINROOM':
                            $status = ($raw['room_type'] ?? null) === 'PUBLIC FREE' ? 4 : 3;
                            break;
                        default:
                            $status = 1;
                    }
                    break;
                case 'REOPEN':
                    // 已結算賽事重開：清空結算欄位等待下一筆 END 重新拉取
                    $winLose = '0.0000';
                    $jackpot = '0.0000';
                    $payoutTime = null;
                    $status = 0;
                    break;
                case 'REFUND':
                    // 房間關閉退款（僅 getbonusresult）
                    $bet = '0.0000';
                    $validBet = '0.0000';
                    $winLose = '0.0000';
                    $status = 9;
                    break;
            }
        }

        return [
            'bet_id' => $betId,
            'vendor_username' => $vendorUsername,
            'bet_time' => $betTime,
            'bet' => $bet,
            'valid_bet' => $validBet,
            'win_lose' => $winLose,
            'payout_time' => $payoutTime,
            'status' => $status,
            'game_code' => self::GAME_CODE,
            'rolling_chip' => '0.0000',
            'jackpot' => $jackpot,
            'session_token' => '',
            'info' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 只有已結算（status=1）且有派彩時間的注單轉入 TBR；未結算 / 房間中繼狀態
     * （建房、進房、重開、退款）留在 wbet_bet_records，狀態轉正後由 --update 模式補寫並重轉
     */
    public function formatBaseTotalBetRecord(InsertedBetRecordData $record): ?BaseTotalBetRecordData
    {
        $betRecord = $record->attributes;

        if ((int) $betRecord['status'] !== 1 || blank($betRecord['payout_time'])) {
            return null;
        }

        return new BaseTotalBetRecordData(
            accountKey: (string) $betRecord['vendor_username'],
            gameCode: (string) $betRecord['game_code'],
            referenceNo: (string) $betRecord['bet_id'],
            bet: (string) $betRecord['bet'],
            validBet: (string) $betRecord['valid_bet'],
            winLose: (string) $betRecord['win_lose'],
            betTime: (string) $betRecord['bet_time'],
            payoutTime: (string) $betRecord['payout_time'],
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

    // -------------------------------------------------------------------------

    /**
     * 未結算單式/串關投注單只保留 Accepted（Cancel/Rejected 三方已自動退款、Pending 尚待定，
     * 不落地也不標記，見 fetchableIds()）
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function onlyAccepted(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['bet_status'] ?? null) === 'Accepted',
        ));
    }

    /**
     * 可標記的 id 清單：排除 Pending（尚無最終結果，標記後就再也拉不回，見類別頂部風險註記）；
     * Accepted / Rejected / Cancel 三方皆已有最終處置（受理或自動退款），可以標記
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, mixed>
     */
    private function fetchableIds(array $rows, string $idField): array
    {
        return array_column(
            array_filter($rows, fn (array $row): bool => ($row['bet_status'] ?? null) !== 'Pending'),
            $idField,
        );
    }

    /**
     * @param  array<int, mixed>  $betIds
     * @param  array<int, mixed>  $parlayIds
     * @param  array<int, mixed>  $resultIds
     * @param  array<int, mixed>  $bonusIds
     *
     * @throws ApiException|ConnectionException
     */
    private function markFetched(array $betIds, array $parlayIds, array $resultIds, array $bonusIds): void
    {
        if ($betIds !== []) {
            $this->postReportApi('/api/markfetchbetlist', ['bet_list' => $this->toMarkList($betIds)], 'markFetchBetList');
        }

        if ($parlayIds !== []) {
            $this->postReportApi('/api/markfetchparlaybetlist', ['bet_list_parlay' => $this->toMarkList($parlayIds)], 'markFetchParlayBetList');
        }

        if ($resultIds !== []) {
            $this->postReportApi('/api/markfetchresult', ['result_list' => $this->toMarkList($resultIds)], 'markFetchResult');
        }

        if ($bonusIds !== []) {
            $this->postReportApi('/api/markfetchbonusresult', ['bonus_list' => $this->toMarkList($bonusIds)], 'markFetchBonusResult');
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array{a: mixed}>
     */
    private function toMarkList(array $ids): array
    {
        return array_map(static fn ($id): array => ['a' => $id], $ids);
    }

    /**
     * 對帳/報表 API 呼叫間的節流等待，抽成獨立方法供測試 override 成 no-op（避免測試真的等待數十秒）
     */
    protected function pace(): void
    {
        sleep(self::REPORT_API_CALL_INTERVAL_SECONDS);
    }

    /**
     * 對帳/報表類 API 呼叫封裝：公式 B 簽名（不含 account_id）+ 序列化節流
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiException|ConnectionException
     */
    private function postReportApi(string $endpoint, array $data, string $action): array
    {
        if ($this->reportApiCallCount > 0) {
            // 文件規定 1 分鐘最多 2 次、每次間隔 30 秒；序列化節流避免真的觸發三方限流。
            // 一次 fetchBetRecords() 最多 8 支呼叫、全程可能長達數分鐘且中途無 console 輸出，
            // 容易讓人誤以為指令卡死，故節流前先留一筆 log 供 tail 觀察進度
            Log::info(self::VENDOR_LABEL . ' 拉單節流等待中', [
                'next_action' => $action,
                'wait_seconds' => self::REPORT_API_CALL_INTERVAL_SECONDS,
            ]);
            $this->pace();
        }
        $this->reportApiCallCount++;

        $ukey = Str::random(8);
        $payload = array_merge([
            'operator_id' => $this->infoDetail['CLIENT_ID'],
            'ukey' => $ukey,
        ], $data);
        $payload['signature'] = $this->generateReportSignature($ukey);

        $url = $this->infoDetail['AUTH_URL'] . $endpoint;

        $response = Http::logAction(self::VENDOR_LABEL . ' ' . $action)
            ->asJson()
            ->post($url, $payload)
            ->throw();

        $json = $response->json();

        if (!is_array($json)) {
            throw new ApiException('Third party error: not json response');
        }

        $status = (string) ($json['status'] ?? '');

        // status=2 為「無資料」，非錯誤（見 docs/integrations/vendors/Wbet/api.md 錯誤碼表）
        if ($status === '2') {
            return [];
        }

        if ($status !== '1') {
            throw new ApiException((string) ($json['statusdesc'] ?? "Third party error: status={$status}"));
        }

        return $json['value'] ?? [];
    }

    /**
     * 公式 B：報表/非玩家類 API 簽名（不含 account_id）
     */
    private function generateReportSignature(string $ukey): string
    {
        return md5($this->infoDetail['CLIENT_ID'] . $ukey . $ukey . $this->infoDetail['CLIENT_SECRET']);
    }

    /**
     * 三方時間（GMT+8，多為含 offset 的 ISO8601）轉 DB 存放的 UTC 字串；
     * 字串本身含 offset 時 Carbon 會直接採用，self::API_TIMEZONE 只在缺 offset 時作為預設
     */
    private static function toDbTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return Carbon::parse((string) $time, self::API_TIMEZONE)->utc()->toDateTimeString();
    }
}
