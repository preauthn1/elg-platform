<?php

declare(strict_types=1);

namespace App\Services\Payment\Traits;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\WithdrawalOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Logging\HttpLoggerCustom;
use App\Models\CashFlowBank;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use Closure;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/payout-submit-and-status
 */
trait WithdrawalPayoutTrait
{
    /**
     * withdrawal_payout_records.info_* 的安全寫入長度。
     *
     * 這三欄在 TPower / VWorld 正式庫仍是 varchar(200)：migration
     * 2026_02_18_141542 改 text 時走 SchemaMonitor::table()，該表列數早已破百萬、
     * 超過 production 列數門檻而被靜默跳過，migrations 表卻已記為執行完成。
     * 三方逾時訊息（帶完整 endpoint URL）動輒超過 200 字元，直接寫入會拋
     * SQLSTATE[22001] 1406，連帶把外層代付交易整個 rollback——連「逾時轉 23
     * 鎖定防重送」的保護也一起消失（op_tickets#2784）。
     */
    protected const PAYOUT_INFO_MAX_LENGTH = 200;

    /**
     * 此 Trait 需要使用的方法（由 DriverTrait 提供）
     *
     * @see DriverTrait::getDriverName()
     */
    abstract protected function getDriverName(): string;

    /**
     * 代付回調的訂單狀態檢查：只放行 21、22，其餘狀態告警並拋 WithdrawalOrderException 拒絕回調。
     *
     * 各金流商回調都在交易內呼叫本方法，收到例外後就 rollBack；BotNotify::send() 在 rollBack 之後
     * 照樣送出、並在開頭標註交易已回滾，告警不會跟著交易被丟掉（見 {@see \App\Services\BotNotifyService}）。
     * 29 那則是防重複出款的告警，送不出去就沒人知道要核對。
     */
    protected function checkWithdrawalOrderStatus(?WithdrawalOrder $order): void
    {
        // 查無訂單，返回失敗
        if (!$order) {
            throw new WithdrawalOrderException('Withdrawal order not found', 400);
        }

        // 21 (PAYMENT_SUBMITTED)：結果未明／pod 中斷停留的單，回調正是定案依據，放行（target/tickets#4137）
        if ($order->status === WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value) {
            Log::info('代付回調單號:' . $order->id . ' 訂單於代付請求中(21)收到回調，進入自動定案');

            return;
        }

        // 22 (PAYMENT_PROCESSING)：代付處理中，正常通過
        if ($order->status === WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value) {
            return;
        }

        // 29 (THIRD_PARTY_FAILED)：#4137 決議移出放行——29 允許重新發起，補送回調若被放行
        // 會與重送的新請求交錯。拒絕並告警：若補送的是「成功」回調，代表訂單可能遭誤標 29，
        // 重新發起就是重複出款，須人工立即核對三方實際出款結果
        if ($order->status === WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value) {
            BotNotify::send(
                '代付回調單號:' . $order->id
                . ' 訂單已標記三方失敗(29)仍收到回調，已拒絕。若該回調為成功通知，訂單可能遭誤標，重新發起將導致重複出款，請立即人工核對三方實際出款結果'
            );

            throw new WithdrawalOrderException(
                'Withdrawal order status not allowed for callback: ' . $order->status,
                400
            );
        }

        // 其餘狀態（含 0/1/2/3/4/9/24）皆為異常，發通知並拒絕回調。
        // 已退役的 23（原「三方查證中」，target/tickets#4137）也落在這裡：線上不應再出現，出現即代表有未知寫入者
        $msg = '代付回調單號:' . $order->id . '訂單狀態不合預期' . PHP_EOL . '當前狀態為:' . $order->status . '，已拒絕回調';
        BotNotify::send($msg);

        throw new WithdrawalOrderException(
            'Withdrawal order status not allowed for callback: ' . $order->status,
            400
        );
    }

    /**
     * 驗證提款訂單是否存在且可發起代付
     * 狀態說明: 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳,21:代付提交中,22:代付處理中,24:三方成功,29:三方失敗
     *
     * @throws Exception
     */
    protected function validatePayoutOrder(?WithdrawalOrder $withdrawalOrder): WithdrawalOrder
    {
        // 檢查提款訂單是否存在
        if ($withdrawalOrder === null) {
            throw new Exception('Withdrawal order not found');
        }

        // 檢查訂單狀態是否允許發起代付 (需為代付提交中 21)
        if ((int) $withdrawalOrder->status !== 21) {
            throw new Exception('Order status not allowed for payout request');
        }

        return $withdrawalOrder;
    }

