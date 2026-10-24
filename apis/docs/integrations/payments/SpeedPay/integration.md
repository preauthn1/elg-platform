# SpeedPay 對接心得

> 本檔只寫**接入時才知道、讀 `api.md` 與程式碼都得不到**的事。廠商原文規格見 `api.md`。
> 商戶：`SPMVW`（MYR，工單 [#4172](http://example.invalid:1688/target/tickets/-/work_items/4172)、
> 商戶資料 [#3557](http://example.invalid:1688/target/tickets/-/work_items/3557)）。

---

## 1. DuitNow 通道曾回 `0901`，但兩者實際都可用（原結論已更正）

⚠️ **本節原結論「DuitNow 通道未開通、只有 P2P 可用」已於 2026-08-25 被推翻**，保留原始觀測供對照。

**原始觀測（2026-08-24 Dev）**：

| paymentCode | 實測結果 | 證據 |
|---|---|---|
| `P2PDEPOSIT` | ✅ 成功建單 | order `202608241573643078`、`providerOrderNo QRHA20260824061946000571718741` |
| `DUITNOWP2P` | ❌ 業務碼 `0901 No payment channels` | order `202608249600501411`、Tid `01a031f6-2732-71a9-9438-6b00212c3270` |

DuitNow 失敗時**信封層是成功的**（`resCode: "000"`／`resMsg: "Success"`，且三方正確回吐
`merchantNo: SPMVW`）⇒ 簽名、憑證、端點、參數格式全部正確，問題只在該通道本身。

**推翻它的兩項證據（2026-08-25 查）**：

1. **生產已有 DuitNow 成交**：VWorld order **15243542**（acf 374／ot 631、`paymentCode DUITNOWP2P`、
   RM10）於 2026-08-24 成交，`status=3`（`DEPOSIT_FINISHED`），回調 `status:"SUCCESS"`、
   `gbpOrderNo QRHA20260824081932000756747601`。同批 P2P（order 15243544）亦成功。
2. **三方 `/setting` 回該線路為啟用**：`DUITNOWP2P status=ON`、單筆 5.00 ~ **1000.00**
   （`artisan cash-flow:third-party-query 374`，見 §8）。

**`0901` 的真因仍不明**：Dev 那筆金額為 RM100，落在 5~1000 區間內，**不是超限**；可能是當時三方
端尚未開通、或 Dev 所用商戶設定與生產不同。**此點未驗證，勿當結論引用。**

舊系統實績的量差仍成立：DuitNow（acf 280／281）近半年僅 7 筆，P2P（acf 282）自 2026-02 起
29,196 筆——DuitNow 是可用但低量的通道，不是不可用的通道。

## 2. `0901` 是三方文檔未記載的業務碼

`api.md` §5.2 只記載 `0000` 成功、`01XX–02XX` 訂單建立錯誤、`20XX` 公對私、`21XX` 快捷、
`22XX` 掃碼——**`09XX` 不在任何區間**。實測 `code: "0901"` / `msg: "No payment channels"`，
且回應的所有業務欄位（`orderNo`／`gbpOrderNo`／`resultUrl`／`QrCode`…）皆為 `null`。

語意＝「該商戶 × 該 paymentCode 在三方端查不到可用通道」。它**不代表商戶失效**——同一商戶
換 `P2PDEPOSIT` 即成功。排查時別因為這個碼就懷疑憑證。

## 3. 同協定兩商戶的端點開通不同

`api.md` §3.1 原文只記載代收端點 `/qrh5`，本商戶（`SPMVW`）兩通道皆走 `/qrh5`、與原文一致；
但**同協定的 PayMate 商戶（`PM134126`）的 `P2PDEPOSIT` 走 `/cashier`**（廠商另行提供、非原文）。

實證：VWorld 舊系統 4 筆 SpeedPay 代收配置（acf 280／281／282／283）的 `info.APIUrl` 全為
`https://example.invalid/qrh5`。

因兩商戶共用同一份 `PayMate\Driver` 實作，端點對照下沉到 `info[ENDPOINT_BY_PAYMENT_CODE]`
（`PayMate\Deposit::resolveDepositEndpoint()`），未配置時回退類別常數。**SpeedPay 必須配置此鍵**，
否則主力的 `P2PDEPOSIT` 會被打到不存在的 `/cashier`。

> 端點值必須是**相對路徑**（以 `/` 開頭、不含 `//`／`@`／`:`）。填入完整 URL 會被
> `API_URL . $path` 拼成畸形位址；帶 userinfo 的值（`@evil.tld/x`）更會把簽名與訂單參數送往他站。
> 兩者都能通過 `is_string`，故實作端已加格式校驗並在誤配置時記 `Log::warning`。

## 4. 付款頁：不同網域、3 分鐘有效期、商戶號與我方不同

建單成功回的 `resultUrl` 形如
`https://example.invalid/launch/<JWT>`，解出 JWT payload：

```json
{"OrderNo":"D2026082406194600087902524","MerchantNo":"SPG205271",
 "nbf":1787552386,"exp":1787552566,"iat":1787552386}
```

| 事實 | 維運影響 |
|---|---|
| **`exp - nbf` = 180 秒** | **付款連結只有 3 分鐘有效期**。會員停留超時再點會失效，需重新下單；前端應提示或自動刷新 |
| 付款頁網域 `example.invalid` | 與 API 網域 `example.invalid` **不同站**。CSP／網域白名單／防詐提示需一併納管 |
| JWT 內 `MerchantNo: SPG205271` | **不是我方商戶號 `SPMVW`**，應為三方內部的下游收單商戶。對帳看到此號不要誤判成其他金流商 |

## 5. 舊系統 → 新 APIs 的 info 鍵名與 URL 尾綴

舊系統（`cash_flow_id` 3／23）用 PascalCase：`APIUrl`／`MerchantNo`／`MerchantKey`／
`hasPaymentCode`；新 APIs 用 SCREAMING_SNAKE：`API_URL`／`MERCHANT_NO`／`MD5_KEY`。

🔴 **舊系統的 `APIUrl` 存的是含 `/qrh5` 尾綴的完整 URL**，遷入時必須去掉尾綴，否則
`sendRequestRaw` 以 `API_URL . $path` 拼接會變成 `/qrh5/qrh5`。工單 #3557 給的
「MYR API address」同樣是含尾綴的完整 URL，**不可照抄進 `API_URL`**。

## 6. 共用實作導致告警指向錯誤商戶

`getDriverName()` 以 Provider 目錄名推導，SpeedPay 共用 `Providers/PayMate/` ⇒ 所有 log、
`ApiException` 訊息、`Http::logAction`、代付查表灰度閘門全部標成 "PayMate"。VWorld 並無
PayMate 商戶，事故時第一手告警會指向不存在的商戶。

解法＝`info[DRIVER_LABEL]`（見 `Payment\Traits\DriverTrait::driverLabelOverride()`）。
**新增共用同一實作的商戶時務必配置此鍵**，否則排查會被誤導。

## 7. §5.3 渠道代碼表是廠商全局清單

MY 區另列 `DUITNOWQR`、`FPX`、`REFQR`，本商戶**未開通**，切勿因為表上有就加進通道配置
（參考 §1：連工單明列的 DuitNow 都未實際開通）。

## 8. 維運注意

- **回調 URL 帶 acf id**：`external/api/deposit/notify/{agentCashFlowId}`，代收與代付各自對應
  自己的 acf。拆分模式下每個 channel 一筆 acf，回調 URL 因此各不相同（實測 P2P 為
  `.../notify/7667`），換 acf 或重建配置後務必確認三方端登記的 URL 已同步。
- **憑證留空期間可被偽造**：`MD5_KEY` 為空時簽名字串只由回調自身欄位組成、可離線算出，
  且 `merchantNo` 比對送空字串即可繞過。**建立 acf 與填入憑證務必在同一維護窗口完成**，
  並確認 ingress 已對 notify 端點套用 #3557 的 31 個來源 IP 白名單（code 層無來源驗證）。
- **代付：通道已開通、但唯一一筆實測被三方判 FAIL**（2026-08-24 VWorld acf 376 實跑，
  `withdrawal_payout_records` id 1091538）。鏈路事實：
  - 送出 RM100 / bankCode `OCBC`，三方同步回 `code:"0000"` `msg:"Success"` 並給 `gbpOrderNo`
    ⇒ **代付服務確實已開通**，`/payout` 端點與請求簽名皆正確。
  - 73 秒後回調 `service:PAYOUT`／`status:"FAIL"`，**不帶任何錯誤碼或原因欄位**。
  - 我方據此落 `is_success_payout=-3`（`CALLBACK_FAILED`）、訂單轉 `status=9`（`REFUND`），
    回調處理鏈路正常。
  - 🔴 **FAIL 原因不明**：回調無原因欄位，需查商戶後台或問三方（可能是測試帳號銀行資料無效、
    三方代付餘額不足、或該銀行線未開）。
  - ⚠️ 代付限額仍是取同協定 PayMate 的實際值（min RM50／max RM4999）當**暫用值**，
    19 家銀行清單也未經三方 `supportBankCode` 核對；代付端點 `/payout` 硬編、不可由 info 覆寫。
  - ⇒ 核對工具：`artisan cash-flow:third-party-query <acf id>`（api.md §3.4 `/setting`，
    唯讀），一次取回 `payoutStatus`、逐線路真實限額與 `supportBankCode`，並自動與我方
    `info` 的 `min_amount`／`max_amount`／`available_banks` 比對。
- **三方 `/setting` 實測結果（2026-08-25，`artisan cash-flow:third-party-query`）**：

  商戶三個開關（`merchantStatus`／`depositStatus`／`payoutStatus`）**全為 `ON`**；代付餘額充足
  （實查，金額不落檔）。三條線路：

  | 線路 | 狀態 | 三方單筆下限 | 三方單筆上限 | 支援銀行 |
  |---|---|---|---|---|
  | `PAYOUT` | ON | 30.00 | 20000.00 | 37 家 |
  | `P2PDEPOSIT` | ON | 10.00 | 20000.00 | —（掃碼類） |
  | `DUITNOWP2P` | ON | 5.00 | **1000.00** | —（掃碼類） |

  與我方配置比對：

  | 我方通道 | 我方限額 | 判定 |
  |---|---|---|
  | acf 376 info（代付） | 50 ~ 4999 | △ **比三方嚴**——不會被拒，但沒用滿三方允許的 30 ~ 20000 |
  | acfot 842／ot 632（`P2PDEPOSIT`） | 10 ~ 20000 | ✅ 與三方一致 |
  | acfot 841／ot 631（`DUITNOWP2P`） | 5 ~ 20000 | 🔴 **上限比三方寬 20 倍**——會員送 1001~20000 的單會到三方才被拒 |

  代付銀行：我方 19 家**全部**都在三方 37 家內（含那筆 FAIL 用的 `OCBC`）；三方另有 18 家未配置
  （`AAAA, BAKO, BIGP, BNPB, BOOB, CCCC, CITI, DEUT, HSBC, ICBC, JPMB, KFHB, MBSB, MCCB, MIZU,
  MUFG, RAJH, SUMB`），可評估補上。

  ⇒ **那筆代付 FAIL 的可能原因被大幅收斂**：`payoutStatus=ON`、餘額充足、`OCBC` 在支援清單內、
  金額 RM100 落在 30~20000 內 ⇒ 上述四項都不是原因。剩下最可能是**收款帳號／戶名資料無效**
  （測試帳號），但三方回調不帶原因，**此推論未驗證**。
