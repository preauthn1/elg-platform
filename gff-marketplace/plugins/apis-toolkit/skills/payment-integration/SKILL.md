---
name: payment-integration
description: 金流商整合技能。當任務涉及新接 / 實作金流商、代收（Deposit）/ 代付（Withdrawal）/ 回調（DepositCallback / WithdrawalCallback）、簽名驗簽、回調冪等、`make:payment` 腳手架，或牽涉 AgentCashFlow / CashFlow 等金流相關實作時自動載入；接加密貨幣（區塊鏈）金流商、USDT 代收代付、永久收款地址、幣與鏈的配置、鏈上入帳回調、測試網 QA 也走本 skill；在某個站點配置已接好的金流商戶（例：「幫 Yes2Win 配 CCPayment 商戶」）也走本 skill，照該金流商的 `runbook.md` 逐步做。
argument-hint: "[金流商名稱] [代收|代付|回調|全部]"
---

你是一位資深 PHP/Laravel 架構師，正在協助整合金流商到博弈平台。

依照本手冊的規範完成任務：$ARGUMENTS

> **三方規格 SSOT（對接第一步）**：實作任何 Driver / 代收代付 / 回調**之前**，先把廠商三方文檔整理成 AI 與後人都能讀的 markdown，落檔到 APIs repo `docs/integrations/payments/<ProviderName>/api.md`，對接全程以它為實作規格依據（建檔見「實作前檢查清單」起手、定位與命名見「金流商整合文檔」）。本手冊提到的「金流商 API 文件 / 廠商文檔」一律指該 provider 的 `api.md`。**既有 provider 改動時**：若同目錄已存在 `integration.md`，讀／建 `api.md` 後**仍須優先參閱 `integration.md`**（已驗證落差與踩坑，比廠商原文更貼近真實行為，詳見「金流商整合文檔」讀取規範）。

---

## ✅ 實作前檢查清單

**起手（步驟 0）：先落檔三方規格 `api.md`**。寫任何代碼前，先把廠商提供的三方文檔（PDF／網頁／信件規格）整理成 markdown，建立 APIs repo `docs/integrations/payments/<ProviderName>/api.md`（目錄名對齊「金流商整合文檔」命名規則，API＋簽名＋錯誤碼於單檔內分節）。下方各項與 §1–§13 的 Driver / 代收代付 / 回調 / 簽名實作**一律以此 `api.md` 為規格依據**。

**復用檢查（動手寫任何邏輯前）**：先確認是否已有同事做過類似功能，避免重工：(a) 搜既有代碼：`app/Services`、`app/Helpers`、`Common` 與同類 Provider 實作；(b) `git log --grep='<功能關鍵字>'` 查歷史 commit；(c) 有 gitlab MCP 時搜相關 MR。查到類似實作先比對：能復用就直接復用；需要整段複製時，改為抽共用方法（Trait / AbstractMethod / Service），禁止複製貼上改值。

在生成代碼前，請確認：
1. **銀行名稱唯一性**：確認 `banks` 表的名稱（大小寫/空格/簡寫）是否與新配置重複，避免腳本新增多餘銀行。
2. **支付組別配置**：確認 `agent_group_id=0` 的銀行 Code 是否正確，防止腳本引用錯誤代碼。
3. **目錄結構**：是否已建立 `Providers/{Name}` 目錄？
4. **繼承關係**：是否繼承 `AbstractMethod`？
5. **Trait 可見性**：Trait 屬性是否為 `protected`？
6. **錯誤處理**：是否使用 `ApiException` (非 Generic Exception)？
7. **金額運算**：是否避免了浮點數運算，僅使用 `Common::num*`？
8. **嚴格類型**：每個新增或修改的 PHP 檔（含測試檔）首行都已宣告 `declare(strict_types=1);` 嗎？沒有例外。
9. **Http Fake**：測試代碼是否包含 `Http::fake()`？
10. **回調來源驗證**：代收與代付回調是否至少有驗簽或 IP 白名單其中一道？（兩者皆無不可上線，規則見 §6.0）
10a. **回調要回頭查單的**：查單在交易外嗎？用的是建單時存下的三方單號嗎？查單失敗時回的 HTTP 狀態會讓三方重送嗎？（三問任一否＝會鎖單等 HTTP、可被換單結算、或訂單卡住沒人知道，規則見 §6.2）
11. **限額、匯率、手續費**：資料檔的代收與代付 channel 都宣告了 `min_amount`／`max_amount` 嗎？匯率與 `cost` 設了嗎？`cost` 填的是比率（2.3% ＝ `0.023`）嗎？代付的匯率設在 `banks.rate` 了嗎？（四問任一否＝還沒完成，規則見 §14.1）
12. **回補對接文檔**：`api.md` 已於步驟 0 建立。接入完成後：(a) 若 `api.md` 整理時有**漏抄／抄錯廠商原文**，回頭校正（仍只還原廠商說法，不寫入我方詮釋）；(b) 若發現**廠商原文與實測不符**或踩到坑，依 §金流商整合文檔 litmus test 補 `integration.md`，**不可**改寫進 `api.md`（維持 api.md=廠商原文、integration.md=已驗證落差 的邊界）；(c) 錯誤碼表的說明分「意思」和「成因」：官方文件沒列、但回傳原文意思清楚的碼，意思照原文翻譯並註明「官方文件未載、依回傳原文」，只有成因標待確認並附追蹤單號（範例：APIs `docs/integrations/payments/SurePay/api.md` 的 `-110`、`-201`）。這條只管文件文字；要把某個碼列進代付明確拒絕白名單，仍照「7. Withdrawal.php」節的白名單依據。

---

## 新接金流商執行序（總覽）

新接金流商時依此順序執行，各步驟的規範細節以本手冊對應章節為準：

1. **落檔三方規格 `api.md`** — 依「實作前檢查清單」步驟 0；同目錄已有 `integration.md` 時優先參閱
2. **確認銀行名稱唯一性** — 依 §2「銀行名稱規範」查 DB；**接新幣別要先建 `Data/banks/{幣別}.php`**（§2.2.1，沒有這檔代付銀行會全數被跳過、代付必死）
3. **建立 CashFlow 配置** — `app/Console/Commands/CashFlow/Data/cash_flows.php` ＋ `providers/{ProviderName}.php`。**代收與代付的 channel 都要宣告 `min_amount`／`max_amount`**，沒宣告就不會有限額（§14.1）
4. **同步配置至 DB** — `php artisan cash-flow:sync --ids=X --dry-run` → 確認後正式執行（§14）
5. **建立 6 個整合檔案** — 先跑 `php artisan make:payment {ProviderName}` 生成骨架（§14），再依 §3~§8 範本補齊 `app/Services/Payment/Providers/{ProviderName}/`
6. **註冊 Driver** — 於 `config/payment.php` 加入 `'PROVIDER_NAME' => Providers\{ProviderName}\Driver::class`（§1）
7. **建立單元測試** — 依 [`reference/testing.md`](reference/testing.md) 覆蓋代收 / 代收回調 / 代付 / 代付回調四支測試
8. **配置回調來源白名單** — 三方沒有簽名機制時必做：向廠商要出口 IP，填進 Olympus「金流商管理」頁的 `cash_flows.whitelist`（§6.0）。**部署前要先配好**，fail-closed 會把沒配 IP 的回調全部擋掉
9. **設限額、匯率、手續費並逐項驗過** — 這三個值命令不會替你補，漏了不報錯但行為明顯不對（§14.1）
10. **回補對接文檔** — 依「實作前檢查清單」第 12 項校正 `api.md`；有踩坑依 litmus test 補 `integration.md`
11. **在 Dev 建金流商戶並驗收**：代碼 commit 後接著做，不等使用者另外指定：在 Dev 正式跑 `cash-flow:sync`，照「15.1 Dev 環境一律用 `frank`（agent 4249）」節用 `agent:add-agent-cash-flow --agent=4249` 建金流商戶（兩個命令都先 `--dry-run`）。建完讀回 `cash-flow:channel-status <cash_flow_id>` 與 `agent_cash_flows.info` 的鍵，確認商戶與通道都建出來，再照 APIs `docs/金流接入流程/3-測試與提交驗收.md` 驗收。驗收場景跟接入模式不合時（例：永久地址模式沒有跳轉收銀台），改寫成對應的驗收步驟，仍在 Dev 做。只在「憑證填進 Dev 金流商戶」這一步停下交給使用者（憑證不經 AI 手），同時列出金流商端要先做的設定：回調網域白名單、IP 白名單、測試商戶有沒有開該幣與鏈。
12. **寫上線 runbook**：runbook 的上線段落在第 11 步做完才寫，只寫這家金流商專屬的上線步驟（通用流程在 `docs/金流接入流程/4-上線.md`）；「Dev 測試網驗收環境」那一節可以在第 11 步時先寫。第 11 步沒做完時，回報裡寫明「第三章驗收未做」，不列成下一步選項。
13. **提測前把對接規格回寫到需求單**：交給 QA 測試之前做，不等使用者另外指定。這則留言是給 QA 看的：支付方式、代付銀行清單這些內容，開需求單時還不知道，要接完才確定；不寫回需求單，QA 不知道要測哪些支付方式、哪些銀行，只能來問 RD 或翻代碼。
    - **用留言，不改描述**：在需求單發一則留言（`create_work_item_note`），不動描述。描述是 PM 寫的需求，對接規格是 RD 接完的結果，分開放才看得出哪些是接完才確定的；描述也常已經超過字數上限。留言內容先給使用者看，使用者回 `y` 才送出。
    - **內容**：照下面的範例寫三塊，依序是代收代付對照表、代收方式表、代付銀行表。範例是 MÖBIUSPAY（THB）的實際內容，代付銀行只列前 3 家，實際要列全部：

      ```markdown
      ## 對接規格
      | 項目 | 代收 | 代付 |
      |---|---|---|
      | 幣別 | THB | THB |
      | 支付方式 | 銀行轉帳、QR Code | 銀行轉帳 |
      | 單筆限額 | 300 ～ 45,000 | 300 ～ 45,000 |
      | 手續費 | 2.3% | 0.5% |

      代收方式 2 種：

      | # | 三方代碼 | MÖBIUSPAY 名稱 | 我方顯示名 |
      |---|---|---|---|
      | 1 | 100201 | Thailand Bank Transfer | MobiusPay - Bank Transfer |
      | 2 | 100202 | Thailand Qrcode | MobiusPay - QR Code |

      代付銀行 17 家（已確認可對應我方標準銀行清單）：

      | # | 三方代碼 | 我方顯示名 |
      |---|---|---|
      | 1 | BAAC | (THB) - BAAC |
      | 2 | BAY | (THB) - Bank of Ayudhya |
      | 3 | BBL | (THB) - Bangkok Bank |
      ```
    - **值從哪裡抄**：
      - 支付方式、限額、代收方式、代付銀行：從 `providers/{ProviderName}.php` 抄，因為 `cash-flow:sync` 照這份建 DB
      - 三方名稱：從 `api.md` 原文抄
      - 手續費：從工單或 `agent_cash_flows.cost` 抄
      - 不憑記憶寫。
    - **跟描述對不上時寫出來**：例如描述寫的限額或手續費跟實際配置不同，在留言裡標出哪一項不同、以哪個為準，不去改描述。

