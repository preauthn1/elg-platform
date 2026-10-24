<?php

declare(strict_types=1);

namespace App\Console\Commands\Test\Payment;

use App\Models\AgentCashFlow;
use App\Services\Payment\Providers\LegendPay\Driver as LegendPayDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 測試代理的遊戲商戶配置能否順利完成以下操作
 * 1. 建立遊戲帳號
 * 2. 餘額轉入遊戲
 * 3. 查詢遊戲餘額
 * 4. 取得遊戲網址
 * 5. 餘額轉回本地
 * 6. 查詢遊戲餘額
 *
 * 🔴 連線設定與憑證（API_URL、API_TOKEN、API_SECRET）一律從 --acf 指定的 LegendPay 商戶設定
 * （agent_cash_flows.info）讀取，禁止寫回程式碼。鍵名與正式流程（LegendPay\Deposit、LegendPay\Withdrawal、
 * LegendPayTrait）相同。
 * 找不到商戶、不是 LegendPay 商戶、或缺鍵時以非 0 結束，錯誤訊息只寫 id 與鍵名，不寫憑證值。
 *
 * 使用範例：php artisan test:LegendPay --acf=123
 */
class TestLegendPay extends Command
{
    protected $signature = 'test:LegendPay
                            {--acf= : LegendPay 商戶設定的 agent_cash_flows.id（必填，憑證從它的 info 讀取）}';

    protected $description = '測試遊戲配置能否正常運作';

    /** 與 LegendPay\Withdrawal 檢查的必填鍵相同 */
    private const array REQUIRED_INFO_KEYS = ['API_URL', 'API_TOKEN', 'API_SECRET'];

    private array $vendorInfo = [];

    public function handle(): int
    {
        $error = $this->loadCredentials();
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        // $result = $this->payIn();
        // $this->info(json_encode($result));

        $result = $this->payOut();
        $this->info(json_encode($result));

        return self::SUCCESS;
    }

    /**
     * 從 --acf 指定的商戶設定讀出憑證放進 vendorInfo；失敗時回錯誤訊息（不含憑證值），成功回 null。
     */
    private function loadCredentials(): ?string
    {
        $acfId = (int) $this->option('acf');
        if ($acfId <= 0) {
            return '請用 --acf=<agent_cash_flows.id> 指定 LegendPay 商戶設定';
        }

        $agentCashFlow = AgentCashFlow::query()->with('cashFlow')->find($acfId);
        if ($agentCashFlow === null) {
            return "找不到 agent_cash_flows.id={$acfId}";
        }

        // 與 PaymentService::getProvider() 相同的判定：third_party_code 去掉前兩碼後對 config/payment.php
        $providerCode = strtoupper(substr((string) $agentCashFlow->third_party_code, 2));
        if (config('payment.provider.' . $providerCode) !== LegendPayDriver::class) {
            return "agent_cash_flows.id={$acfId} 不是 LegendPay 商戶設定";
        }

        $info = (array) $agentCashFlow->info;
        $missing = array_values(array_filter(
            self::REQUIRED_INFO_KEYS,
            static fn (string $key): bool => !isset($info[$key]) || $info[$key] === '',
        ));
        if ($missing !== []) {
            return "agent_cash_flows.id={$acfId} 的 info 缺少 " . implode('、', $missing);
        }

        foreach (self::REQUIRED_INFO_KEYS as $key) {
            $this->vendorInfo[$key] = (string) $info[$key];
        }

        return null;
    }

    public function payIn(): array
    {
        $params = [
            'mode' => '0',
            'random' => Str::random(16),
            'orderAmount' => '100.00',
            'orderId' => 'TEST' . time(),
            'param' => '', // 商户自定义回传参数
            'callbackUrl' => 'https://example.invalid/api/cashflow/166',
            'returnUrl' => 'https://example.invalid/Deposit#result',
            'ts' => (int) (microtime(true) * 1000), // 毫秒时间戳
        ];

        $this->info(json_encode($params));

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'l-token' => $this->vendorInfo['API_TOKEN'],
            'l-sign' => $this->generateSign($params, $this->vendorInfo),
        ];

        $url = $this->vendorInfo['API_URL'] . '/LegendPay/payin';

        return Http::withHeaders($headers)
            ->asForm()
            ->post($url, $params)
            ->json();
    }

    public function payOut(): array
    {
        $params = [
            // 'amount' => '101.00',
            // 'callbackUrl' => 'https://example.invalid/api/LegendPay/withdrawal/callback',
            // 'account' => '201000696127',
            // 'ts' => (int) (microtime(true) * 1000),
            // 'orderId' => 'TEST' . time(),
            // 'random' => 'UeGwoA6IAGNCzxvy',
            // 'param' => '',
            // 'personName' => 'Benji',
            // 'personPhone' => '6453092843',
            // 'ifsc' => 'ADBK0000001',

            'account' => '484561546',
            'amount' => '100.00',
            'callbackUrl' => 'https://example.invalid/api/LegendPay/withdrawal/callback',
            'ifsc' => '544566',
            'orderId' => 'TEST' . time(),
            'param' => '',
            'personName' => rawurlencode('Ben Jem'),
            'personPhone' => '6654882528',
            'random' => Str::random(16),
            'ts' => (int) (microtime(true) * 1000), // 毫秒时间戳

        ];

        $this->info(json_encode($params));

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'l-token' => $this->vendorInfo['API_TOKEN'],
            'l-sign' => $this->generateSign($params, $this->vendorInfo),
        ];

        $url = $this->vendorInfo['API_URL'] . '/LegendPay/payout';

        return Http::withHeaders($headers)
            ->asForm()
            ->post($url, $params)
            ->json();
    }

    private function generateSign($params, $info): string
    {
        // 只移除 null 與空字串，保留 0 與 '0'
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 若回傳欄位名稱為 signature，排除它
        unset($filtered['sign']);

        ksort($filtered);

        // 與 LegendPayTrait::generateSign 同步：urldecode 才會把 + 還原成空格（含空格值簽名正確）
        $sign = urldecode(http_build_query($filtered));
        $sign = $sign . '&key=' . $info['API_SECRET'];
        // 簽名原文含 API_SECRET，只印雜湊後的簽名；l-token header 同理不印
        $sign = strtoupper(md5($sign));
        $this->info($sign);
        $this->newLine();

        return $sign;
    }
}
