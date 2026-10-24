<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Console/Commands/CashFlow/ThirdPartyQueryTest.php
 *
 * 以固定高位 id 建資料，避免與其他測試或 app:upgrade 的主檔 id 相撞；
 * 資料本身由 TestCase 的 DatabaseTransactions 自動回滾，不需手動清除。
 *
 * 三方 payload 的線路限額刻意比照 VWorld SPMVW 商戶 2026-08-25 實測值
 * （DUITNOWP2P 上限僅 1000、我方配 20000），確保「我方寬」這條判定有真實案例護欄。
 *
 * ! PendingCommand 的 expectsOutputToContain 是**逐行消耗**：命中某行後指標即移到下一行。
 * ! 因此同一次 artisan 呼叫的多個斷言必須落在**不同輸出行**且順序與行序一致——
 * ! 同一列表格的左右兩欄不能一起斷言（會找不到後者），需拆成兩個測試。
 */
class ThirdPartyQueryTest extends TestCase
{
    private const int CASH_FLOW_ID_PAYOUT = 999901;

    private const int CASH_FLOW_ID_OTHER_PROVIDER = 999902;

    private const int CASH_FLOW_ID_DEPOSIT = 999903;

    private const int CASH_FLOW_ID_FPAY = 999904;

    private const int ACF_ID_PAYOUT = 999901;

    private const int ACF_ID_OTHER_PROVIDER = 999902;

    private const int ACF_ID_DEPOSIT = 999903;

    private const int ACF_ID_FPAY = 999904;

    private const int ORDER_TYPE_ID_P2P = 999901;

    private const int ORDER_TYPE_ID_DUITNOW = 999902;

    private const string MERCHANT_NO = 'TESTMERCHANT';