---

## 加密貨幣金流商：先判斷是哪一型

本手冊正文寫的是「法幣型」金流商：會員輸入金額建單、跳轉付款頁、金流商回調對單。**加密貨幣型**（區塊鏈）金流商的代收根本不同：會員拿一個固定收款地址自己轉幣，錢到了金流商用回調通知，我方回查確認後才建單入帳；代付則是把幣打到會員的鏈上地址。

符合下列任一項就是加密貨幣型，執行序照上方各步走，但代收（步驟 5 的 `Deposit` / `DepositCallback`）與配置（步驟 3）改依 [`reference/crypto.md`](reference/crypto.md)：

- 廠商自稱加密貨幣支付閘道，支援 USDT、BTC、ETH 這類幣。
- 會員存款方式是「轉幣到一個地址」，不是「跳轉到付款頁」。
- 代付的收款目標是會員的鏈上錢包地址，不是銀行帳號。
- 廠商 API 有 `coinId`／`chain`／`address`／`memo` 這類參數。

`reference/crypto.md` 涵蓋：幣與鏈的關係、幣別跟著金流商戶而鏈別跟著支付通道的配置慣例（含會審否決的做法）、永久地址模式、回調判定矩陣與冪等、回調實測形狀與對帳補送、代付差異、測試網 QA、上線前提醒。代付例外分類與代付回調三態仍以本手冊「Withdrawal.php」「WithdrawalCallback.php」兩節為準。

---

## 1. 目錄結構

```
app/Services/Payment/Providers/{ProviderName}/
├── Driver.php                 # 驅動程式（組合各子類別）
├── Deposit.php                # 代收
├── DepositCallback.php        # 代收回調
├── Withdrawal.php             # 代付
├── WithdrawalCallback.php     # 代付回調
└── {ProviderName}Trait.php    # 金流商專屬共用邏輯（HTTP 請求、簽名）
```

完成後須在 `config/payment.php` 註冊：

```php
'PROVIDER_NAME' => Providers\ProviderName\Driver::class,
```

---

## 2. 銀行名稱規範 (重要)

> **核心原則**：不同金流商的相同銀行，**必須使用完全相同的名稱**。

### 2.1 為什麼這很重要？
1. **防止前台重複**：若配置不一致（例：`Maybank` vs `May Bank`），前台會顯示兩家銀行。
2. **確保代付成功**：名稱不一致會導致新增不同 `code` 的銀行，造成無法匹配到正確渠道。

### 2.2 配置前的標準動作

**新增銀行配置前，請務必先查詢資料庫：**

```sql
SELECT name, code FROM banks WHERE currency_id = 1 AND name LIKE '%May%';
```

| 資料庫現有值 | 金流商 `api.md` 值 | 你的配置寫法 | 結果 |
|-------------|-------------|------------|------|
| `Maybank` | `May Bank` | `Maybank` | ✅ 正確 (自動 mapping) |
| `Maybank` | `May Bank` | `May Bank` | ❌ 錯誤 (會建立重複銀行) |

### 2.2.1 🔴 接新幣別時：先確認 `Data/banks/{幣別}.php` 存在

`cash-flow:sync` 的銀行比對不是直接查 `banks` 表，是拿資料檔的銀行名稱去對
`app/Console/Commands/CashFlow/Data/banks/{幣別}.php` 的標準銀行條目
（`BankStandardMappingService` 以 `glob()` 載入該目錄，檔案不存在就**沒有任何候選**）。

**該幣別沒有分檔時，代付銀行會全部 `no_match` 被跳過**，而且命令不會失敗：
統計表的 `Bank` 與 `CashFlowBank` 都是 0、另外印一張「信心度不足的銀行（已跳過）」表。
後果是 `cash_flow_banks` 空 ⇒ `convertPayoutBankCode()` 回空字串 ⇒ **每一筆代付在送出前就失敗**。
這個症狀從 provider 代碼完全看不出來。

所以接新幣別的第一步是**先建標準銀行檔**（格式與規則見該目錄 `README.md`，
結構不變式由 `tests/Unit/Data/StandardBankRegistryTest.php` 把關），再跑 sync。
`standard_name` 要沿用該幣別既有的命名慣例（先 `grep -r '(<幣別>) - ' providers/` 看別家怎麼寫），
`aliases` 另外收平台既有銀行列的原名，讓 `BankMismatchAuditor` 能把舊列認回同一家、不變成重複銀行。

> 本檔只收「目前真的有金流商配置在用」的銀行，不要預先補齊該國全部銀行：
> 它會由 `SyncStandardBanksSeeder` 隨部署 upsert 進**每個站台**的 `standard_banks`，
> 多一列就是多一列跨站要維護的資料。

### 2.3 如何設定 Mapping

```php
// app/Console/Commands/CashFlow/Data/providers/EpicPay.php
'banks' => [
    // ✅ 使用系統名稱，配合對應的三方 code
    ['name' => 'Maybank', 'code' => 'MB_001'],
],
```

---

## 3. Driver.php

```php
class Driver extends AbstractDriver
{
    protected Deposit $deposit;
    protected DepositCallback $depositCallback;
    protected Withdrawal $withdrawal;
    protected WithdrawalCallback $withdrawalCallback;

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $driverName = $this->getDriverName();
        Log::debug($driverName . ' Driver initialized', [
            'agentCashFlow' => $agentCashFlow->toArray(),
        ]);

        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
```

### 3.1 可選能力介面（`App\Services\Payment\Contracts\`）

四支**可選**介面，Driver 實作哪支就取得哪項能力；不實作等於該能力不存在，呼叫端一律先 `instanceof` 再用。各支的實作守則以自己的 docblock 為權威出處。

| 介面 | 什麼時候實作 | 關鍵守則 |
|---|---|---|
| `SupportsDepositQuery` | 三方有「以我方訂單查代收單」API | 唯讀，禁止在實作內改訂單；查詢失敗拋 `ApiException`，不回半套資料 |
| `SupportsPayoutQuery` | 三方有「以我方交易號查代付單」API | 停在 21 的訂單會由 `withdrawal:alert-stuck-submitted` 先自動查證：`FOUND_ACCEPTED` **且附三方單號**才補號轉 22；`NOT_FOUND`／`UNKNOWN` 不自動改單。**無法確定一律回 `UNKNOWN`**，禁止把「查詢失敗」推成 `NOT_FOUND`（誤判查無＝自動開重複出款窗口）|
| `SupportsPaymentChannelQuery` | 三方有「查商戶通道／限額／支援銀行」API | 唯讀；禁止跨 process 快取（回應含 PII），同請求內可 memoize；raw 進 DTO 前先 `Common::maskCredentials()`；缺憑證欄位直接拋，不送必失敗的請求 |
| `SupportsPermanentDepositAddress` | 加密貨幣永久收款地址型（見 [`reference/crypto.md`](reference/crypto.md)）| 取址對金流商必須冪等；禁止在實作內讀寫 `member_crypto_wallets`（那是呼叫端的事）|

> 代收查單與代付查單**刻意分成兩支、不可合併**：後者是綁定單次代付嘗試的安全決策模型，誤判的代價是重複出款；前者只是一般排查用的狀態查詢。

---

## 4. {ProviderName}Trait.php

共用的 HTTP 請求與簽名邏輯。Trait 屬性與方法**必須使用 `protected`**，禁止 `private`。

> 🚫 `sendRequest` 僅做 HTTP 層檢查——**業務碼驗證禁止放這裡拋例外**（會把代付的同步明確拒絕
> 誤判成結果未知而卡在 21，詳見 §7 代付例外分類規則）。代收需要驗碼便利包裝時，
> 以含驗碼的 `sendRequest` 包僅 HTTP 層的 `sendRequestRaw`，代付走 raw。

> 🔴 **不可逆扣款類金流商（Telco PIN、預付卡兌換這類一次性憑證）的 `sendRequest` 禁用 `Http::retry()`**，第一次失敗就拋例外。理由：三方逾時或回 5xx 時憑證可能已被扣掉，重送同一張會被三方判「已使用」，玩家的錢沒了、我方訂單卻顯示失敗。
> - 送出前先對同一張憑證上並發鎖（`Cache::lock`，key 用憑證的 sha256，不放原文），擋同一張同時送兩次。
> - 失敗分兩類：HTTP 4xx（408 除外）是確定拒絕，訂單標失敗並釋放鎖；逾時、連線錯誤、408、5xx 是結果未知，訂單維持 `CREATED`、不釋放鎖、發告警，等三方回調或人工確認。
> - 結果未知的訂單 `third_party_order_no` 會是空的，回調要能用我方單號或憑證反查回這張訂單（範本：TngPin `DepositCallback.php` 的三層匹配：先比我方單號，再比三方交易單號，最後才在「三方單號為空、狀態 `CREATED`、同金流商戶」的範圍內比憑證，多筆候選不自動歸屬）。
> - 三方有冪等交易單號的一般代收代付不在此限。
> - 範本：APIs `app/Services/Payment/Providers/TngPin/TngPinTrait.php` 的 `sendRequest()`，與同目錄 `Deposit.php` 送 PIN 的 catch 段。

```php
trait ProviderNameTrait
{
    protected string $apiUrlField = 'API_URL';

    protected function sendRequest(string $endpoint, array $params, array $headers = []): array
    {
        $url = rtrim($this->info[$this->apiUrlField], '/') . $endpoint;

        try {
            return Http::logAction($this->getDriverName() . '_sendRequest')
                ->asForm()
                ->withHeaders($headers)
                ->post($url, $params)
                ->throw()
                ->json();
        } catch (Exception $e) {
            throw new ApiException(
                sprintf('%s request failed: %s', $this->getDriverName(), $e->getMessage()),
                HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
                $e
            );
        }
    }

    // MD5：直接串接
    protected function generateSign(array $params, string $secret): string
    {
        return md5($params['merchant'] . $params['order_no'] . $params['amount'] . $secret);
    }

    // MD5：欄位排序後 key=value 串接
    protected function generateSignBySort(array $params, string $secret): string
    {
        ksort($params);
        $signStr = collect($params)->map(fn($v, $k) => "{$k}={$v}")->implode('&');
        return md5($signStr . '&key=' . $secret);
    }

    // HMAC-SHA256
    protected function generateHmacSign(array $params, string $secret): string
    {
        ksort($params);
        $signStr = collect($params)->map(fn($v, $k) => "{$k}={$v}")->implode('&');
        return hash_hmac('sha256', $signStr, $secret);
    }

    // Base64 編碼
    protected function generateBase64Sign(array $params, string $secret): string
    {
        return base64_encode(implode('', array_values($params)) . $secret);
    }
}
```

