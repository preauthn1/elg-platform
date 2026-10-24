<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Enums\CashFlowTypeEnum;
use App\Enums\Payment\PaymentChannelDirection;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\Contracts\SupportsDepositQuery;
use App\Services\Payment\Contracts\SupportsPaymentChannelQuery;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 查詢三方商戶設定，並與我方 ACF 配置比對
 *
 * 用途：新接商戶（或自聚合殼獨立的商戶）的限額與代付銀行清單，常先借用同協定既有商戶的值
 * 當暫用值，我方無從得知三方端真實開通了什麼。本命令以三方官方查詢 API 取回實際設定，
 * 並自動比對 `agent_cash_flows.info` 的 `min_amount`／`max_amount`／`available_banks`，
 * 把不一致的項目列出來——19 家銀行手工比對容易看漏。
 *
 * ! 唯讀：不寫任何資料表、不建三方訂單、不動金額；只帶該 ACF 的憑證做一次簽名查詢。
 *
 * ! 支援範圍由 provider 的能力介面決定，不綁定具體 Driver 類別：通道查詢看
 * ! {@see SupportsPaymentChannelQuery}（PayMate／SpeedPay 的 `/setting`、SurePay 的
 * ! `/v1/bankquery`、FPay 的 `/wallet/withdraw_bank_list`），`--order` 查單看
 * ! {@see SupportsDepositQuery}。未實作者明確報不支援。
 * ! FPay 那支只回代付銀行清單、沒有限額與開通狀態，限額比對顯示「三方未回傳」屬正常。
 *
 * ! 白標資料要用對應連線才讀得到（見 whitelabel-container-exec）：
 * ! DB_CONNECTION=<白標> php artisan cash-flow:third-party-query <acf id>
 *
 * @knowledge rules/payout-bank-code-mapping
 */
class ThirdPartyQuery extends Command
{
    protected $signature = 'cash-flow:third-party-query
                            {acf : 要查詢的 agent_cash_flows.id}
                            {--order= : 以我方訂單號向三方查該筆代收訂單（需 provider 支援）}
                            {--raw : 直接輸出三方回應原始 JSON（已遮罩），不做比對}';

    protected $description = '查三方商戶通道配置（限額／支援銀行）並與我方 ACF 比對；--order 查單筆代收訂單。依 provider 能力介面偵測支援範圍';

    /** 金額比較精度：三方回傳為兩位小數字串，取 4 位足夠且不引入 float 誤差 */
    private const int AMOUNT_SCALE = 4;

    /**
     * 代付在限額比對表的顯示標籤。三方通道名各家不同（PayMate `PAYOUT`、SurePay iban 數字碼），
     * 故只作我方列的名稱，不參與三方通道匹配——匹配一律走 direction。
     */
    private const string PAYOUT_CHANNEL = 'PAYOUT';