    private const string MD5_KEY = 'test-md5-key-0000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        // 代付 ACF：限額存 info，對應三方 PAYOUT 線路
        $this->makeCashFlow(self::CASH_FLOW_ID_PAYOUT, 'W_SPEEDPAY', 3);
        $this->makeAgentCashFlow(self::ACF_ID_PAYOUT, self::CASH_FLOW_ID_PAYOUT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
            'min_amount' => 50,
            'max_amount' => 4999,
            'available_banks' => [
                ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'MAYB'],
                ['BankCode_ELG' => 'CIMB Bank', 'BankCode_Third' => 'CIMB'],
                ['BankCode_ELG' => 'OCBC Bank', 'BankCode_Third' => 'OCBC'],
            ],
        ]);
    }

    public function test_payout_limits_compared_against_payout_channel(): void
    {
        $this->fakeSetting($this->successPayload());

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_PAYOUT])
            ->assertSuccessful()
            ->expectsOutputToContain('payoutStatus')
            // 我方上限 4999 低於三方 5000 → 保守（不會被拒，但沒用滿三方額度）
            // 下限（兩邊都是 50）在同一列的左欄，受下述逐行限制無法在同一呼叫內一起斷言；
            // 「一致→✓」的行為由 test_deposit_limits_compared_via_order_type_key 覆蓋
            ->expectsOutputToContain('△ 4999.0000 vs 5000.0000（我方嚴）')
            // 代付 ACF 只對應 PAYOUT，另兩條代收線路屬未配置
            // ! 斷言順序須與輸出順序一致（PendingCommand 逐段消耗，見 tests 內其他命令測試慣例）
            ->expectsOutputToContain('三方有、我方未配置的線路 2 條：DUITNOWP2P, P2PDEPOSIT')
            // 我方有 OCBC、三方 supportBankCode 沒有 → 送出會被拒
            ->expectsOutputToContain('我方有、三方沒有（送出會被三方拒）1 家：OCBC')
            // 三方有 PBBB、我方未配置 → 可評估補上
            ->expectsOutputToContain('三方有、我方沒有（可評估補上）1 家：PBBB');
    }

    public function test_deposit_limits_compared_via_order_type_key(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_DEPOSIT, 'D_SPEEDPAY', 1);
        $this->makeAgentCashFlow(self::ACF_ID_DEPOSIT, self::CASH_FLOW_ID_DEPOSIT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ]);

        // order_types.key 即 paymentCode（見 PayMate\Deposit 組參處）
        $this->makeOrderType(self::ORDER_TYPE_ID_P2P, self::CASH_FLOW_ID_DEPOSIT, 'P2PDEPOSIT');
        $this->makeOrderType(self::ORDER_TYPE_ID_DUITNOW, self::CASH_FLOW_ID_DEPOSIT, 'DUITNOWP2P');

        $this->makeAgentCashFlowOrderType(self::ACF_ID_DEPOSIT, self::ORDER_TYPE_ID_P2P, 10, 20000);
        $this->makeAgentCashFlowOrderType(self::ACF_ID_DEPOSIT, self::ORDER_TYPE_ID_DUITNOW, 5, 20000);

        $this->fakeSetting($this->successPayload());

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT])
            ->assertSuccessful()
            // P2PDEPOSIT 兩邊一致
            ->expectsOutputToContain('✓ 20000.0000')
            // DUITNOWP2P 我方上限 20000、三方只有 1000 → 超限訂單送出後才被拒
            ->expectsOutputToContain('⚠️ 20000.0000 vs 1000.0000（我方寬）')
            ->expectsOutputToContain('三方有、我方未配置的線路 1 條：PAYOUT')
            // 代收 ACF 沒有 available_banks
            ->expectsOutputToContain('我方 info 無 available_banks');
    }

    public function test_unlimited_lower_bound_is_flagged_as_looser(): void
    {
        $this->prepareUnlimitedDepositChannel();

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT])
            ->assertSuccessful()
            ->expectsOutputToContain('⚠️ 不限 vs 10.0000（我方寬）');
    }

    public function test_unlimited_upper_bound_is_flagged_as_looser(): void
    {
        $this->prepareUnlimitedDepositChannel();

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT])
            ->assertSuccessful()
            ->expectsOutputToContain('⚠️ 不限 vs 20000.0000（我方寬）');
    }

    /**
     * 我方 min_amount／max_amount 皆為 0（＝不限制），三方兩端都有限
     *
     * ! 上下限拆成兩個測試：同一列的兩格斷言放在同一個 artisan 呼叫時，
     * ! PendingCommand 的 substring 比對會互搶（見 tests 內其他命令的同型處理）。
     */
    private function prepareUnlimitedDepositChannel(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_DEPOSIT, 'D_SPEEDPAY', 1);
        $this->makeAgentCashFlow(self::ACF_ID_DEPOSIT, self::CASH_FLOW_ID_DEPOSIT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ]);
        $this->makeOrderType(self::ORDER_TYPE_ID_P2P, self::CASH_FLOW_ID_DEPOSIT, 'P2PDEPOSIT');
        $this->makeAgentCashFlowOrderType(self::ACF_ID_DEPOSIT, self::ORDER_TYPE_ID_P2P, 0, 0);

        $this->fakeSetting($this->successPayload());
    }

    public function test_channel_missing_on_third_party_is_flagged(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_DEPOSIT, 'D_SPEEDPAY', 1);
        $this->makeAgentCashFlow(self::ACF_ID_DEPOSIT, self::CASH_FLOW_ID_DEPOSIT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ]);
        // key 為純數字＝bankcode 路徑（應與三方通道碼逐一對應），三方 payload 不含此碼
        // → 我方開了三方沒有的通道，屬真異常。非數字 key 走 contractname 路徑不適用此判定，
        //   見 test_contractname_style_channel_is_reported_as_not_applicable
        $this->makeOrderType(self::ORDER_TYPE_ID_P2P, self::CASH_FLOW_ID_DEPOSIT, '10029999');
        $this->makeAgentCashFlowOrderType(self::ACF_ID_DEPOSIT, self::ORDER_TYPE_ID_P2P, 10, 5000);

        $this->fakeSetting($this->successPayload());

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT])
            ->assertSuccessful()
            ->expectsOutputToContain('🔴 三方無此線路');
    }

    /**
     * contractname 路徑（`order_types.key` 非純數字，如 SurePay MYR 的 `OB`）本來就不對應
     * 單一三方通道碼，比對不上不是缺口——不得報 🔴，否則該類 ACF 會永遠滿屏紅字，
     * 把「key 是數字卻真的找不到通道」這種真異常稀釋掉。
     */
    public function test_contractname_style_channel_is_reported_as_not_applicable(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_DEPOSIT, 'D_SPEEDPAY', 1);
        $this->makeAgentCashFlow(self::ACF_ID_DEPOSIT, self::CASH_FLOW_ID_DEPOSIT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ]);
        // 我方 key 是 OB（非數字＝contractname 路徑），三方回的線路名都不是 OB
        $this->makeOrderType(self::ORDER_TYPE_ID_P2P, self::CASH_FLOW_ID_DEPOSIT, 'OB');
        $this->makeAgentCashFlowOrderType(self::ACF_ID_DEPOSIT, self::ORDER_TYPE_ID_P2P, 10, 9999);

        $this->fakeSetting($this->successPayload());

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT])
            ->assertSuccessful()
            ->expectsOutputToContain('不適用（contractname 路徑）')
            ->doesntExpectOutputToContain('🔴 三方無此線路');
    }

    public function test_raw_option_outputs_json_without_comparison(): void
    {
        $this->fakeSetting($this->successPayload());

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_PAYOUT, '--raw' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"payoutStatus": "ON"')
            ->doesntExpectOutputToContain('限額比對');
    }

    public function test_fails_when_response_signature_invalid(): void
    {
        // 信封欄位齊全但 signMsg 錯 → unwrapEnvelope 拋例外，命令須明確失敗而非印半套資料
        Http::fake([
            '*/setting' => Http::response([
                'version' => '1.0',
                'merchantNo' => self::MERCHANT_NO,
                'resCode' => '000',
                'resMsg' => 'success',
                'response' => base64_encode((string) json_encode($this->successPayload())),
                'signMsg' => strtoupper(md5('wrong-signature')),
            ]),
        ]);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_PAYOUT])
            ->assertFailed()
            ->expectsOutputToContain('查詢失敗');
    }

    public function test_fails_when_envelope_rejected(): void
    {
        // 信封層拒絕（resCode != 000）：sendRequest 直接拋，不應被當成「查到但欄位空」
        Http::fake([
            '*/setting' => Http::response([
                'version' => '1.0',
                'merchantNo' => self::MERCHANT_NO,
                'resCode' => '101',
                'resMsg' => 'merchant not found',
                'response' => '',
                'signMsg' => strtoupper(md5('irrelevant')),
            ]),
        ]);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_PAYOUT])
            ->assertFailed()
            ->expectsOutputToContain('查詢失敗');
    }

    public function test_fails_when_agent_cash_flow_missing(): void
    {
        $this->artisan('cash-flow:third-party-query', ['acf' => 999999999])
            ->assertFailed()
            ->expectsOutputToContain('不存在');
    }

    /**
     * 能力偵測：provider 未實作 SupportsPaymentChannelQuery 時明確報「不支援」，
     * 不再是「僅支援 SE PayCenter 協定」的類別綁定訊息。
     */
    public function test_rejects_provider_without_channel_query_capability(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_OTHER_PROVIDER, 'W_ZPAY', 3);
        $this->makeAgentCashFlow(self::ACF_ID_OTHER_PROVIDER, self::CASH_FLOW_ID_OTHER_PROVIDER, [
            'API_URL' => 'https://other.test',
        ]);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_OTHER_PROVIDER])
            ->assertFailed()
            ->expectsOutputToContain('不支援通道查詢');
    }

    /**
     * FPay（target/op_tickets#3174）：available_banks 的 BankCode_Third 對 /wallet/withdraw_bank_list 回傳的 id。
     * 我方有、三方沒有的代號，送出就會被 FPay 回 bank id invalid。
     */
    public function test_fpay_payout_bank_ids_compared_against_withdraw_bank_list(): void
    {
        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->makeCashFlow(self::CASH_FLOW_ID_FPAY, 'W_FPAY', 3);
        $this->makeAgentCashFlow(self::ACF_ID_FPAY, self::CASH_FLOW_ID_FPAY, [
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'available_banks' => [
                ['BankCode_ELG' => 'TPID01', 'BankCode_Third' => '2'],
                ['BankCode_ELG' => 'TPID03', 'BankCode_Third' => '7'],
            ],
        ], $currency->id);

        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => [
                ['currency' => 'IDR', 'bank_name' => 'Bank Mandiri', 'id' => '2'],
                ['currency' => 'IDR', 'bank_name' => 'Bank Negara Indonesia', 'id' => '3'],
            ],
        ])]);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_FPAY])
            ->assertSuccessful()
            // 我方的 7 不在三方清單內 → 送出會被回 bank id invalid
            ->expectsOutputToContain('我方有、三方沒有（送出會被三方拒）1 家：7')
            // 三方有 3、我方未配置
            ->expectsOutputToContain('三方有、我方沒有（可評估補上）1 家：3');
    }

    /**
     * --order 模式：provider 實作 SupportsDepositQuery 時，以我方訂單號查三方並輸出映射結果。
     */
    public function test_order_mode_queries_deposit_order(): void
    {
        $this->prepareDepositAcf();
        $order = $this->createOrderForAcf(self::ACF_ID_DEPOSIT);
        $this->fakeQueryEnvelope(['code' => '0000', 'msg' => 'ok', 'status' => 'SUCCESS', 'orderNo' => $order->order_no]);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT, '--order' => $order->order_no])
            ->assertSuccessful()
            ->expectsOutputToContain('SUCCESS');
    }

    /**
     * 範疇防護：訂單必須屬於指定 acf，防止拿 A 通道的憑證查 B 通道的單。
     */
    public function test_order_mode_rejects_order_of_other_acf(): void
    {
        $this->prepareDepositAcf();
        $order = $this->createOrderForAcf(self::ACF_ID_PAYOUT);

        $this->artisan('cash-flow:third-party-query', ['acf' => self::ACF_ID_DEPOSIT, '--order' => $order->order_no])
            ->assertFailed()
            ->expectsOutputToContain('不屬於');
    }

    /**
     * --order 模式的 provider 未實作 SupportsDepositQuery 時明確報「不支援代收查單」——
     * 且不得被通道查詢的 ChannelQuery 閘先擋下（訊息會指向錯誤的能力）。
     */
    public function test_order_mode_rejects_provider_without_deposit_query_capability(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_OTHER_PROVIDER, 'W_ZPAY', 3);
        $this->makeAgentCashFlow(self::ACF_ID_OTHER_PROVIDER, self::CASH_FLOW_ID_OTHER_PROVIDER, [
            'API_URL' => 'https://other.test',
        ]);

        $this->artisan('cash-flow:third-party-query', [
            'acf' => self::ACF_ID_OTHER_PROVIDER,
            '--order' => 'TPQ-NOT-EXIST',
        ])
            ->assertFailed()
            ->expectsOutputToContain('不支援代收查單');
    }

    /** 代收 ACF（PayMate driver，實作 SupportsDepositQuery）；沿用本檔既有欄位組 */
    private function prepareDepositAcf(): void
    {
        $this->makeCashFlow(self::CASH_FLOW_ID_DEPOSIT, 'D_SPEEDPAY', 1);
        $this->makeAgentCashFlow(self::ACF_ID_DEPOSIT, self::CASH_FLOW_ID_DEPOSIT, [
            'API_URL' => 'https://paycenter.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
        ]);
    }

    /** 建一筆綁定指定 ACF 的代收訂單 */
    private function createOrderForAcf(int $acfId): Order
    {
        $agent = $this->createAgentAndDomain();
        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => self::CASH_FLOW_ID_DEPOSIT, 'currency_id' => 1, 'key' => 'P2PDEPOSIT'],
            ['name' => 'PayMate P2PDEPOSIT', 'status' => 1, 'info' => '{}', 'admin' => 0]
        );

        return Order::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'order_type_id' => $orderType->id,
            'agent_cash_flow_id' => $acfId,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'TPQ' . uniqid(),
            'status' => 0,
            'rate' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /** fake PayMate /query 的信封回應（同 fakeSetting 的組裝方式，路徑改 /query） */
    private function fakeQueryEnvelope(array $inner): void
    {
        $envelope = [
            'version' => '1.0',
            'merchantNo' => self::MERCHANT_NO,
            'resCode' => '000',
            'resMsg' => 'success',
            'response' => base64_encode((string) json_encode($inner)),
        ];

        $envelope['signMsg'] = $this->sign($envelope);

        Http::fake(['*/query' => Http::response($envelope)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(): array
    {
        return [
            'code' => '0000',
            'msg' => '查詢成功',
            'merchantStatus' => 'ON',
            'depositStatus' => 'ON',
            'payoutStatus' => 'ON',
            'payments' => [
                [
                    'name' => 'PAYOUT',
                    'status' => 'ON',
                    'minAmount' => '50.00',
                    'maxAmount' => '5000.00',
                    'todayLimit' => '0.00',
                    'totalAmount' => '0.00',
                    'supportBankCode' => 'MAYB,CIMB,PBBB',
                ],
                [
                    'name' => 'P2PDEPOSIT',
                    'status' => 'ON',
                    'minAmount' => '10.00',
                    'maxAmount' => '20000.00',
                    'todayLimit' => '0.00',
                    'totalAmount' => '0.00',
                    'supportBankCode' => '',
                ],
                [
                    'name' => 'DUITNOWP2P',
                    'status' => 'ON',
                    'minAmount' => '5.00',
                    'maxAmount' => '1000.00',
                    'todayLimit' => '0.00',
                    'totalAmount' => '0.00',
                    'supportBankCode' => '',
                ],
            ],
        ];
    }

    /**
     * 以 api.md §2.1 的 Response 簽名規則組出合法信封
     *
     * @param  array<string, mixed>  $payload
     */
    private function fakeSetting(array $payload): void
    {
        $envelope = [
            'version' => '1.0',
            'merchantNo' => self::MERCHANT_NO,
            'resCode' => '000',
            'resMsg' => 'success',
            'response' => base64_encode((string) json_encode($payload)),
        ];

        $envelope['signMsg'] = $this->sign($envelope);

        Http::fake(['*/setting' => Http::response($envelope)]);
    }

    /**
     * 依 api.md §1.1／§2.1：欄位名升冪排序、`name:value` 以 & 拼接、末尾接 key，MD5 後轉大寫
     *
     * @param  array<string, string>  $fields
     */
    private function sign(array $fields): string
    {
        ksort($fields, SORT_STRING);

        $signStr = '';
        foreach ($fields as $key => $value) {
            $signStr .= $key . ':' . $value . '&';
        }

        return strtoupper(md5($signStr . 'key:' . self::MD5_KEY));
    }

    private function makeCashFlow(int $id, string $thirdPartyCode, int $type): void
    {
        $cashFlow = new CashFlow;
        $cashFlow->forceFill([
            'id' => $id,
            'third_party_code' => $thirdPartyCode,
            'name' => 'Test ' . $thirdPartyCode,
            'type' => $type,
            'status' => 1,
            'admin' => 0,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  ?int  $currencyId  需要幣別的 provider 才傳（FPay 查銀行清單要帶幣別）；不傳則沿用欄位預設值
     */
    private function makeAgentCashFlow(int $id, int $cashFlowId, array $info, ?int $currencyId = null): void
    {
        $attributes = [
            'id' => $id,
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => 0,
            'name' => 'Test ACF ' . $id,
            'info' => $info,
            'status' => 1,
            'admin' => 0,
        ];

        if ($currencyId !== null) {
            $attributes['currency_id'] = $currencyId;
        }

        $agentCashFlow = new AgentCashFlow;
        $agentCashFlow->forceFill($attributes)->save();
    }

    private function makeOrderType(int $id, int $cashFlowId, string $key): void
    {
        $orderType = new OrderType;
        $orderType->forceFill([
            'id' => $id,
            'cash_flow_id' => $cashFlowId,
            'parent' => 0,
            'name' => 'Test ' . $key,
            'key' => $key,
            'type' => 1,
            'currency_id' => 1,
            'status' => 1,
            'admin' => 0,
        ])->save();
    }

    private function makeAgentCashFlowOrderType(int $agentCashFlowId, int $orderTypeId, int $min, int $max): void
    {
        $agentCashFlowOrderType = new AgentCashFlowOrderType;
        $agentCashFlowOrderType->forceFill([
            'cash_flow_id' => self::CASH_FLOW_ID_DEPOSIT,
            'agent_cash_flow_id' => $agentCashFlowId,
            'order_type_id' => $orderTypeId,
            'min_amount' => $min,
            'max_amount' => $max,
            'available_amount' => '',
            'settings' => '',
            'status' => 1,
        ])->save();
    }
}