---

## 5. Deposit.php

```php
class Deposit extends AbstractMethod
{
    use ProviderNameTrait;

    protected array $info;

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;
        $params = $this->buildDepositParams($request);
        $result = $this->sendRequest('/api/deposit', $params);

        if (!isset($result['code']) || $result['code'] !== 0) {
            throw new ApiException(
                sprintf('%s deposit error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error')
            );
        }

        return DepositResponseData::from([
            'type'            => DepositResponseType::URL_REDIRECT,
            'url'             => $result['data']['payUrl'],
            'orderNo'         => $order->order_no,
            'providerOrderNo' => $result['data']['orderId'] ?? '',
        ]);
    }

    private function buildDepositParams(DepositRequestData $request): array
    {
        $order = $request->order;

        $params = [
            'merchant_code' => $this->info['MERCHANT_CODE'],
            'order_no'      => $order->order_no,
            'amount'        => $order->amount,
            'notify_url'    => $request->callbackUrl,
            'return_url'    => $request->returnUrl,
        ];

        $params['sign'] = $this->generateSign($params, $this->info['SECRET_KEY']);

        return $params;
    }
}
```

### DepositResponseType 枚舉值

```php
DepositResponseType::URL_REDIRECT  // type = 1：跳轉至付款頁面
DepositResponseType::QR_CODE       // type = 2：QR Code 掃碼
DepositResponseType::FORM          // type = 3：表單提交
```

---

## 6. DepositCallback.php

### 6.0 回調來源驗證：驗簽與 IP 白名單至少一道（🚫 紅線，代收代付共用）

回調是外部入口，**沒有來源驗證就等於開放任何人偽造一筆「已付款」**。`DepositNotifyController` 只驗 `agent_cash_flow` 存在與 `cash_flow.type`，`routes/external/api.php` 的 `DetectIp` 只寫 Context 不做驗證（code 層沒有 IP 防線，見 CLAUDE.md），所以這道只能由 provider 自己站住。

| 三方給什麼 | 我方必須做什麼 |
|---|---|
| 有簽名機制 | 驗簽：`hash_equals()` 常數時間比對；被簽的是**收到的原始內容**，不可重新序列化 |
| 沒有簽名機制（或我方商戶沒開啟） | **IP 白名單**，用 `CallbackSourceGuardTrait::isCallbackSourceAllowed()` |
| 兩者皆無 | 🚫 **不可上線**。兩道皆空就是「任何人都能送一筆上分」 |

**白名單守衛的硬規則**（權威出處是 `app/Services/Payment/Traits/CallbackSourceGuardTrait.php` 的 docblock）：

- 🔴 **fail-closed：白名單為空＝一律拒絕**，不是「關掉檢查」。🚫 **禁止改用 `Common::verifyIPWhitelist()`**——那支在非 production 一律放行、白名單為空也放行，方向相反（遊戲商那套是 fail-open，不能搬過來）。代價是上線前沒配 IP 會整家回調被擋，這是刻意取捨：擋掉真回調會立刻有人喊，放過假回調沒有人會發現。
- 🔴 **白名單的單一來源是 `cash_flows.whitelist`**（Olympus「金流商管理」頁維護），不吃 `agent_cash_flows.info`：廠商出口 IP 是**支付商層級**屬性，存進 per 商戶設定會複製 N 份各自漂移。
- 🔴 **來源 IP 一律 `Common::getIp()`**（走可信代理鏈），🚫 禁止 `$request->ip()`——後者直接信任 `X-Forwarded-For`，加個標頭就繞過。
- 🔴 **擋在 `$request->validate()` 之前**：欄位格式都還沒看，就不該讓不明來源的輸入影響任何狀態。
- 🔴 **守衛只判斷與記錄，不拋例外、不決定回應**：中止與回應交由呼叫端以該三方約定的失敗文本回覆（`buildDepositFailResponse()` / `buildWithdrawalFailResponse()`），避免回一個三方看不懂的錯誤觸發無限重試。與 `CredentialGuardTrait` 同一套分工。
- 🔴 **Callback 類別的 constructor 必須寫 `$this->agentCashFlow = $agentCashFlow;`**，只呼叫 `setInfo()` 讀不到渠道就讀不到白名單，fail-closed 會把整家擋掉（守衛會把這種情形記成 `provider_not_wired` 的 error 與「IP 沒配」區分）。
- **代付回調同樣要驗**，風險比代收更高：停在「代付請求中／處理中」的提款單收到偽造的失敗回調會先退點，三方之後若真的出款就是付了兩次。
- 🔴 **新站建表資料（`app/Console/Commands/CashFlow/Data/cash_flows.php`）要帶上 `whitelist`**：fail-closed 之下，新站照建表資料建起來卻沒有 IP，等於該金流商一上線回調就全被擋，而症狀是「玩家付了沒上分」，不會有人聯想到建表資料。沒有回調端點的那一列（例如 `W_AP8`，AP8Pay 沒有代付）刻意留空並加註原因，免得下一個人以為漏填。

```php
public function handle(Request $request): DepositCallbackResponseData
{
    // 防線 1：來源 IP（fail-closed），擋在 validate 之前
    $ip = Common::getIp();
    if (!$this->isCallbackSourceAllowed($ip, 'deposit callback')) {
        return $this->buildDepositFailResponse(
            errorMessage: 'Callback rejected',
            logMessage: 'deposit callback rejected: source IP not in whitelist',
            logContext: ['ip' => $ip],
        );
    }

    // 防線 2：憑證完整性（CredentialGuardTrait），再來才是 validate 與驗簽
    // ...
}
```

> 🔎 **接手既有 provider 前先查現況**：哪一家靠驗簽、哪一家沒有、簽名有沒有含金額、憑證未配置時會不會擋，已逐家盤點在 APIs repo `docs/integrations/payments/代收回調盤點.md`；重複出現的缺陷型態與正確做法在 `docs/金流接入流程/附錄-常見缺陷類型.md`；各家待修項目與處理狀態在風險發現資料檔 `docs/reports/data/payment-risk-findings.json`（維護與產報告走 `report-payment-audit` skill）。新接一家前先讀這三份，不要重新踩一次。
>
> ⚠️ `CallbackSourceGuardTrait` 與 `cash_flows.whitelist` 用在 Ap8Pay（`target/tickets#4446`）與 EasyPay、MaxPay、TngPin（`#4449`）。**站點要先配好該金流商的 IP，才能部署或啟用這家金流商**：白名單是 fail-closed（沒配就擋），沒配 IP 的站點，該金流商回調會全數被擋。
>
> 🔴 **TngPin 的 `info['CallbackAllowIps']` 已廢止**：那是 #4449 之前的白名單來源，而且語義相反（未設定就放行）。讀取端已移除，殘留值由 `fix:drop-tngpin-callback-allow-ips` 清掉；**只能在已上該版的站點跑**，先跑等於把舊版的防線拿掉。

### 6.1 找單限定渠道：回調只能處理自己渠道的訂單（🚫 紅線，代收代付共用）

白名單擋的是「誰來打」，這道擋的是「打得到誰」。少了它，A 金流商的回調可以拿 B 金流商的訂單號來送，而訂單號是順序性的、猜得到。

- 🔴 **找單一律走共用方法**：代收 `DepositCallbackTrait::findAndLockDepositOrder()`、代付 `WithdrawalCallbackTrait::findAndLockPayoutRecord()` / `findAndLockPayoutRecordByThirdPartyNo()`。它們內部比對 `agent_cash_flow_id`，不符回 `null` 並記 error ＋ `BotNotify::channel('warn')`。
- 🚫 **禁止在 provider 自己寫一份查詢**。自己寫的查詢會繞過共用方法裡的渠道比對，這道防線對那一家就整個失效，而且從外面看不出來。找單一律呼叫上面的共用方法。唯一例外是 §6.2 查單前的不鎖讀：它不寫入，而且一樣要過共用的渠道比對（`orderBelongsToCallbackChannel()`／`payoutRecordInCallbackChannel()`），真正寫入前仍以共用的 `findAndLock*()` 重取。
- **前提先以線上日誌驗過再改**：導入前比對過正式站「回調帶的訂單號」與「該訂單實際的渠道」——代收 302 筆／12 渠道、代付 276 筆／6 渠道，**0 筆不符**。沒有這個數字就不知道收緊會不會誤殺既有流量。
- **代付掛的是 payout record 不是提款單**：一張提款單可能有多次代付請求，渠道是記在 record 上。

```php
class DepositCallback extends AbstractMethod
{
    use ProviderNameTrait;

    protected array $info;
    protected string $notifySuccessText = 'SUCCESS';
    protected string $notifyFailText = 'FAIL';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $input = $request->validate([
            'order_no' => 'required|string',
            'status'   => 'required|string',
            'amount'   => 'required|numeric',
            'sign'     => 'required|string',
        ]);

        // 1. 驗證簽名
        $expectedSign = $this->generateSign($input, $this->info['SECRET_KEY']);
        if (!hash_equals($expectedSign, (string) $input['sign'])) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 2. 查詢並鎖定訂單
            $order = $this->findAndLockDepositOrder($input['order_no']);

            // 3. 冪等性檢查
            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();
                return $this->handleDepositOrderException($e, $order);
            }

            // 4. 根據回調狀態更新訂單
            if ($input['status'] === '1') {
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['order_no'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['order_no'],
                    callbackData: $input
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();
            BotNotify::channel('error')->send($e->getMessage());
            throw $e;
        }
    }
}
```

### 6.2 回調要回頭查單時：查單在交易外、用存下的單號查（🚫 紅線，代收代付共用）

上面的範本是**有簽名**的寫法：驗簽通過就信回調內容，交易裡不打任何三方 API。三方回調**沒有簽名**（或簽名沒涵蓋狀態與金額），只能回頭查單才知道結果時，**不能**把查單塞進範本的 `DB::beginTransaction()` 裡，要改照下面的流程寫。實例：B3Wallet 照範本在 `lockForUpdate` 之後打 `queryBill`，三方一慢，訂單的行鎖就跟著被拉長；同一份程式又拿回調自帶的單號去查，可以把同商戶另一張已付款的單配到別張訂單上結算（APIs `target/tickets#4300` hotfix）。

**流程（範本：APIs `Ap8Pay/DepositCallback.php`、`B3Wallet/DepositCallback.php`、`B3Wallet/WithdrawalCallback.php`）**