    public function handle(): int
    {
        $agentCashFlow = AgentCashFlow::query()->find((int) $this->argument('acf'));

        if ($agentCashFlow === null) {
            $this->error(sprintf('ACF #%s 不存在', (string) $this->argument('acf')));

            return self::FAILURE;
        }

        // --order 模式自帶 SupportsDepositQuery 偵測，不經 resolveDriver 的 ChannelQuery 閘——
        // AllPay 實作了 DepositQuery 但沒有 ChannelQuery，順序放反會讓它的查單被錯誤擋下
        if (($orderNo = (string) $this->option('order')) !== '') {
            return $this->handleOrderQuery($agentCashFlow, $orderNo);
        }

        $driver = $this->resolveDriver($agentCashFlow);

        if ($driver === null) {
            return self::FAILURE;
        }

        $this->line(sprintf(
            '查詢 ACF #%d「%s」（cash_flow %d）→ %s',
            $agentCashFlow->id,
            (string) $agentCashFlow->name,
            (int) $agentCashFlow->cash_flow_id,
            (string) ($agentCashFlow->info['API_URL'] ?? '(API_URL 未設定)')
        ));

        try {
            $snapshot = $driver->queryPaymentChannels();
        } catch (Throwable $e) {
            $this->error(sprintf('查詢失敗：%s', $e->getMessage()));

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            // DTO 的 raw 已於 provider 層過憑證／PII 遮罩（SurePay bankquery 回應含
            // bankaccno／accname），此處直接輸出即可，不再重複遮罩
            $this->line((string) json_encode($snapshot->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderMerchantStatus($snapshot);
        $this->renderChannels($snapshot->channels);
        $this->compareAmountLimits($agentCashFlow, $snapshot->channels);
        $this->compareBankCodes($agentCashFlow, $snapshot->channels);

        return self::SUCCESS;
    }

    /**
     * `--order` 模式：以我方訂單號向三方查單筆代收訂單。
     *
     * ! 唯讀：只讀 Order、不寫回任何欄位（查到 SUCCESS 也不代表可直接結算，補單走既有流程）。
     */
    private function handleOrderQuery(AgentCashFlow $agentCashFlow, string $orderNo): int
    {
        try {
            $driver = app(PaymentService::class)->getProvider($agentCashFlow);
        } catch (Throwable $e) {
            $this->error(sprintf('無法取得 Driver：%s', $e->getMessage()));

            return self::FAILURE;
        }

        if (!$driver instanceof SupportsDepositQuery) {
            $this->error(sprintf(
                '該 provider（%s）不支援代收查單（未實作 SupportsDepositQuery）',
                is_object($driver) ? $driver::class : gettype($driver)
            ));

            return self::FAILURE;
        }

        $order = Order::query()->where('order_no', $orderNo)->first();

        if ($order === null) {
            $this->error(sprintf('訂單 %s 不存在', $orderNo));

            return self::FAILURE;
        }

        // 範疇防護：訂單必須屬於指定 ACF，防止拿 A 通道的憑證查 B 通道的單
        if ((int) $order->agent_cash_flow_id !== (int) $agentCashFlow->id) {
            $this->error(sprintf('訂單 %s 不屬於 ACF #%d', $orderNo, $agentCashFlow->id));

            return self::FAILURE;
        }

        try {
            $result = $driver->queryOrder($order);
        } catch (Throwable $e) {
            $this->error(sprintf('查詢失敗：%s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->table(['訂單號', '三方狀態', '訊息'], [[
            $result->orderNo,
            $result->orderStatus->name,
            (string) $result->errorMessage,
        ]]);

        return self::SUCCESS;
    }

    /**
     * 取得 Driver 並確認具備通道查詢能力
     */
    private function resolveDriver(AgentCashFlow $agentCashFlow): ?object
    {
        try {
            $driver = app(PaymentService::class)->getProvider($agentCashFlow);
        } catch (Throwable $e) {
            $this->error(sprintf('無法取得 Driver：%s', $e->getMessage()));

            return null;
        }

        if (!$driver instanceof SupportsPaymentChannelQuery) {
            $this->error(sprintf(
                'ACF #%d 的 provider（%s）不支援通道查詢（未實作 SupportsPaymentChannelQuery）',
                $agentCashFlow->id,
                is_object($driver) ? $driver::class : gettype($driver)
            ));

            return null;
        }

        return $driver;
    }

    /**
     * 商戶層開通狀態與餘額；三方未回傳的欄位在 DTO 為 null，統一以佔位符呈現
     */
    private function renderMerchantStatus(PaymentChannelSnapshot $snapshot): void
    {
        $rows = [
            ['商戶總開關', 'merchantStatus', $this->stringOrPlaceholder($snapshot->merchantStatus)],
            ['代收', 'depositStatus', $this->stringOrPlaceholder($snapshot->depositStatus)],
            ['代付', 'payoutStatus', $this->stringOrPlaceholder($snapshot->payoutStatus)],
        ];

        $this->newLine();
        $this->info('▍商戶開通狀態');
        $this->table(['項目', '欄位', '值'], $rows);

        // 餘額需三方後台開啟設定才回傳（PayMate api.md §3.4）；SurePay bankquery 沒有這組欄位
        $balanceRows = [];
        foreach ([
            '可代付餘額' => $snapshot->availableBalance,
            '錢包總餘額' => $snapshot->totalBalance,
        ] as $label => $value) {
            if ($value !== null) {
                $balanceRows[] = [$label, $value];
            }
        }

        if ($balanceRows === []) {
            $this->line('  餘額欄位未回傳（需三方後台開啟該設定，或該 provider 無此欄位，非異常）');

            return;
        }

        $this->table(['餘額', '值'], $balanceRows);
    }

    /**
     * 逐通道設定
     *
     * @param  list<PaymentChannel>  $channels
     */
    private function renderChannels(array $channels): void
    {
        $this->newLine();
        $this->info('▍三方通道設定');

        if ($channels === []) {
            $this->warn('  三方未回傳任何通道——代表該商戶當前沒有可用通道');

            return;
        }

        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = [
                $channel->name,
                $this->directionLabel($channel->direction),
                $this->stringOrPlaceholder($channel->status),
                $this->stringOrPlaceholder($channel->minAmount),
                $this->stringOrPlaceholder($channel->maxAmount),
                $this->stringOrPlaceholder($channel->todayLimit),
                $this->stringOrPlaceholder($channel->totalAmount),
                $channel->supportBankCodes === [] ? '0（未提供）' : (string) count($channel->supportBankCodes),
                $this->stringOrPlaceholder($channel->accountBankName),
            ];
        }

        $this->table(
            ['通道', '方向', '狀態', '單筆下限', '單筆上限', '單日上限', '今日已累積', '支援銀行數', '帳戶銀行'],
            $rows
        );
        $this->line('  「支援銀行數」＝該通道可收付的銀行清單筆數（三方未提供則為 0）；'
            . '「帳戶銀行」＝該通道自身收付款帳戶的開戶行，兩者語意不同不可互推');
    }

    /**
     * 方向顯示；三方未標示方向（如 SurePay 的未知 banktype）以佔位符呈現
     */
    private function directionLabel(?PaymentChannelDirection $direction): string
    {
        return match ($direction) {
            PaymentChannelDirection::Deposit => '代收',
            PaymentChannelDirection::Payout => '代付',
            default => '（未標示）',
        };
    }

    /**
     * 比對我方各通道限額與三方通道
     *
     * 通道對應依據：
     * - 代收：`order_types.key` 精確匹配三方通道名（PayMate 的 paymentCode 與 SurePay 的
     *   iban 通道碼都是 `order_types.key`，天然同鍵）。
     * - 代付：沒有 ACFOT，限額存於 `agent_cash_flows.info`，比對對象是**方向為代付的全部通道**
     *   （不看通道名——PayMate 叫 `PAYOUT`、SurePay 是 iban 數字碼，寫死名稱會把 SurePay
     *   代付誤報成「三方無此線路」）；同方向多通道時取 min 最小／max 最大的聯集邊界。
     *
     * ! 方向要分清楚：我方比三方寬（⚠️）＝會員送得出去、到三方才被拒；
     * ! 我方比三方嚴（△）＝保守設定，不會被拒但少接了三方允許的量。
     *
     * @param  list<PaymentChannel>  $channels
     */
    private function compareAmountLimits(AgentCashFlow $agentCashFlow, array $channels): void
    {
        $this->newLine();
        $this->info('▍限額比對（我方通道 → 三方通道）');

        $ourChannels = $this->collectOurChannels($agentCashFlow);

        if ($ourChannels === []) {
            $this->line('  我方查無可比對的通道限額（代收看 agent_cash_flow_order_types、代付看 example.invalid）');

            return;
        }

        $isPayout = $agentCashFlow->cashFlow?->type === CashFlowTypeEnum::WITHDRAW->value;

        $rows = [];
        foreach ($ourChannels as $channel) {
            $bounds = $isPayout
                ? $this->payoutBounds($channels)
                : $this->depositBounds($channels, $channel['paymentCode']);

            if ($bounds === null) {
                // contractname 路徑（key 非純數字）本來就不對應單一三方通道碼——比對不上
                // 不是缺口。報 🔴 會讓這類 ACF 永遠滿屏紅字，稀釋掉真正的異常訊號。
                $missingLabel = !$isPayout && !$this->isChannelCodeStyleKey($channel['paymentCode'])
                    ? '不適用（contractname 路徑）'
                    : '🔴 三方無此線路';

                $rows[] = [
                    $channel['paymentCode'],
                    $channel['source'],
                    sprintf('%s（我方 %s）', $missingLabel, $this->limitLabel($channel['min'])),
                    sprintf('%s（我方 %s）', $missingLabel, $this->limitLabel($channel['max'])),
                ];

                continue;
            }

            $rows[] = [
                $channel['paymentCode'],
                $channel['source'],
                $this->compareOne($channel['min'], $bounds['min'], false),
                $this->compareOne($channel['max'], $bounds['max'], true),
            ];
        }

        $this->table(['通道（paymentCode）', '我方來源', '單筆下限（我方 vs 三方）', '單筆上限（我方 vs 三方）'], $rows);
        $this->line('  ⚠️ ＝我方比三方寬（超限訂單送出後才被三方拒）／△ ＝我方比三方嚴（保守，未用滿三方額度）');

        $unusedChannels = array_values(array_diff(
            array_map(static fn (PaymentChannel $channel): string => $channel->name, $channels),
            $isPayout
                ? array_map(
                    static fn (PaymentChannel $channel): string => $channel->name,
                    $this->channelsByDirection($channels, PaymentChannelDirection::Payout)
                )
                : array_column($ourChannels, 'paymentCode')
        ));

        if ($unusedChannels === []) {
            return;
        }

        sort($unusedChannels);
        $this->line(sprintf('  💡 三方有、我方未配置的線路 %d 條：%s', count($unusedChannels), implode(', ', $unusedChannels)));
    }

    /**
     * 我方通道 key 是否為「三方通道碼」形式（純數字）。
     *
     * 代收有兩條路徑（見 SurePay api.md §3 codebase 行為補充）：key 為純數字時送 `bankcode`
     * 直跳指定通道、可與三方通道逐一對應；key 為 `OB`／`QR` 這類非數字時送 `contractname`，
     * 由三方顯示收銀台選擇頁——這種 key 天生對不到單一通道碼，比對不上不代表配置有問題。
     */
    private function isChannelCodeStyleKey(string $paymentCode): bool
    {
        return $paymentCode !== '' && ctype_digit($paymentCode);
    }

    /**
     * 代付比對邊界：方向為代付的全部通道取 min 最小／max 最大（聯集邊界）
     *
     * @param  list<PaymentChannel>  $channels
     * @return array{min: ?string, max: ?string}|null 無任何代付通道時回 null
     */
    private function payoutBounds(array $channels): ?array
    {
        $payoutChannels = $this->channelsByDirection($channels, PaymentChannelDirection::Payout);

        if ($payoutChannels === []) {
            return null;
        }

        $min = null;
        $max = null;
        foreach ($payoutChannels as $channel) {
            if ($channel->minAmount !== null
                && ($min === null || Common::numCompare($channel->minAmount, $min, self::AMOUNT_SCALE) < 0)) {
                $min = $channel->minAmount;
            }

            if ($channel->maxAmount !== null
                && ($max === null || Common::numCompare($channel->maxAmount, $max, self::AMOUNT_SCALE) > 0)) {
                $max = $channel->maxAmount;
            }
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * 代收比對邊界：以 `order_types.key` 精確匹配三方通道名
     *
     * @param  list<PaymentChannel>  $channels
     * @return array{min: ?string, max: ?string}|null 三方無同名通道時回 null
     */
    private function depositBounds(array $channels, string $paymentCode): ?array
    {
        foreach ($channels as $channel) {
            if ($channel->name === $paymentCode) {
                return ['min' => $channel->minAmount, 'max' => $channel->maxAmount];
            }
        }

        return null;
    }

    /**
     * 取指定方向的通道
     *
     * @param  list<PaymentChannel>  $channels
     * @return list<PaymentChannel>
     */
    private function channelsByDirection(array $channels, PaymentChannelDirection $direction): array
    {
        return array_values(array_filter(
            $channels,
            static fn (PaymentChannel $channel): bool => $channel->direction === $direction
        ));
    }

    /**
     * 收集我方通道限額
     *
     * @return list<array{paymentCode: string, source: string, min: string, max: string}>
     */
    private function collectOurChannels(AgentCashFlow $agentCashFlow): array
    {
        if ($agentCashFlow->cashFlow?->type === CashFlowTypeEnum::WITHDRAW->value) {
            $min = $agentCashFlow->info['min_amount'] ?? null;
            $max = $agentCashFlow->info['max_amount'] ?? null;

            if (!is_numeric($min) || !is_numeric($max)) {
                return [];
            }

            // 外部值入境即以 bc 正規化，避免 (string) float 帶進精度誤差
            return [[
                'paymentCode' => self::PAYOUT_CHANNEL,
                'source' => sprintf('acf #%d info', $agentCashFlow->id),
                'min' => Common::numFloor($min, self::AMOUNT_SCALE),
                'max' => Common::numFloor($max, self::AMOUNT_SCALE),
            ]];
        }

        $agentCashFlow->load('agentCashFlowOrderTypes.orderType.parentOrderType');

        $channels = [];
        foreach ($agentCashFlow->agentCashFlowOrderTypes as $acfot) {
            $orderType = $acfot->orderType;

            if ($orderType === null) {
                continue;
            }

            $channels[] = [
                'paymentCode' => (string) ($orderType->parentOrderType?->key ?? $orderType->key),
                'source' => sprintf('acfot #%d／ot #%d status=%d', $acfot->id, $orderType->id, $acfot->status),
                'min' => Common::numFloor($acfot->min_amount, self::AMOUNT_SCALE),
                'max' => Common::numFloor($acfot->max_amount, self::AMOUNT_SCALE),
            ];
        }

        return $channels;
    }

    /**
     * 比對單一限額值；`0` 在我方語意是「不限制」（見 AgentCashFlowOrderType::$min_amount 註解）
     *
     * @param  bool  $isUpperBound  上限與下限的「寬/嚴」方向相反
     */
    private function compareOne(string $ourValue, mixed $theirValue, bool $isUpperBound): string
    {
        if (!is_numeric($theirValue)) {
            return sprintf('三方未回傳（我方 %s）', $this->limitLabel($ourValue));
        }

        $theirNormalized = Common::numFloor($theirValue, self::AMOUNT_SCALE);

        // 我方 0＝不限制，任何有限的三方值都比它嚴 ⇒ 我方寬
        if (Common::numCompare($ourValue, '0', self::AMOUNT_SCALE) === 0) {
            return sprintf('⚠️ 不限 vs %s（我方寬）', $theirNormalized);
        }

        $comparison = Common::numCompare($ourValue, $theirNormalized, self::AMOUNT_SCALE);

        if ($comparison === 0) {
            return sprintf('✓ %s', $theirNormalized);
        }

        // 上限：我方大於三方＝寬；下限：我方小於三方＝寬
        $isLooser = $isUpperBound ? $comparison > 0 : $comparison < 0;

        return sprintf(
            '%s %s vs %s（我方%s）',
            $isLooser ? '⚠️' : '△',
            $ourValue,
            $theirNormalized,
            $isLooser ? '寬' : '嚴'
        );
    }

    /**
     * 限額顯示：0 在我方語意是不限制
     */
    private function limitLabel(string $value): string
    {
        return Common::numCompare($value, '0', self::AMOUNT_SCALE) === 0 ? '不限' : $value;
    }

    /**
     * 比對我方 available_banks 的三方碼與三方通道的支援銀行聯集
     *
     * 聯集範圍依 ACF 方向取同方向通道（代付 ACF 取代付通道、代收 ACF 取代收通道）——
     * 跨方向聯集會把代收才有的銀行算進代付可用清單，反過來掩蓋真正的缺口。
     *
     * @param  list<PaymentChannel>  $channels
     */
    private function compareBankCodes(AgentCashFlow $agentCashFlow, array $channels): void
    {
        $this->newLine();
        $this->info('▍代付銀行清單比對');

        $availableBanks = $agentCashFlow->info['available_banks'] ?? null;

        if (!is_array($availableBanks) || $availableBanks === []) {
            $this->line('  我方 info 無 available_banks（代收渠道正常無此欄位），略過比對');

            return;
        }

        $ours = [];
        foreach ($availableBanks as $bank) {
            if (!is_array($bank)) {
                continue;
            }

            $code = $bank['BankCode_Third'] ?? null;

            if (is_string($code) && $code !== '') {
                $ours[$code] = true;
            }
        }

        $direction = $agentCashFlow->cashFlow?->type === CashFlowTypeEnum::WITHDRAW->value
            ? PaymentChannelDirection::Payout
            : PaymentChannelDirection::Deposit;

        $theirs = [];
        foreach ($this->channelsByDirection($channels, $direction) as $channel) {
            foreach ($channel->supportBankCodes as $code) {
                $theirs[$code] = true;
            }
        }

        if ($theirs === []) {
            $this->warn(sprintf('  三方所有線路都沒回傳支援銀行，我方配置了 %d 家——無法確認任一家可用', count($ours)));

            return;
        }

        $onlyOurs = array_keys(array_diff_key($ours, $theirs));
        $onlyTheirs = array_keys(array_diff_key($theirs, $ours));

        $this->line(sprintf('  我方 %d 家／三方 %d 家（各線路聯集）', count($ours), count($theirs)));

        if ($onlyOurs === []) {
            $this->line('  ✓ 我方每一家都在三方清單內');
        } else {
            sort($onlyOurs);
            $this->warn(sprintf('  🔴 我方有、三方沒有（送出會被三方拒）%d 家：%s', count($onlyOurs), implode(', ', $onlyOurs)));
        }

        if ($onlyTheirs === []) {
            return;
        }

        sort($onlyTheirs);
        $this->line(sprintf('  💡 三方有、我方沒有（可評估補上）%d 家：%s', count($onlyTheirs), implode(', ', $onlyTheirs)));
    }

    /**
     * 純量轉可讀字串；缺值統一顯示佔位符，避免把「未回傳」誤讀成空值
     */
    private function stringOrPlaceholder(mixed $value): string
    {
        if (is_string($value)) {
            return $value === '' ? '（空字串）' : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '（未回傳）';
    }
}
