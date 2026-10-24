<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

use Spatie\LaravelData\Data;

/**
 * DgPay info（agent_cash_flows.info）欄位 DTO。
 *
 * 各參數在「代收（Deposit）/ 代付（Withdrawal）× V1 / V2」四象限的使用狀況
 * （✅=參與 param／簽名／路由；❌=該路徑不使用；⚠️=條件式）：
 *
 * | info key      | 代收V1 | 代收V2 | 代付V1 | 代付V2 | 說明（file:line）                                                                    |
 * |---------------|:------:|:------:|:------:|:------:|--------------------------------------------------------------------------------------|
 * | API_URL       |   ✅   |   ✅   |   ✅   |   ✅   | API base URL＋endpoint（Deposit:269 / Withdrawal:190）                                |
 * | VERSION       |   ✅   |   ✅   |   ✅   |   ✅   | 決定走 V1/V2 路徑本身；缺省 V1（Deposit:65,92 / Withdrawal:58,91,216）                 |
 * | CURRENCY      |   ✅   |   ✅   |   ✅   |   ✅   | 送三方 currency＋進簽名＋通道分流；必填、無 fallback（Withdrawal:89,157,219,229）      |
 * | MERCHANT      |   ✅   |   ✅   |   ✅   |   ✅   | opCode，param＋簽名；回調也讀（Deposit:75,81 / Withdrawal:93,114 / DgPayTrait:15）     |
 * | MERCHANT_KEY  |   ✅   |   ✅   |   ✅   |   ✅   | 簽名密鑰（md5 尾段）＋回調驗簽（Deposit:296,308 / Withdrawal:224,233 / DgPayTrait:23） |
 * | PROVIDER_ID   |   ✅   |   ❌   |   ✅   |   ❌   | 僅 V1：param＋簽名＋路由；代付 MYR id=10&type=10→Manual 否則 Auto（Withdrawal:108,158,227）。代收 param/簽名（Deposit:69,290），缺省 0 由 orderType 自動解析（Deposit:58,143 / getProviderData:226） |
 * | PROVIDER_TYPE |   ✅   |   ❌   |   ✅   |   ❌   | 同 PROVIDER_ID（Deposit:70,291 / Withdrawal:109,158,228）                             |
 * | PAYMENT_CODE  |   ❌   |   ⚠️   |   ❌   |   ❌   | 僅代收 V2：paymentType param＋簽名；非空=override 全通道、空值由 channel key 動態解析（Deposit::resolvePaymentType，官方 §7） |
 * | DEFAULT_EMAIL |   ⚠️   |   ⚠️   |   ❌   |   ❌   | 僅代收 THB Qris 通道，member email 空時 fallback（Deposit:201）                       |
 *
 * 註：V2 代付（SGD）走 recipientInfo、不帶 providerId/providerType/data；V2 代收（SGD）走
 * paymentType＋sender。回調鏈（DgPayTrait）直讀 $this->info 取 MERCHANT/MERCHANT_KEY 驗簽，
 * 未經本 DTO。
 */
class InfoData extends Data
{
    public function __construct(
        public string $apiUrl,       // 全象限：API base URL＋endpoint
        public string $version,      // 全象限：選 V1/V2 路徑本身；缺省 V1
        public string $currency,     // 全象限必填：param＋簽名＋通道分流，無 fallback
        public int $providerId,      // 僅 V1（代收+代付）：param＋簽名＋路由；V2 不用
        public int $providerType,    // 僅 V1（代收+代付）：同 providerId
        public string $paymentCode,  // 僅代收 V2：paymentType param＋簽名；空值時由 channel key 動態解析
        public string $merchant,     // 全象限必填：opCode，param＋簽名；回調也讀
        public string $defaultEmail, // 僅代收 THB Qris：member email 空時 fallback
        public string $merchantKey,  // 全象限必填：簽名密鑰＋回調驗簽
    ) {}

    /**
     * 由 agent_cash_flow.info 陣列建構 DTO。
     *
     * 命名刻意避開 `from` 前綴：Spatie LaravelData 會把 `from*` 靜態方法註冊為 magic named
     * constructor，若命名為 fromInfo，內部 self::from([...]) 傳入 array 會被再度 dispatch 回本方法
     * 造成無窮遞迴（SIGSEGV），故改用 mapInfo。
     *
     * 新組（DGPAY，cash_flows 119/120）採一致的 SCREAMING_SNAKE key；
     * 舊組（DG，cash_flows 33/39/40/45/54…）部分 key 為 PascalCase（APIUrl/Currency/Merchant/MerchantKey/DefaultEmail），
     * 兩組共用同一個 DgPay Driver，故此處對 PascalCase key 以「新 key 優先、fallback 舊 key」相容；
     * VERSION / PROVIDER_ID / PROVIDER_TYPE / PAYMENT_CODE 新舊皆為大寫 snake，單一鍵即可。
     */
    public static function mapInfo(array $info): self
    {
        return self::from([
            'apiUrl' => $info['API_URL'] ?? $info['APIUrl'] ?? '',
            'version' => $info['VERSION'] ?? 'V1',
            'currency' => $info['CURRENCY'] ?? $info['Currency'] ?? '',
            // (int) 強制轉型：模板／DB 可能存空字串 '' 或數字字串（如 "5"），
            // 而 ?? 只接 null 不接 ''；缺此轉型會讓 strict_types 下 int 建構參數收到 '' 拋 TypeError。
            'providerId' => (int) ($info['PROVIDER_ID'] ?? 0),
            'providerType' => (int) ($info['PROVIDER_TYPE'] ?? 0),
            'paymentCode' => $info['PAYMENT_CODE'] ?? '',
            'merchant' => $info['MERCHANT'] ?? $info['Merchant'] ?? '',
            'merchantKey' => $info['MERCHANT_KEY'] ?? $info['MerchantKey'] ?? '',
            'defaultEmail' => $info['DEFAULT_EMAIL'] ?? $info['DefaultEmail'] ?? '',
        ]);
    }
}