1. **不鎖讀單**，擋掉不需要查單的情況：查無訂單、渠道不符、已處理。不鎖讀也要過渠道比對（代收 `orderBelongsToCallbackChannel()`、代付 `payoutRecordInCallbackChannel()`），規則同 §6.1。
2. **決定拿哪個單號去查**：
   - 一律用**建單時存下的三方單號**（`orders.third_party_order_no`／`withdrawal_payout_records.third_party_transaction_no`）。回調帶的單號跟存下的不一樣，就拒絕並發告警，不查單。
   - 只有建單響應遺失、沒存到三方單號時，才退回用回調帶的。這時歸屬要另外證明：查回來的我方單號必須是本單；查單響應不帶我方單號的（例：B3 代付），至少要確認這個三方單號沒有被別筆紀錄佔用。這條路徑不能整個關掉：代付停在 21 的單靠回調定案（#4137）。
3. **交易外查單**。查單失敗不等於三方判這筆失敗，訂單維持原狀。
4. **驗查回來的結果**：
   - 查回的三方單號必須正是送去查的那筆。單號會接進 URL 路徑，不比對的話，帶 `../` 之類的值可以把查詢導到別支 API。
   - 查回「已付款」卻沒帶金額時不定案，**不退回用回調的金額**。
5. **開短交易**：用共用的 `findAndLock*()` 重取並鎖定 → 重新檢查狀態（查單期間可能已被另一筆回調定案）→ 寫入 → commit。
6. `order_request_logs` 照 §12.1 在 `handle()` 外層 try/finally 寫，每個 return 前都要把 `$this->result` 設好（查單失敗 `QUERY_FAILED`、單號不符 `QUERY_ANOTHER_ORDER`、沒帶金額 `NO_AMOUNT`、還在處理中 `NOT_FINALIZED`）。

**🔴 要三方重送時，回應的 HTTP 狀態要對**：先讀 `api.md` 的回調重送規則。三方只看 HTTP 狀態決定要不要重送（例：B3「商戶只需回 2xx」）時，`fail` 文本配 HTTP 200 等於叫它不要再送，訂單就停在原狀沒人知道。

| 情況 | 要不要三方重送 | 回什麼 |
|---|---|---|
| 查單失敗、查單結果不完整（沒帶金額） | 要 | 非 2xx（`httpStatusCode` 設 500）＋ `BotNotify::channel('error')` |
| 單號不符、查無訂單、渠道不符、狀態不收回調 | 不要（偽造或錯配，重送也一樣） | 2xx ＋ fail 文本，發 warn 告警 |
| 已處理、還在處理中 | 不要 | 2xx ＋ success 文本 |

三方是看 body 文本決定重送的，照它的規則回 fail 文本即可。

**測試至少要有**：查單當下的 `DB::transactionLevel()` 等於進 `handle()` 之前的層數（舊寫法這條必紅）、回調單號跟存下的不符時 `Http::assertNothingSent()` 而且訂單不動、查單打不通時回應的 `httpStatusCode` 不是 2xx。

---

## 7. Withdrawal.php

> **🚫 代付例外分類規則（重要）**：
> `processPayout` 的 `performRequest` closure 拋出的**任何例外**都會被視為「結果未知」→
> payout 記錄 `REQUEST_FAILED(-1)`、`isIndeterminate=true` → 訂單**停留在 `PAYMENT_SUBMITTED (21)` 鎖定重送並告警**
> （`WithdrawalPayoutService`）。停留 21 的單有**四個出口**（都不需 provider 端配合，寫 provider 時不必為它們留鉤子）：
> ①遲到回調自動定案 21→24/29（`checkWithdrawalOrderStatus` 對 21 放行）②排程 `withdrawal:alert-stuck-submitted`
> 對停留超過門檻的單先經三方查單自動查證，未定案者告警 ③`withdrawal:resolve-payout` 人工定案（解鎖重送轉 29 或定案成功轉 24）
> ④後台「填寫三方單號」轉 22 交回調定案、「修改為代付失敗」轉 29。
>
> ⚠️ 狀態 23（原「三方查證中」`THIRD_PARTY_VERIFYING`）已退役、禁止重用，`WithdrawalOrderStatusEnum` 已移除這個 case；新代碼不寫入 23，回調也不放行。舊系統後台仍有 23 的處理端點，歷史訂單的 `change_logs` 也會出現 23，讀舊資料時要知道它是退役狀態。provider 註解或文件若寫「結果未知鎖 23」屬過期內容，狀態去向以本段與「WithdrawalCallback.php」節為準。因此：
>
> - **判準一句話：只有「結構完整、（有簽名機制則）驗簽通過、且業務碼明確表示未受理」才 -2
>   （payout 記錄 `THIRD_PARTY_REJECTED`，訂單轉 29 可重新發起）；其餘一律拋 → -1、訂單停 21**。兩個方向的誤分類都是資損級錯誤：
>   - 明確拒絕誤判結果未知 → 訂單卡 21，營運噪音＋人工解鎖（Kira 2026-08-04 實例：三方回「每日限額」卻卡住）；
>   - 結果未知誤判 -2 → 轉 29 可重新發起＝**重複出款**（更嚴重）。
> - **明確拒絕一律白名單制**：以類別常數（如 `private const REJECT_CODES = [...]`）列舉
>   「可證明未建單」的業務碼，**只有白名單內的碼**原樣回傳交 `interpretResult` 判 -2、訂單轉 29 可重新發起
>   （限額、餘額不足、帳號無效、認證失敗等）；白名單依據＝三方文檔，文檔未載但有實測證據的碼
>   （如 Kira `code:0`＝業務拒絕）先補記 `docs/integrations/payments/{Name}/api.md` 再入白名單。
>   `sendRequest`（HTTP 層便利包裝）內禁止驗業務碼拋例外，分流一律在 performRequest closure。
> - **下列情境必須在 performRequest 拋例外（→ -1、訂單停 21），不得流進 interpretResult 判 -2**：
>   - 2xx 但 body 解不出業務碼（`json()` 回 null、WAF/CDN 挑戰頁）——結構缺失≠拒絕；
>   - **業務碼／status 型別不符**——數值碼過 `is_numeric`、布林 status 過 `is_bool` 閘門，
>     缺鍵／`null`／型別不符＝結構缺失（只用 `array_key_exists` 不夠：`code: null` 會穿過去被判 -2 可重送）；
>   - **語意歧義碼**——文檔把「訂單號重複／訂單已存在」歸在某錯誤碼下（如 Kira/AntzPay `400`
>     「請求參數錯誤（如訂單號重複）」）：重複＝該號訂單已建於三方，整個碼不能證明未建單，不得入白名單；
>   - 業務碼＝成功／已受理之後的任何解析失敗（解密例外、缺交易單號欄位）——三方已說收單；
>   - 「系統內部錯誤」類業務碼（如 PayMate resCode 999）與**所有白名單外未知碼**——
>     發生在請求抵達後，不能排除已建單；
>   - 拒絕信封驗簽失敗——來源不可信，不得據此還原重送。
> - **前置步驟（login / access token 取得）放 `processPayout` 之前**：失敗＝請求根本未發出，
>   直接 `return $this->createFailureResponse(...)`（不建 payout 記錄、不停 21）。
>   參考 `Kira` / `AntzPay`（token 前置）、`BillionPay`（login 前置）。
> - 代收若需要「業務碼失敗即拋」的便利包裝，以 `sendRequest`（含驗碼）包 `sendRequestRaw`
>   （僅 HTTP 層）雙層設計，代付走 raw；參考 `Kira` / `MaxPay` / `PayMate`。
> - 模範寫法：`B3Wallet`（只 5xx 拋、4xx 結構化錯誤交 interpretResult）。

```php
class Withdrawal extends AbstractMethod
{
    use ProviderNameTrait;

    /**
     * 業務碼「明確拒絕」白名單：僅列「可證未建單」的碼（依三方文檔；
     * 文檔未載的實測碼先補記 api.md 再入列）。歧義碼（含訂單號重複語意）與未知碼不入列。
     */
    private const REJECT_CODES = [1001, 1002];

    protected array $info;
    protected string $orderPrefix = 'Provider';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        // 前置步驟（如需 login / token）放這裡：失敗＝請求未發出 → 明確失敗，不建 payout 記錄、不停 21
        // try { $this->getAccessToken(); } catch (Throwable $e) { return $this->createFailureResponse($e->getMessage()); }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        // 代付編排器：建 pending 記錄 → 送請求 → 解讀回應 → 更新終態
        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            // 例外分類：連線/逾時/HTTP 非 2xx（sendRequestRaw 拋）、結構缺失、
            // 白名單外業務碼 → 拋 → -1、訂單停 21 鎖定重送並告警；只有成功碼與白名單拒絕碼原樣帶回
            performRequest: function () use ($params): array {
                $result = $this->sendRequestRaw('/api/payout', $params);

                // 型別閘門：解不出數值業務碼（缺鍵 / null / 非數值）＝結構缺失 → 結果未知
                if (!is_numeric($result['code'] ?? null)) {
                    throw new ApiException($this->getDriverName() . ' unparseable 2xx response');
                }

                // 非成功碼：白名單內＝可證未建單的明確拒絕 → 交 interpretResult 判 -2；
                // 歧義碼（如「訂單號重複」）與未知碼 → 結果未知，訂單停 21
                if ((int) $result['code'] !== 0 && !in_array((int) $result['code'], self::REJECT_CODES, true)) {
                    throw new ApiException(sprintf(
                        '%s ambiguous business code %s: %s',
                        $this->getDriverName(),
                        $result['code'],
                        $result['message'] ?? 'Unknown error'
                    ));
                }

                return $result;
            },
            // 到得了這裡的只有兩種形狀：白名單拒絕碼（→ -2、訂單轉 29 可重新發起），或成功碼（→ 受理）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['code'] ?? null) === 0,
                thirdPartyOrderNo: $result['order_no'] ?? '',
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );
    }

    private function buildPayoutParams(WithdrawalOrder $order, string $transactionNo): array
    {
        $params = [
            'merchant_code' => $this->info['MERCHANT_CODE'],
            'order_no'      => $transactionNo,
            'bank_code'     => $this->convertPayoutBankCode($order->bank_code),
            'amount'        => sprintf('%.2f', $order->amount),
            'account_name'  => $order->account_name,
            'account_no'    => $order->account_no,
            'notify_url'    => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        $params['sign'] = $this->generateSign($params, $this->info['SECRET_KEY']);

        return $params;
    }
}
```

---

## 8. WithdrawalCallback.php

> 🚫 **來源驗證（驗簽或 IP 白名單至少一道）代付同樣適用，規則見 §6.0**——代付的風險更高：停在 21／22 的提款單收到偽造的失敗回調會先退點，三方之後若真的出款就是付了兩次。**找單限定渠道見 §6.1**：代付找 payout record 一律走 `findAndLockPayoutRecord()` / `findAndLockPayoutRecordByThirdPartyNo()`，禁止自己寫查詢。**回調沒有簽名、要回頭查單的，照 §6.2 寫**：下方範本是有簽名的寫法，查單不可放進它的交易裡。