    /**
     * 獲取提款訂單並驗證
     *
     * @param  array<string>  $with  需要預載的關聯，例如 ['member']
     *
     * @throws Exception
     */
    protected function getWithdrawalOrder($withdrawalOrderId, array $with = []): WithdrawalOrder
    {
        // 檢查提款訂單是否存在
        $withdrawalOrder = WithdrawalOrder::query()
            ->with($with)
            ->where('id', $withdrawalOrderId)
            ->first();

        if (!$withdrawalOrder) {
            throw new Exception('Withdrawal order not found');
        }

        // 檢查是否已有三方訂單號避免重複提交
        // if (!empty($withdrawalOrder->third_party_order_no)) {
        //     throw new Exception('Order already submitted to provider');
        // }

        // 檢查訂單狀態是否允許發起代付 (需為代付提交中 21)
        // 狀態說明: 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳,21:代付提交中,22:代付處理中,24:三方成功,29:三方失敗
        if ((int) $withdrawalOrder->status !== 21) {
            throw new Exception('Order status not allowed for payout request');
        }

        return $withdrawalOrder;
    }

    /**
     * 建立代付請求紀錄（pending）。
     *
     * 於送出三方請求「之前」建立，使請求失敗（含 timeout）亦留痕；
     * 終態（成功 / 失敗）由 processPayout 後續 handler 更新同一筆記錄。
     *
     * ⚠️ `transaction_time` 一律留空字串，禁止在此填入我方時間。該欄語意是「三方回傳的
     * 交易時間」，只能由回調（WithdrawalCallbackTrait::updatePayoutRecord）以三方給的值寫入；
     * 請求時間看 `created_at`。曾在此塞 `now()`（UTC）當佔位，代理後台把它當三方時間顯示，
     * 對三方回 +8 的代付商（GMPay）在回調抵達前一律早 8 小時（target/op_tickets#3467）。
     *
     * @param  array<string, mixed>  $requestParams
     */
    protected function createPayoutRecord(
        WithdrawalOrder $withdrawalOrder,
        string $transactionNo,
        array $requestParams,
    ): WithdrawalPayoutRecord {
        // 獲取 cash_flow_id
        $cashFlowId = $this->agentCashFlow->cash_flow_id ?? $this->agentCashFlow->cashFlow->id ?? null;

        if (!$cashFlowId) {
            throw new Exception('Cash flow ID not found');
        }

        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => $cashFlowId,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrder->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'payout_order_request_body' => Common::safeJsonEncode($requestParams),
            'payout_order_response' => '',
            'result_amount' => $withdrawalOrder->amount,
            'result_code' => '',
            'transaction_time' => '',
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::PENDING->value,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
        ]);
    }

    /**
     * 代付編排器：請求前建 pending → 送請求 → 解讀回應 → 更新終態。
     *
     * 把「建記錄 → try 送請求 → 解讀 → 更新終態 → 組 response → log」收進一處，
     * 各 provider 以兩個 closure 注入變異點：
     *  - $performRequest fn(): array — 送請求回傳三方 raw response；可拋（連線 / 逾時）→ REQUEST_FAILED(-1)
     *  - $interpretResult fn(array $result): PayoutResult — 解讀 raw response；應為 total（null 安全取值、不主動拋）
     *
     * @param  array<string, mixed>  $requestParams
     * @param  Closure(): array<mixed>  $performRequest
     * @param  Closure(array<mixed>): PayoutResult  $interpretResult
     */
    protected function processPayout(
        WithdrawalOrder $withdrawalOrder,
        string $transactionNo,
        array $requestParams,
        Closure $performRequest,
        Closure $interpretResult,
    ): WithdrawalResponseData {
        $record = $this->createPayoutRecord($withdrawalOrder, $transactionNo, $requestParams);

        // 🔴 務必在送出前清掉：HTTP 狀態與對端 IP 取的是「最後一次出站」，不清的話，請求若拋在
        // 送出之前（參數組裝失敗、DNS 都沒查），會沿用同一個 job 裡更早那次請求的值，
        // 留下一筆看起來正常的紀錄。
        HttpLoggerCustom::forgetLastOutbound();

        // 送出請求：連線 / 逾時例外 → -1 REQUEST_FAILED
        try {
            $result = $performRequest();
        } catch (\Throwable $e) {
            // 🔴 連不上三方時 payout_order_response 只會被寫入 exception 訊息，HTTP 狀態與
            // 往返全貌留不下來；這一筆是事後判斷「三方到底收到沒有」的唯一依據。
            //
            // 30 家的錯誤碼鍵不統一，列的是實際見過的幾個（EasyPay 用 code 回
            // MERCHANT_INSUFFICIENT_BALANCE、Ap8Pay 用 error.type）；取不到就退回 exception，
            // 所以多列幾個是安全的。分得出來才看得出「三方拒收」與「連線斷掉」的差別。
            $this->logPayoutExchange(
                $record,
                $requestParams,
                $e->getMessage(),
                $this->providerFailureOf($e, 'code', 'error_code', 'type', 'error.type'),
                // RequestException 才帶得出 response；連線層斷掉時退回 macro 記下的那次
                $this->httpStatusOf($e) ?? HttpLoggerCustom::lastOutboundHttpStatus(),
            );

            return $this->handlePayoutRequestException($record, $e);
        }

        // 解讀回應：parser 例外（疑似三方回傳格式變更）保守視為失敗 → -2，避免記錄卡 PENDING；
        // 同步 BotNotify 告警通知人工檢查 parser（原始回應已存 DB、遮罩後落 log）
        try {
            $outcome = $interpretResult($result);
        } catch (\Throwable $e) {
            BotNotify::send(sprintf(
                '代付回應解讀失敗（疑似三方格式變更），已標記失敗待人工檢查 parser。三方:%s 訂單:%d 交易號:%s 原因:%s',
                $this->getDriverName(),
                $record->withdrawal_order_id,
                $record->transaction_no,
                $e->getMessage(),
            ));

            return $this->handlePayoutResponseFailure(
                $record,
                $result,
                $this->getDriverName() . ' interpret payout result failed: ' . $e->getMessage(),
            );
        }

        if ($outcome->isSuccess) {
            $this->logPayoutExchange(
                $record,
                $requestParams,
                $result,
                OrderRequestResultEnum::OK,
                HttpLoggerCustom::lastOutboundHttpStatus(),
                $outcome->thirdPartyOrderNo,
            );

            return $this->handlePayoutSuccess($record, $result, $outcome->thirdPartyOrderNo);
        }

        $this->logPayoutExchange(
            $record,
            $requestParams,
            $result,
            OrderRequestResultEnum::providerFailure((string) $outcome->errorMessage),
            HttpLoggerCustom::lastOutboundHttpStatus(),
        );

        return $this->handlePayoutResponseFailure($record, $result, $outcome->errorMessage);
    }

    /**
     * 記錄一次代付請求的三方往返（工單 target/tickets#4449）。
     *
     * `withdrawal_payout_records` 雖然已有 payout_order_request_body／payout_order_response，
     * 但它一筆 record 只留得下最後一次的內容，也沒有 HTTP 狀態、來源與統一的判定值；
     * 與代收放在同一張表才能用同一套方式查。兩邊並存是刻意的，不是重複。
     *
     * 🔴 在交易外呼叫：processPayout() 本身不開交易，各 handle*() 自己的短交易在這之後才開始。
     */
    private function logPayoutExchange(
        WithdrawalPayoutRecord $record,
        array $requestParams,
        mixed $response,
        OrderRequestResultEnum|string $result,
        ?int $httpStatus = null,
        ?string $thirdPartyOrderNo = null,
    ): void {
        $this->logExchange(
            action: OrderRequestActionEnum::WITHDRAWAL_CREATE,
            // 30 家的 info 鍵不統一，代付專用網址優先（BillionPay／YeePay 用 WITHDRAWAL_URL、
            // BigPayz 用 PAYOUT_API_URL），只有 API_URL 的才回落共用網址。
            // 這欄只是事後辨識用的粗位置，不是實際打出去的完整路徑（path 由各家 sendRequest 自己接）。
            url: (string) (
                $this->info['WITHDRAWAL_URL']
                ?? $this->info['PAYOUT_API_URL']
                ?? $this->info['API_URL']
                ?? $this->info['APIUrl']
                ?? ''
            ),
            request: $requestParams,
            response: $response,
            result: $result,
            order: $record,
            orderNo: $record->transaction_no,
            thirdPartyOrderNo: $thirdPartyOrderNo,
            // 出站的 source_ip 存的是對端（三方伺服器）IP，與入站的「誰打我方」對稱，
            // 兩者合起來就是「這次往返的對端是誰」。由 curl handler stats 的 primary_ip 取得。
            sourceIp: HttpLoggerCustom::lastOutboundRemoteIp(),
            httpStatus: $httpStatus,
        );
    }

    /**
     * 轉換為三方代付的銀行代碼。
     * allowlist 內的 provider 查 cash_flow_banks（source-of-truth），否則讀舊 available_banks JSON。
     *
     * 🔴 **代付銀行碼只能經本方法取得，禁止 provider 自行讀 info.available_banks 或自建對照表**
     * ——第二份對照表必然與這裡 drift，症狀是「某家銀行送出去被三方退」而查不出原因。
     *
     * ⚠️ cash_flow_banks 的唯一鍵不含 bank_code，同鍵多列時一律 orderBy id 取最早列以保證確定性。
     *
     * 查表鍵選 bank_code 而不是 standard_bank_code：後者是無 migration 的孤兒欄、只有零星資料；
     * 2026-08 新增的 cash_flow_banks.standard_bank_id 是投影用的便利欄，也不是本方法的查表鍵。
     * 診斷陷阱：withdrawal:check-payout-channels 永遠只讀 available_banks JSON，而本方法在
     * provider 已灰度時查 cash_flow_banks，兩者可能給出相反答案；check 說可用不等於出款會成功。
     */
    protected function convertPayoutBankCode($bankCode)
    {
        if ($this->shouldUseCashFlowBankLookup()) {
            return $this->lookupProviderBankCodeFromTable((string) $bankCode);
        }

        return $this->convertPayoutBankCodeFromJson($bankCode);
    }

    /** provider 是否已灰度切到 cash_flow_banks 查表。 */
    private function shouldUseCashFlowBankLookup(): bool
    {
        $allowlist = config('payment.payout_bank_lookup_providers', []);

        if (empty($allowlist)) {
            return false;
        }

        // 每次 allowlist 變動才重算一次；同一 allowlist 不重複 log（static 局部快取）
        static $checkedAllowlist = null;
        if ($checkedAllowlist !== $allowlist) {
            $checkedAllowlist = $allowlist;
            $unknown = array_values(array_diff($allowlist, $this->resolveKnownProviders()));
            if (!empty($unknown)) {
                Log::warning('payout_bank_lookup_providers 含未知 provider', ['unknown' => $unknown]);
            }
        }

        return in_array($this->getDriverName(), $allowlist, true);
    }

    /**
     * 從 config('payment.provider') Driver FQN 中提取目錄名，作為已知 provider 集合。
     * 例：App\Services\Payment\Providers\SurePay\Driver → SurePay
     *
     * ! 已知落差：以 info[DRIVER_LABEL] 覆寫顯示名的共用實作商戶（如 SpeedPay 共用 PayMate 目錄）
     * ! 不在本集合內，把該 label 加進 allowlist 會觸發「含未知 provider」warning。閘門本身
     * ! 以 getDriverName() 比對故仍正確生效，僅該 warning 為誤報。
     *
     * @return array<int, string>
     */
    private function resolveKnownProviders(): array
    {
        return array_map(
            static fn (string $driverClass): string => basename(dirname(str_replace('\\', '/', $driverClass))),
            config('payment.provider', []),
        );
    }

    /** 新路徑：以 (cash_flow_id, currency_id, bank_code) 查 cash_flow_banks.provider_bank_code。 */
    private function lookupProviderBankCodeFromTable(string $bankCode): string
    {
        // cash_flow_id 實務上可能為 null（agentCashFlow 尚未同步 cash_flow_id 欄位）；
        // 比照 createPayoutRecord() 的三段 fallback，以 nullsafe 關聯取值，
        // 確保行為一致、不因 null 導致 WHERE cash_flow_id=NULL 恆偽而擋單。
        $cashFlowId = $this->agentCashFlow->cash_flow_id ?? $this->agentCashFlow->cashFlow?->id;

        return (string) CashFlowBank::query()
            ->where('cash_flow_id', $cashFlowId)
            ->where('currency_id', $this->agentCashFlow->currency_id)
            // bank_code 是識別碼，必須逐字相同才算命中：欄位 collation 為 utf8mb4_unicode_ci，
            // 直接用 `=` 會把 MAYBANK 與 MayBank 判為同一個碼（banks.code 現存這種變體），
            // 而舊 JSON 路徑是大小寫敏感的 ⇒ 不加 BINARY 兩條路徑會在灰度開閘時換答案。
            // 比對慣例與 AvailableBanksAlignService、bank:remove-acf-bank-entry 等既有實作一致。
            // bank_code 無索引，驅動索引是 (cash_flow_id, currency_id)，故 BINARY 不影響查詢計畫。
            ->whereRaw('BINARY bank_code = ?', [$bankCode])
            ->where('status', 1)
            // cash_flow_banks 唯一鍵為 (cash_flow_id, currency_id, bank_id)，不含 bank_code；
            // 同鍵多列時 value() 取列由引擎決定（非確定性），orderBy id 穩定取最早建立的列。
            ->orderBy('id')
            ->value('provider_bank_code');
    }

    /**
     * 舊路徑：讀 agent_cash_flows.info.available_banks JSON（保留供未灰度 provider）。
     *
     * 比對規則與查表路徑一致：BankCode_ELG 必須是字串且與 bank_code 逐字相同。
     * 原本用 `==`，兩邊都是數字字串時 PHP 會強制轉型（'007' 等於 '7'、'1e2' 等於 '100'），
     * 而查表路徑的 SQL `=` 是字串比對、不會這樣判 ⇒ 同一張單在灰度開閘前後會拿到不同的碼。
     * 2026-09-09 收緊前先掃過五個白標共 280 筆有 available_banks 的 ACF（VPlus33 連線失敗未涵蓋），
     * 無任何數字字串或非字串的 BankCode_ELG，故此次收緊不改變現存資料的匹配結果。
     */
    private function convertPayoutBankCodeFromJson($bankCode): string
    {
        $availableBanks = $this->info['available_banks'] ?? [];
        foreach ($availableBanks as $bank) {
            if (!is_array($bank) || !isset($bank['BankCode_ELG']) || !is_string($bank['BankCode_ELG'])) {
                continue;
            }

            if ($bank['BankCode_ELG'] !== (string) $bankCode) {
                continue;
            }

            // 三方碼若不是純量（壞資料），回空字串走原本的「對不到」語意，
            // 不讓 (string) 把陣列轉成字面 "Array" 當成銀行碼送給三方。
            return is_scalar($bank['BankCode_Third'] ?? null) ? (string) $bank['BankCode_Third'] : '';
        }

        return '';
    }

    /**
     * 處理代付請求異常（連線 / 逾時，未取得三方回應）。
     *
     * 更新既有 pending 記錄為 REQUEST_FAILED(-1) + 記錄日誌 + 返回失敗響應。
     */
    protected function handlePayoutRequestException(
        WithdrawalPayoutRecord $record,
        \Throwable $exception,
    ): WithdrawalResponseData {
        $errorMessage = $this->getDriverName() . ' request exception: ' . $exception->getMessage();

        // 條件更新：record 建立於事務外，回調可能已搶先定案（SUCCESS/CALLBACK_FAILED）——
        // 只有仍 PENDING 才寫失敗終態，不得抹除回調寫入的終態與冪等標記（#4137）
        $updated = WithdrawalPayoutRecord::query()
            ->whereKey($record->id)
            ->where('is_success_payout', WithdrawalPayoutStatusEnum::PENDING->value)
            ->update([
                'is_success_payout' => WithdrawalPayoutStatusEnum::REQUEST_FAILED->value,
                'payout_order_response' => Common::safeJsonEncode(['exception' => $exception->getMessage()]),
                'info_1' => $this->truncatePayoutInfo($errorMessage),
            ]);

        if ($updated === 0) {
            Log::warning($this->getDriverName() . ' payout record already finalized by callback, keep callback terminal state', [
                'record_id' => $record->id,
                'order_id' => $record->withdrawal_order_id,
                'record_status' => $record->refresh()->is_success_payout,
            ]);
        }

        Log::error($this->getDriverName() . ' payout request exception', [
            'order_id' => $record->withdrawal_order_id,
            'transaction_no' => $record->transaction_no,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'error' => $exception->getMessage(),
            'trace' => Common::formatTrace($exception),
        ]);

        return WithdrawalResponseData::from([
            'isSuccess' => false,
            'errorMessage' => $exception->getMessage(),
            'isIndeterminate' => true,
        ]);
    }

    /**
     * 處理代付響應失敗（三方同步回應拒絕 / 回應解讀失敗）。
     *
     * 更新既有 pending 記錄為 THIRD_PARTY_REJECTED(-2) + 記錄日誌 + 返回失敗響應。
     *
     * @param  array<mixed>  $rawResponse
     */
    protected function handlePayoutResponseFailure(
        WithdrawalPayoutRecord $record,
        array $rawResponse,
        string $errorMessage = '',
    ): WithdrawalResponseData {
        $fullErrorMessage = $this->getDriverName() . ' response error: ' . $errorMessage;

        // 條件更新：回調可能已搶先定案，只有仍 PENDING 才寫失敗終態（同 handlePayoutRequestException）
        $updated = WithdrawalPayoutRecord::query()
            ->whereKey($record->id)
            ->where('is_success_payout', WithdrawalPayoutStatusEnum::PENDING->value)
            ->update([
                'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
                'payout_order_response' => Common::safeJsonEncode($rawResponse),
                'info_1' => $this->truncatePayoutInfo($fullErrorMessage),
            ]);

        if ($updated === 0) {
            Log::warning($this->getDriverName() . ' payout record already finalized by callback, keep callback terminal state', [
                'record_id' => $record->id,
                'order_id' => $record->withdrawal_order_id,
                'record_status' => $record->refresh()->is_success_payout,
            ]);
        }

        Log::error($this->getDriverName() . ' payout response failed', [
            'order_id' => $record->withdrawal_order_id,
            'transaction_no' => $record->transaction_no,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            // 憑證／PII 入 log 前統一遮罩（紅線）；完整原文仍保留於 DB payout_order_response
            'response' => Common::maskData($rawResponse),
        ]);

        return WithdrawalResponseData::from([
            'isSuccess' => false,
            'errorMessage' => $errorMessage,
            'providerResponse' => Common::safeJsonEncode($rawResponse),
        ]);
    }

    /**
     * 處理代付同步受理（三方已受理請求，等回調確認撥款）。
     *
     * 回填 third_party_transaction_no / payout_order_response，
     * is_success_payout 維持 PENDING(0)，待回調確認後才寫 SUCCESS(1)。
     *
     * @param  array<mixed>  $rawResponse
     */
    protected function handlePayoutSuccess(
        WithdrawalPayoutRecord $record,
        array $rawResponse,
        string $thirdPartyTransactionNo = '',
    ): WithdrawalResponseData {
        $record->update([
            'third_party_transaction_no' => $thirdPartyTransactionNo,
            'payout_order_response' => Common::safeJsonEncode($rawResponse),
        ]);

        return WithdrawalResponseData::from([
            'isSuccess' => true,
            'errorMessage' => '',
            'providerOrderNo' => $thirdPartyTransactionNo,
            'providerResponse' => Common::safeJsonEncode($rawResponse),
        ]);
    }

    /**
     * 創建失敗響應（簡化版本）
     */
    protected function createFailureResponse(string $errorMessage): WithdrawalResponseData
    {
        return WithdrawalResponseData::from([
            'isSuccess' => false,
            'errorMessage' => $errorMessage,
        ]);
    }

    /**
     * 截斷 info_* 欄位內容至各白標都容得下的長度。
     *
     * 以 mb_substr 依字元切（varchar(200) 計的是字元不是 byte），避免多位元組
     * 字元被攔腰截斷成亂碼。完整原文仍留在 payout_order_response(text) 與
     * log，截斷只影響這個摘要欄位、不遺失任何除錯資訊。
     */
    protected function truncatePayoutInfo(string $value): string
    {
        return mb_substr($value, 0, self::PAYOUT_INFO_MAX_LENGTH);
    }
}