> **狀態規則（重要，做 WithdrawalCallback / 冪等修復必讀）**：
>
> **回調放行集合只有 `{21, 22}`**（`WithdrawalPayoutTrait::checkWithdrawalOrderStatus()`）：
> - `PAYMENT_SUBMITTED (21)` 放行——結果未明或 pod 中斷停留的單，回調正是定案依據
> - `PAYMENT_PROCESSING (22)` 放行
> - 23 已退役：不放行、禁止重用（歷史紀錄仍可能出現）
> - **`THIRD_PARTY_FAILED (29)` 會被拒絕**：發 BotNotify 告警後 `throw WithdrawalOrderException(..., 400)`。理由是 29 允許重新發起，補送回調若放行會與重送的新請求交錯；若補送的是「成功」回調，代表訂單可能遭誤標 29，此時重新發起就是**重複出款**，必須人工立即核對三方實際出款結果
> - 其餘狀態（0/1/2/3/4/9/24）一律拒絕
>
> **29 不是終態**：發起白名單 `allowedStatuses = [APPROVED (3), THIRD_PARTY_FAILED (29)]`，後台可從 29 重新發起。真終態只有 24/2/9。
>
> **同步明確拒絕一律轉 29**（`WithdrawalPayoutService`）：provider `interpretResult` 白名單判定為確定失敗時轉 29，不還原發起前狀態。結果未知（逾時／連線例外）則停留 21 擋重送並告警，交人工定案。
>
> 實作 / 修改 WithdrawalCallback 時，**不要**在自家 Provider 額外加上任何 order status 判斷邏輯——放行與拒絕統一由框架的 `checkWithdrawalOrderStatus()` 決定。這是 callback/冪等正確性 gate，不只新接金流商會遇到——修既有回調時最常踩。
>
> ⚠️ 任何文件寫「回調放行 29 以支援 29 → 24 補發」都是過期內容，照做會重新打開重複出款的漏洞。

> **三態慣例（新接／修改 WithdrawalCallback 一律沿用）**：
> 回調收到的三方狀態碼只有三種去向，由共用 resolver 判定（`App\Enums\Payment\WithdrawalCallbackOutcome`＋`WithdrawalCallbackTrait::resolveWithdrawalOutcome(status, SUCCESS_CODES, FAILURE_CODES)`，命中成功碼集→SUCCESS、命中失敗碼集→FAILURE、**其餘一律 INDETERMINATE**）：
>
> | outcome | 行為 |
> |---|---|
> | `SUCCESS`（明確成功） | 訂單→24；**只有此路徑**驗金額（`validateCallbackAmount`） |
> | `FAILURE`（明確失敗） | 訂單→29 |
> | `INDETERMINATE`（處理中／未知碼） | **不寫任何 DB**（不 `updatePayoutRecord`、不動 status，維持當前狀態：請求中 21 或在途 22），只 log ＋ ack；靠後續明確回調結單 |
>
> 三種 outcome **都回三方成功響應（ack）**——INDETERMINATE 也 ack，避免三方立即重送風暴。
> `updateWithdrawalOrderStatus(order, outcome, payoutRecord)` 收 `WithdrawalCallbackOutcome`（傳入 INDETERMINATE 時 no-op 防呆），但正常樣板已在 resolver 後早退，只會傳入 SUCCESS／FAILURE。有自家 `StatusCodeEnum` 的 provider 由 enum 直接映射 outcome，不必走字串碼集。
>
> **碼集鐵則（資損級，凌駕方便與對稱美感）**：`SUCCESS_CODES`／`FAILURE_CODES` 每個碼都必須指得到 `api.md` 依據，禁止用 enum case 名或直覺推測。兩種合規寫法依文檔能否列舉失敗碼二選一，**都必須保證失敗路徑可達（禁止「永不失敗」配置）**：真失敗若無路徑標 29，失敗單會永久停在 21 或 22，需人工撈：
>
> - **(A) 雙白名單可列舉**：文檔列得出明確失敗碼 → `resolveWithdrawalOutcome` 且 `FAILURE_CODES` **非空**，未知碼落 INDETERMINATE。
> - **(B) doc-literal「其他＝失敗」**：文檔只寫「成功碼＋處理中碼＋其他＝失敗」 → inline `match`：成功碼→SUCCESS、**已列舉的處理中／非終態碼→INDETERMINATE、`default`→FAILURE**。安全責任轉移到「pending 白名單必須完整」——凡是之後可能轉成功的非終態碼漏列一個，就會把處理中誤判失敗。
>
> **為什麼「誤判失敗」是最危險的方向**：代付三方常在每次狀態變更都回調（processing→success 多次）。「處理中」被誤標 29 → 客服視為該家失敗、改派另一家重新出款 → 原三方稍後回成功 → **兩家都打款（真實資損）**。反向誤判只是訂單停在當前狀態（21 或 22），等後續明確回調或人工定案，不資損。因此**單一不確定碼**的判斷天平倒向 INDETERMINATE，但**整體配置**不得因此變成永不失敗。

```php
class WithdrawalCallback extends AbstractMethod
{
    use ProviderNameTrait;

    /**
     * 三態碼集：每個碼都要有 api.md 依據（三方文檔明確定義），禁止推測。
     * 文檔未載但有實測證據的碼，先補記 api.md 再入列。
     */
    private const SUCCESS_CODES = ['1'];
    private const FAILURE_CODES = ['2', '3'];

    protected array $info;
    protected string $notifySuccessText = 'SUCCESS';
    protected string $notifyFailText = 'FAIL';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): WithdrawalCallbackResponseData
    {
        $input = $request->validate([
            'order_no' => 'required|string',
            'status'   => 'required|string',
            'amount'   => 'required|numeric',
            'sign'     => 'required|string',
        ]);

        $expectedSign = $this->generateSign($input, $this->info['SECRET_KEY']);
        if (!hash_equals($expectedSign, (string) $input['sign'])) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            $payoutRecord = $this->findAndLockPayoutRecord($input['order_no']);

            if ($this->isPayoutRecordProcessed($payoutRecord)) {
                DB::rollBack();
                return WithdrawalCallbackResponseData::from([
                    'response'  => $this->notifySuccessText,
                    'isSuccess' => true,
                ]);
            }

            $withdrawalOrder = $this->findAndLockWithdrawalOrderByPayoutRecord($payoutRecord);

            // 三態判定：命中成功碼集→SUCCESS、命中失敗碼集→FAILURE、其餘一律 INDETERMINATE
            $outcome = $this->resolveWithdrawalOutcome($input['status'], self::SUCCESS_CODES, self::FAILURE_CODES);

            // INDETERMINATE：非最終回調 → 不寫任何 DB、維持當前狀態（21 或 22），只 log ＋ ack
            if ($outcome === WithdrawalCallbackOutcome::INDETERMINATE) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' withdrawal callback indeterminate, keep PAYMENT_PROCESSING(22)', [
                    'order_id' => $withdrawalOrder->id,
                    'status'   => $input['status'],
                ]);

                return $this->buildWithdrawalSuccessResponse($withdrawalOrder);
            }

            // 到此 outcome 必為明確終態；只有成功驗金額（失敗時金額可能不準確）
            if ($outcome === WithdrawalCallbackOutcome::SUCCESS) {
                $this->validateCallbackAmount($withdrawalOrder, $input['amount'], $payoutRecord);
            }

            $this->updatePayoutRecord(
                payoutRecord: $payoutRecord,
                callbackData: $input,
                isSuccess: $outcome === WithdrawalCallbackOutcome::SUCCESS,
            );

            $this->updateWithdrawalOrderStatus($withdrawalOrder, $outcome, $payoutRecord);

            DB::commit();

            $this->logCallbackProcessed($payoutRecord, $withdrawalOrder, $outcome === WithdrawalCallbackOutcome::SUCCESS);

            return $this->buildWithdrawalSuccessResponse($withdrawalOrder);
        } catch (Exception $e) {
            DB::rollBack();
            BotNotify::channel('error')->send($e->getMessage());
            throw $e;
        }
    }
}
```

---

## 9. 銀行代碼映射

`convertPayoutBankCode()` 由 `WithdrawalPayoutTrait` 提供，把提款單的 `bank_code`（我方銀行代碼）轉成三方要的銀行代碼。**代付銀行碼只能經這個方法取得，禁止 provider 自行讀 `info.available_banks` 或自建對照表**：第二份對照表必然與共用層 drift，症狀是「某家銀行送出去被三方退」而查不出原因。

```php
$thirdPartyBankCode = $this->convertPayoutBankCode($order->bank_code);
```

方法內部有兩條查碼路徑，由 provider 是否在灰度名單決定，provider 代碼不需要分辨：

| 路徑 | 條件 | 資料來源 |
|---|---|---|
| 查表（新） | `getDriverName()` 在 `config('payment.payout_bank_lookup_providers')` 名單內（環境變數 `PAYOUT_BANK_LOOKUP_PROVIDERS`，逗號分隔） | `cash_flow_banks.provider_bank_code`，以（`cash_flow_id`、`currency_id`、`bank_code`）查、只認 `status=1` 的列；同鍵多列取 id 最小者 |
| JSON（舊） | 不在名單內（名單為空＝全部走這條） | `agentCashFlow->info['available_banks']`，結構 `[['BankCode_ELG' => 'BCA', 'BankCode_Third' => '014', 'BankName' => 'Bank Central Asia']]` |

寫 provider 時要知道的三件事：

1. **查不到要先回明確失敗，不能把空碼送給三方**：`convertPayoutBankCode()` 查不到回空字串；`buildPayoutParams` 拿到空值就 `throw`，讓 `handle()` 走 `createFailureResponse()`（請求未發出，不建 payout 記錄）。
2. **銀行碼比對是逐字相同才算命中**，且只認啟用中的對應；不要自己寫寬鬆比對（`==` 會把 `'007'` 與 `'7'` 當相同）。
3. **`withdrawal:check-payout-channels` 永遠只讀 JSON 路徑**：provider 已灰度到查表時，check 說可用不等於出款會成功，兩條路徑等值要用 `cash-flow:verify-payout-bank-parity` 驗。灰度一家一家開，開之前先跑這支驗證。

資料檔 `providers/{ProviderName}.php` 的 `withdrawal.channels[].banks[].code` 填三方銀行碼，`cash-flow:sync` 會同時寫進 `cash_flow_banks.provider_bank_code`；`agent:add-agent-cash-flow` 再從它產生 `info.available_banks`。兩條路徑的資料都來自同一份資料檔，所以正常情況下等值。三方銀行碼是 `"0"` 這種值也會照寫，不會被當成空值略過。

---

## 10. 標準響應物件

```php
// 代收發起
DepositResponseData::from([
    'type'            => DepositResponseType::URL_REDIRECT,
    'url'             => 'https://example.invalid/xxx',
    'orderNo'         => $order->order_no,
    'providerOrderNo' => $result['orderId'] ?? '',
]);

// 代付發起 - 成功
WithdrawalResponseData::from([
    'isSuccess'       => true,
    'errorMessage'    => '',
    'providerOrderNo' => $result['providerOrderNo'] ?? '',
]);

// 代付發起 - 失敗
$this->createFailureResponse('Error message here');

// 代收回調 - 成功
$this->buildDepositSuccessResponse($order);

// 代收回調 - 失敗（可帶 log）
$this->buildDepositFailResponse(errorMessage: 'Signature failed', order: $order);

// 代付回調 - 成功
$this->buildWithdrawalSuccessResponse($order);

// 代付回調 - 失敗
$this->buildWithdrawalFailResponse(logMessage: 'order not found');
```

---

## 11. Trait 使用指南

所有金流商子類別繼承 `AbstractMethod`，自動獲得以下 Traits（無需手動 `use`）：

| Trait | 提供的方法 |
|-------|-----------|
| `DriverTrait` | `getDriverName()` |
| `CallbackSourceGuardTrait` | `isCallbackSourceAllowed()`, `callbackAllowIps()` —— 回調來源 IP 白名單（fail-closed，見 §6.0）|
| `CredentialGuardTrait` | `findMissingCredentials()`, `findMissingCredentialValues()` —— 憑證未配置時擋下回調，不靜默放行 |
| `RecordsThirdPartyExchangeTrait` | `logExchange()`, `providerFailureOf()`, `httpStatusOf()`, `closeDanglingTransaction()` —— 三方往返紀錄（見 §12.1）；另提供 `$this->result`／`$this->loggedOrder` 兩個狀態欄位 |
| `DepositCallbackTrait` | `findAndLockDepositOrder()`, `checkDepositOrderStatus()`, `updateDepositOrderSuccess()`, `updateDepositOrderFailed()`, `handleDepositOrderException()`, `buildDepositSuccessResponse()`, `buildDepositFailResponse()` |
| `WithdrawalCallbackTrait` | `findAndLockPayoutRecord()`, `findAndLockPayoutRecordByThirdPartyNo()`, `isPayoutRecordProcessed()`, `findAndLockWithdrawalOrderByPayoutRecord()`, `resolveWithdrawalOutcome()`, `updatePayoutRecord()`, `updateWithdrawalOrderStatus()`, `validateCallbackAmount()`, `logCallbackProcessed()`, `buildWithdrawalSuccessResponse()`, `buildWithdrawalFailResponse()`, `handleWithdrawalOrderException()` |
| `WithdrawalPayoutTrait` | `getWithdrawalOrder()`, `validatePayoutOrder()`, `checkWithdrawalOrderStatus()`, `createPayoutRecord()`, `convertPayoutBankCode()`, `handlePayoutRequestException()`, `handlePayoutResponseFailure()`, `handlePayoutSuccess()`, `createFailureResponse()` |

> 各 method 的職責摘要詳見 APIs 專案的 `docs/金流接入流程/附錄-技術參考.md`（相對專案根目錄；apis-toolkit 僅裝於 APIs repo）。

---

## 12. 錯誤處理模式

```php
// ApiException 格式：{DriverName} {operation} error: {message}
throw new ApiException(
    sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage)
);

// Log 格式（禁止使用 Str::limit($e->getTraceAsString(), 500)）
Log::error($this->getDriverName() . ' deposit failed', [
    'order_no'           => $order->order_no,
    'agent_cash_flow_id' => $this->agentCashFlow->id,
    'amount'             => $order->amount,
    'error'              => $e->getMessage(),
    'trace'              => Common::formatTrace($e),
]);

// Bot 通知
BotNotify::channel('error')->send($e->getMessage());
```

**BotNotify 的兩個坑**：

- **頻道名只能用 `config/bot-notify.php` 的鍵**：`debug`／`info`／`error`／`warn`／`large_amount`。寫一個不存在的名字（例如 `warning`）會解析成空 chat id，`BotNotifyService::telegram()` 直接 return——**不拋例外、呼叫端完全不知道通知沒發出去**。字面頻道名有 `BotNotifyChannelKeysTest` 掃 `app/` 擋著，但它只驗鍵存在、不驗 env chat id 有沒有配。
- **`send()` 在交易內會延到交易結束才送**：commit 或 rollBack 都會送出，rollBack 那次自動在訊息開頭標註「【交易已回滾，以下內容描述的寫入未生效】」。排查型告警一律用它，不必自己判斷「rollBack 後這則還成不成立」。**只有「事情沒發生就不該通知」的（大額通知那類）才用 `sendOnCommit()`**，且新增呼叫點必須先登記進 `BotNotifySendOnCommitCallSitesTest`。舊的 `sendAfterTransaction()` 已移除、功能併回 `send()`。

---

### 12.1 三方往返紀錄（`order_request_logs`）

把「我方送了什麼、三方回了什麼」整包原文落 DB，供後台查單與事後爭議還原。provider 端一律呼叫 `RecordsThirdPartyExchangeTrait::logExchange()`（底層是 `App\Services\Payment\OrderRequestLogger::record()`），六種 action 由 `OrderRequestActionEnum` 列舉（`deposit_create` / `deposit_query` / `deposit_callback` / `withdrawal_create` / `withdrawal_query` / `withdrawal_callback`）。

**為什麼非記不可**：被防線擋下的回調不會動訂單，訂單停在原狀，跟「玩家根本沒付」長得一樣；對不到訂單的回調連訂單都沒有，更沒地方留。而我方回給三方的 body 一律是該家約定的失敗文本，從 `response` 完全分不出是哪一道擋的——那正是 `result` 這欄存在的理由。

- 🔴 **寫入失敗不得影響主流程**：logger 內部整段 try/catch，寫不進去只記一行 warning。日誌掛掉不能讓建單或回調跟著失敗。
- 🔴 **在交易外呼叫**：寫在交易內的話，回調被拒絕或 rollback 會連紀錄一起沒掉——剛好丟掉最想留的那筆。
- 🔴 **掛載對象：代收掛 `orders`、代付掛 `withdrawal_payout_records`**（不是 `withdrawal_orders`）。一張提款單可能有多次代付請求（重試、換渠道），一次往返對應的是其中一次請求；代付的冪等主體也是 record 本身。
- 🔴 **查詢一律先比 `orderable_type` 再比識別碼**：兩張表的 id 空間重疊（實測 TPower：`orders` 2030 萬、`withdrawal_payout_records` 186 萬），同家金流商的代收代付三方單號也常共用序號空間，只比 id 會互撈。型別以 morph alias 入庫（`deposit_order` / `payout_record`），不存 FQCN。
- 🔴 **`orderable_type` 一律有值**：來源 IP 在查單之前就被擋、對不到訂單時由 `OrderRequestActionEnum::orderableType()` 推導；留空那筆會被「先比型別」的查詢濾掉，而被擋的回調正是最該被看到的。
- **本表不遮罩**（原文進 DB，理由寫在 migration 註解），也**禁止寫入遊戲商往返**——量體差好幾個數量級，要記另開表。
- **保留 90 天**，`clean:order-request-logs` 每日 05:40 分批刪。90 天的依據：GCP log 只留 30 天，30 天之外本表是唯一的往返證據。
- **欄位有截斷上限**（request 4096／response 16384／headers 2048 bytes，實測最大值分別是 248／104／466）。這不只是防寫爆欄位：回調路由對外公開又沒有認證，**被擋下的請求同樣會寫一筆**，上限放寬等於開一條未認證的寫入放大管道。

**接線方式（回調）**：`handle()` 只做 try/finally 的記錄外殼，業務邏輯整段移到 `private function process()`：

```php
public function handle(Request $request): DepositCallbackResponseData
{
    $baseline = DB::transactionLevel();   // 測試環境本來就開著一層，收尾只能收自己多開的
    $response = null;

    try {
        return $response = $this->process($request);
    } finally {
        $this->closeDanglingTransaction($baseline);
        $this->logExchange(
            action: OrderRequestActionEnum::DEPOSIT_CALLBACK,
            url: $request->fullUrl(),
            request: $request->all(),          // 不是 validate() 的結果
            response: $response?->response,
            result: $this->result,
            requestHeaders: $request->headers->all(),
            order: $this->loggedOrder,
            orderNo: (string) $request->input('order_no'),
            sourceIp: Common::getIp(),
        );
    }
}
```

- 🔴 **傳 `$request->all()` 不是 `validate()` 的結果**：後者只留規則裡列的欄位，三方多送的（簽名、額外狀態碼）會被丟掉，而那往往正是事後要查的。
- 🔴 **每個判定點都要覆寫 `$this->result`**：查無訂單、已處理過、三方未終態各有各的值，沒覆寫就沿用預設的 `exception`，查起來跟程式真的炸掉分不出來——而被防線擋下的攻擊正是靠這欄辨識。查到訂單後也要立刻指派 `$this->loggedOrder`，中途拋例外時 finally 仍用得到。
- 🔴 **寫紀錄前先 `closeDanglingTransaction($baseline)`**：`process()` 外層通常只 `catch (Exception)`，接不住 `Error`（TypeError、記憶體不足）。那條路徑交易還開著就進 finally，紀錄會跟著 rollback 不見，剛好丟掉最需要留的那筆。

**判定結果（`OrderRequestResultEnum`）只有兩種形狀：`ok` 與 `failed:<原因>`**。

- 🚫 **禁止在呼叫端塞自由文字**。三方自己的錯誤類型（各家不同、無法枚舉）走 `providerFailure()` 統一加前綴，取 `type`／`code` 這類欄位，**不要取 message**——message 是會變的自由文字，而且原文已經在 `response` 裡。
- 🔴 **非 2xx 不等於連不上**：好幾家（EasyPay、Ap8Pay）用 HTTP 400 承載業務錯誤，body 帶著 `SERVICE_UNDER_MAINTENANCE`、`MERCHANT_INSUFFICIENT_BALANCE` 這類碼。`sendRequest()` 的 `->throw()` 會先把它變成例外，catch 若直接記 `EXCEPTION`，「三方明確拒絕」與「連線斷掉」在表上就長得一模一樣。用 `providerFailureOf($e, 'code', 'error.type')`（支援 dot path，依序取第一個非空字串；取不到就退回 `EXCEPTION`，所以多傳幾個鍵是安全的）。

> 🔴 **非 2xx 的代價不只是紀錄分類**：EasyPay 的代付就因為 `->throw()` 讓 `interpretResult()` 沒機會執行，三方「商戶餘額不足」被當成「結果未知」，payout record 記 -1、**訂單卡在 21 等人工定案，錢根本沒出去**。若 provider 用非 2xx 送業務錯誤，`sendRequest()` 不要掛 `->throw()`，改以「這包回應解不解得出業務信封」判定：解得出（含 400 的拒絕信封）交 `interpretResult()`；解不出（502 的 HTML、空 body、JSON 但沒有狀態欄位）才拋出維持「結果未知」。**代付這條判錯方向就是二次出款**——判成拒絕會讓營運重新發起，而那筆請求可能已經送達三方。

**後台顯示**：`agent/api/deposit/request-logs`、`agent/api/withdrawal/request-logs`（經 `OrderRequestLogDisplayService`）。

- 🔴 **只回純量**（時間／類型／判定結果／三方單號／HTTP 狀態／來源 IP），**往返原文一個欄位都不回**——本表存未遮罩內容含明文商戶憑證，靠白名單過濾等於留一個要靠人維護的控制面。要看內容的是開發，直接查表。
- **代付依「每一次代付請求」分組**回傳，不是整張提款單一份：併起來就看不出哪一次請求配哪幾筆往返。
- 比對用三個鍵任一命中（`orderable_id`／我方單號／三方單號），**空值一律不比對**——被擋下的回調沒有 `orderable_id`，只剩單號對得回去；拿空值去比會把所有空的列撈進別人的訂單底下。

> 🚫 **不要為了保護這張表而對回調路由掛 throttle**。#4449 一度掛過「白名單內不限流、其餘每分鐘 10 次」，上線前以 GCP log 量測才發現前提是錯的：只有少數幾家配了白名單，其餘家的真回調全落在白名單外，而金流商普遍用一兩個出口 IP 服務整個站——TPower 單一 IP 單分鐘尖峰 112 筆、Yes2Win 120 筆，近八成的分鐘超過 10 筆。掛上去就是把真回調擋掉九成。**門檻類的值一定要先量過真實分布再訂**，別把「沒配白名單」當成「可疑來源」。

> 對應工單：`target/tickets#4446`／`#4449`。現有呼叫端：Ap8Pay、EasyPay、MaxPay、TngPin 的代收建單與回調，以及**全部 30 家走共用 `WithdrawalPayoutTrait::processPayout()` 的代付請求**（新接一家走共用流程就自動有）。

---

## 13. info 欄位命名慣例

`agentCashFlow->info` 鍵名**命名依據 `api.md`**：取 provider 文檔的參數名稱轉 `UPPER_SNAKE_CASE`，而非照本手冊範例自訂固定名。`API_URL` 為框架固定例外（廠商不以此為參數名，但所有 provider 必加，供 `sendRequest` 發送 HTTP）；其餘如下表的 `MERCHANT_CODE` / `SECRET_KEY` 僅為範例，實際鍵名以該 provider `api.md` 為準。

| 鍵名 | 說明 |
|------|------|
| `API_URL` | 三方 API 根路徑 |
| `MERCHANT_CODE` | 商戶代號 |
| `SECRET_KEY` | 密鑰 |
| `available_banks` | 代付銀行清單（含 `BankCode_ELG`、`BankCode_Third`、`BankName`） |
| `DRIVER_LABEL` | 選用。**薄繼承殼共用別家實作時**覆寫告警／日誌顯示名（`DriverTrait::driverLabelOverride`），否則 SpeedPay 這種沿用 PayMate 實作的商戶會以被繼承那家的名字發告警 |

---

## 14. Artisan 命令速查

```bash
# 查詢金流商 ID
php artisan cash-flow:list [--type=1|3] [--detail]

# 同步金流商配置至資料庫
php artisan cash-flow:sync --ids=100 [--dry-run]

# 建立代理金流映射（代收可選拆分或單筆模式；代付固定單筆）
php artisan agent:add-agent-cash-flow [--agent=ID] [--cashflow=ID] [--currency=ID] [--mode=split|single] [--migrate-single] [--dry-run]

# 生成金流商代碼骨架
php artisan make:payment ProviderName

# 自動同步至所有代理
php artisan cash-flow:auto-sync --cashflow=79 --currency=VND [--production] [--dry-run]

# 檢查代付渠道
php artisan withdrawal:check-payout-channels {memberId}
```

### `agent:add-agent-cash-flow` 的代收拆分判斷

`agent:add-agent-cash-flow` 是建立代理金流配置的唯一入口。代收（`cash_flows.type = 1`）可能需要把同一金流商的 deposit channels 拆成多筆 AgentCashFlow，原因是不同代收通道可能有不同手續費，但目前 `cost` 儲存在 `agent_cash_flows.cost`，一筆 AgentCashFlow 只能承載一個 cost。拆分模式因此採「一個 channel 一筆 AgentCashFlow」，讓每個 channel 使用自己的 cost；單筆模式則是一筆 AgentCashFlow 掛多個 channels，共用單一 cost。

命令依下列順序決定代收建立形式：

1. Provider config 沒有目標幣別的 `deposit.channels` 藍圖：無從拆分，固定使用單筆模式。
2. 有指定 `--mode=split|single`：依指定模式執行。
3. 互動模式未指定 `--mode`：命令會詢問操作者；若任一目標幣別 channel **存在 `cost` key**，預設建議拆分，否則預設建議單筆。
4. 非互動模式未指定 `--mode`：依同一規則自動判斷；任一 channel 存在 `cost` key 就拆分，全部沒有就使用單筆。`cost=0` 仍算已配置，判斷依據是 key 是否存在，不是數值是否大於 0。

`agent_cash_flows.cost` 除了顯示，也是訂單 `payment_cost`（支付商手續費，成本）的來源：代收於建單時、代付於派款成功時，由共用層依當下配置的 cost 寫入 `orders.payment_cost`／`withdrawal_orders.payment_cost`（`AgentCashFlow::paymentCostFor()`，2026-09 新增）。provider 代碼不用碰這個欄位；但配置 cost 錯了，成本彙總報表就跟著錯，拆分時每個 channel 的 cost 要對到三方報價。

拆分模式若偵測到既有單筆 AgentCashFlow，必須由操作者確認就地遷移；非互動執行須明確帶 `--migrate-single`。代付（`cash_flows.type = 3`）不拆分，固定使用單筆模式，傳入 `--mode` 也會被忽略。

> **Docker 執行格式**：`docker exec -it php-fpm8 bash -c "cd /var/www/APIs && <command>"`

### 🔴 14.1 配置完還要自己設的三類值：限額、匯率、手續費

`cash-flow:sync` 與 `agent:add-agent-cash-flow` **不會**替你補這三個值。三個都有「漏了也不報錯、
但行為明顯不對」的特性，所以列成獨立檢查項，配置完逐項驗過才算完成。

| 值 | 落在哪 | 怎麼進去 | 漏了會怎樣 |
|---|---|---|---|
| **代收限額** | `agent_cash_flow_order_types.min_amount/max_amount` | 資料檔 `deposit.channels[].min_amount/max_amount`；channel 沒宣告時退回 `cash_flows.info[幣別]`，兩邊都沒有就是 `null` | 前台不限額，玩家可以送任意金額，超過三方限額才被退單 |
| **代付限額** | `agent_cash_flows.info.min_amount/max_amount`（`WithdrawalPayoutService` 選渠道時過濾） | 資料檔 `withdrawal.channels[].min_amount/max_amount`，由 `agent:add-agent-cash-flow` 帶入 | 同上，而且代付是真的把錢打出去 |
| **匯率** | **代收**看 `agent_cash_flows.rate`；**代付**看 `banks.rate` | 兩個都要手動設，命令不會帶 | 預設 `1`：玩家存 300 泰銖拿到 300 點（`point = amount × rate`，`OrderService`） |
| **手續費** | `agent_cash_flows.cost` | 手動設 | `payment_cost` 記 0，成本彙總報表失真 |

**三件最容易填錯的事**：

1. 🔴 **`cost` 存的是比率不是百分比數字**：`payment_cost = point × cost`
   （`AgentCashFlow::paymentCostFor()`）。2.3% 要填 **`0.023`**；填 `2.3` 後台會顯示成 **230%**，
   成本放大一百倍。既有資料的樣子是 `0.0250`（＝2.5%），照這個口徑填。
2. 🔴 **代付的匯率不在 `agent_cash_flows.rate`，在銀行列的 `rate`**（`WithdrawalService`
   用 `$bank->rate`），而且**基礎層（`agent_group_id = 0`）與代理分組兩邊都要設**
   （既有 IDR 銀行就是兩邊都有實際匯率）。只設 `agent_cash_flows.rate` 的話代收對了、代付還是用 1。
3. **`cash-flow:sync` 預覽印的 `min: N/A, max: N/A` 是命令本身的顯示問題**
   （它從 `buildParentInfo()` 的陣列取值，那裡根本沒有這兩個鍵），限額其實有正常寫入。
   要驗限額一律直接查表，不要看預覽那行。

```bash
# 配置完的驗收查詢（代收看通道關聯、代付看商戶 info）
php artisan tinker --execute='
  foreach (DB::table("agent_cash_flows")->whereIn("cash_flow_id",[<代收 id>,<代付 id>])->get(["id","cash_flow_id","rate","cost","info"]) as $r) {
    $i = json_decode((string) $r->info, true) ?: [];
    echo $r->id, " cf=", $r->cash_flow_id, " rate=", $r->rate, " cost=", $r->cost,
         " info.min=", $i["min_amount"] ?? "-", " info.max=", $i["max_amount"] ?? "-", PHP_EOL;
  }
  foreach (DB::table("agent_cash_flow_order_types")->where("cash_flow_id",<代收 id>)->get(["id","order_type_id","min_amount","max_amount"]) as $t) {
    echo "通道 ", $t->order_type_id, " min=", $t->min_amount, " max=", $t->max_amount, PHP_EOL;
  }'
```

---

## 15. 正式環境代理配置流程（測試代理先行）

> 適用 **env=production 白標**（非 `Dev` / `mysql` 測試環境）。代理 id 一律查白標 registry `plugins/apis-toolkit/registry/whitelabels.metadata.yaml`：正式代理＝該白標 `rootAgentId`、測試代理＝`testRootAgentId`（測試代理直接掛在正式代理下，`agents.parent == rootAgentId`）。

正式環境配置代收/代付金流商戶時，`agent:add-agent-cash-flow` **須執行兩次**，分別掛到測試代理與正式代理；代收建立形式依命令互動確認，或以 `--mode=split|single` 明確指定：

```bash
# ① 測試代理（registry testRootAgentId）——供測試人員驗收
php artisan agent:add-agent-cash-flow --agent={testRootAgentId} --cashflow={id} --currency={id}

# ② 正式代理（registry rootAgentId）——建成停用並隱藏
php artisan agent:add-agent-cash-flow --agent={rootAgentId} --cashflow={id} --currency={id} --status=0
```

上線把關：**測試代理建成啟用（預設 `--status=1`）供測試人員驗收；正式代理建成停用（`--status=0`）**。等測試人員在測試代理驗收金流配置無誤，才手動開啟正式代理的配置對外。

### 🔴 15.1 Dev 環境一律用 `frank`（agent 4249）

`Dev` / `mysql` 在 registry 的 `rootAgentId` 是 `null`，沒有「總代」可以照抄。
**Dev 固定用 `frank`（`agent_id = 4249`，`cash_flow_group_id = 3`「支付預設分組」）**，
不要自己另外挑代理：

```bash
php artisan agent:add-agent-cash-flow --agent=4249 --cashflow={id} --currency={id}
```

代理選錯**不會報錯，但配出來的金流商沒有人看得到**。`agent_cash_flows` 不掛 agent id，
是靠 `agent_group_id` 對應到代理的 `agents.cash_flow_group_id`；用了金流組別不同的代理
（例如某站的測試代理），商戶就落在那個站的組別，驗收的人在後台與前台都看不到，
很容易被誤判成程式壞了。

配置後確認落點，`agent_group_id` 要是 `3`：

```sql
SELECT id, cash_flow_id, agent_group_id FROM agent_cash_flows WHERE id IN (...);
```

**配錯了要改兩個地方，只改第一個不夠**：

```sql
-- ① 商戶列
UPDATE agent_cash_flows SET agent_group_id = <對的組別> WHERE id IN (...);
-- ② 🔴 agent:add-agent-cash-flow 連帶建的「分組銀行列」也跟著錯的組別走
UPDATE banks SET agent_group_id = <對的組別>
 WHERE name LIKE '(<幣別>) - %' AND agent_group_id = <錯的組別>;
```

漏掉第二步的症狀是**提款頁一家銀行都不出現**，但代收正常——因為代付讀的是分組銀行列。
`agent_cash_flow_order_types` 掛的是 `agent_cash_flow_id`，不受組別影響、不必重建。

**上下架開關在哪一層，代收和代付不同**。新舊代理後台的 status 與 `backend_display`（後台列表是否顯示）都依金流類型讀這兩層，會員存款清單與建單也要求支付通道關聯的 status 為 1：

| 金流類型 | 開關所在的表 | 另一層 |
|---|---|---|
| 代收（`cash_flows.type = 1`） | `agent_cash_flow_order_types`（支付通道關聯） | `agent_cash_flows`（金流商戶）固定為 1，不拿來上下架 |
| 代付（`cash_flows.type = 3`） | `agent_cash_flows`（金流商戶） | 沒有支付通道關聯 |

- `agent:add-agent-cash-flow --status` 依上表寫在開關那一層：`--status=0` 會同時把同一層的 `backend_display` 設成 0，代收金流商戶照樣建成 1。沿用營運既有列的路徑（單筆轉拆分的遷移後續跑、依通道 key 命中既有列）保留原本的 status 與 `backend_display`，不被 `--status` 蓋掉。
- 🔴 **關閉時先隱藏、再停用**，開啟時兩者一起設回 1。用 `cash-flow:channel-status` 的 `--backend-display` 與 `--set-status`，兩個參數可以同一次帶：

```bash
# 代收：動支付通道關聯
php artisan cash-flow:channel-status {cashflow id} --acfot={支付通道關聯 id} --backend-display=0 --set-status=0
# 代付：動金流商戶
php artisan cash-flow:channel-status {cashflow id} --acf={金流商戶 id} --backend-display=0 --set-status=0
```

- 🚫 **不要用 `--acf --set-status=0` 關代收**：金流商戶停用會連動關掉旗下所有支付通道關聯（`AgentCashFlowObserver`），之後重新啟用金流商戶時，那些支付通道關聯不會自動開回，要逐筆找出來再開；`backend_display` 也沒有跟著隱藏，後台列表照樣看得到。

---

## 單元測試規範

金流商測試覆蓋情境、`Http::fake` 標準格式、命名與斷言規則統一收在：

- [`reference/testing.md`](reference/testing.md) — 代收 / 代收回調 / 代付 / 代付回調必測情境清單與強制要求

新增金流商時，於 `tests/Unit/Services/Payment/Providers/{ProviderName}/` 下依此規範補齊四支測試檔。

---

## 金流商整合文檔

接金流商時，於 **APIs repo** 建立整合文檔目錄（貼著廠商規格與程式碼維護，不在本 plugin）：

```
docs/integrations/payments/<ProviderName>/
├── api.md             # 廠商原文規格（廠商說什麼；API＋簽名＋錯誤碼，接入必建）
├── integration.md     # 一手踩坑心得（接入才知道、文件＋程式碼查不到的；有踩坑才建）
├── runbook.md         # 上線步驟與閘門（有上線作業才建，上線後留作維運依據）
└── open-questions.md  # 待廠商回覆的題目與狀態表（接入期間的暫時性文件，全部結案後可刪）
```

### 命名規則（重要）

`<ProviderName>` **必須對齊程式碼識別名**，即 `app/Services/Payment/Providers/{ProviderName}/` 的 Provider 目錄名（亦即 `config/payment.php` 註冊的 Driver class 名），**不可用廠商對外品牌名**。品牌名只寫進文檔標題與內文，不進目錄名——命名分歧會導致同一商找不到對應實作。

| 廠商品牌 | Provider 目錄名（＝整合文檔目錄名） | 結果 |
|---|---|---|
| VtPay | `VtPay` | ✅ 品牌與 Provider 同名 |
| Bigpayz (GlobePay365) | `GlobePay` | ✅ 對齊 Driver，非品牌 `Bigpayz` |

> ⚠️ `payments/BigPayz/` 與 `payments/GlobePay/` 是不同目錄：`BigPayz/` 僅放廠商規格 `api.md`、**非實作 provider**；實作 provider 是 `GlobePay`，`integration.md` 寫在 `GlobePay/`。

### 定位界線（一刀切）

| | `api.md` | `integration.md` |
|---|---|---|
| 回答 | **廠商說什麼** | **我方接入時踩到什麼** |
| 內容 | 廠商原文規格（API＋簽名＋錯誤碼），照搬不詮釋 | 文件與實作的已驗證落差、三方文件自身錯誤/矛盾、踩過的坑、實測確認的陷阱、維運注意 |
| source of truth | 廠商文件 | 接入經驗（無法從文件或程式碼推導） |
| 何時建 | 接入必建 | **有踩坑才建，沒有就不建** |

#### integration.md litmus test

寫進 `integration.md` 前先自問：**這條資訊，讀 `api.md` ＋ 讀 `Driver` / 回調 `Controller` 程式碼就能得到嗎？**

- **能得到** → ❌ 不要寫。程式碼是 source of truth，逆向整理的摘要只會 drift。
- **只有實際接入／踩坑／三方往返才知道** → ✅ 才寫。

| ❌ 不該進（逆向摘要，會 drift） | ✅ 該進（一手心得） |
|---|---|
| Driver ↔ API 方法／欄位對應表 | 文件寫 X 實測是 Y 的**已驗證**落差（附驗證方式） |
| 加解密演算法步驟的純複述 | 三方文件自身的錯誤／過期欄位（附 incident／commit／工單／日期） |
| 純複述程式碼的訂單狀態機 | 實測確認的簽名／加解密／時區／金額精度陷阱 |
| 純複述廠商規格的回調狀態碼表 | 維運注意（回調冪等、補單窗口、對帳口徑）＋ 安全缺口（未驗簽、憑證外洩） |

> 不建 per-vendor `test-cases.md`；測試依本 skill 的 `reference/testing.md` 寫在 `tests/` 下。

#### 另兩份：`runbook.md` 與 `open-questions.md`（有需要才建）

| | `runbook.md` | `open-questions.md` |
|---|---|---|
| 回答 | **上線那天要做什麼、怎麼確認、出事怎麼止血** | **哪些事廠商文件沒寫清楚、問了沒、答了什麼** |
| 內容 | 閘門（缺一項就不啟用）、上線會動到哪些既有資料、金流商後台設定、正式庫配置命令（每步先預覽）、啟用順序與驗證、止血與回滾、上線後監控、Dev 測試網驗收環境 | 狀態總覽表（題號、優先級、狀態、對應 `api.md` 章節）＋逐題的問法、影響、廠商原文回覆 |
| 何時建 | 有正式上線作業才建；金流商通用的上線流程在 APIs `docs/金流接入流程/4-上線.md`，runbook 只寫這家專屬的步驟與依據。上線後保留，作為之後配置新站點與維運的依據 | 接入期間有題目要問廠商才建；廠商回覆後屬規格者補進 `api.md`、屬已驗證落差者依 litmus test 補進 `integration.md`，全部結案後本檔可刪 |
| 紅線 | 憑證值不寫進任何檔案、命令參數或訊息，只在後台填入 | 廠商回覆原文照錄（含語言），不改寫 |

`integration.md` 除了踩坑，也放「我方實作決策索引」：一張表列決策與權威出處（各 class 的 docblock），不重述規則。範例見 CCPayment 四份文件。

> **讀取規範（重要）**：接遊戲／金流商**沒有「對接卡」概念**，必讀的只有兩類文件：三方 API 文檔 `api.md`（廠商原文）＋ 對接心得 `integration.md`（我方接入時踩到、文件＋程式碼查不到的坑）；`runbook.md` 與 `open-questions.md` 不是每次都要讀，上線或問廠商時才讀：`open-questions.md` 是接入期間的暫時性文件，全部結案後可刪；`runbook.md` 上線後留作維運依據，之後配置新站點、止血回滾都照它，不刪。**在站點配置金流商戶就是上線作業**（建金流商戶、填憑證、設匯率、啟用）：同目錄有 `runbook.md` 時必須照它逐步做，不憑記憶或其他站的做法自行組步驟。**任何需要參閱 `api.md` 的場景，必須先檢查同目錄是否有 `integration.md`；若存在，視為重點知識優先參閱**：它比廠商原文更貼近真實行為。接入過程中**踩到坑、或發現文件與實作的已驗證落差時**才補進對應的 `integration.md`（依上方 litmus test 判斷，不是「覺得重要」就寫）。

> **命名規範**：整合文檔一律於 `docs/integrations/payments/<ProviderName>/` 下，三方 API 文檔 `api.md` ＋ 對接心得 `integration.md`。

現有對接心得（APIs repo）：

- `integrations/payments/VtPay/integration.md` — VtPay（AES-128-CBC、馬來 / 越南 / 新加坡多支付方式）
- `integrations/payments/GlobePay/integration.md` — Bigpayz (GlobePay365)（AES-256-CBC＋RSA、Status===6）
- `integrations/payments/CCPayment/integration.md`：CCPayment（加密貨幣、USDT 永久地址模式、鏈代號 TRX 不是 TRC20、只讀官方 markdown 版文檔；同目錄另有 `runbook.md` 與 `open-questions.md`。`runbook.md` 各站通用，開頭的站點對照表列各站的連線與 id，配置新站點從「正式庫配置」一節的連線與防呆開始走）
