# Pg（Play'n GO，vendor_id=20）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。
>
> ⚠️ 此 `Pg` 為 **Play'n GO**（`PgAccount`/`PgBetRecord`），與 **PG Soft**（`target*` / `Pgssw*` Model）為**不同廠商**，勿混淆。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 Play'n GO 端保管 |
| 傳輸協定 | **SOAP**（`SoapClient` + WSDL），**非** REST/JSON |
| 呼叫方向 | **本平台 → Play'n GO**（主動呼叫）；無 seamless callback |
| 驗證 | SOAP WSDL `login` / `password`（Basic）；endpoint 由 `API_URL`（WSDL）決定 |
| 轉帳成功判定 | **回應為 object 且不含 `UserAccount` 欄位** → `SUCCESS`；含 `UserAccount` 或非 object → `TIMEOUT`（無真正 status/code） |
| 進遊戲 | SOAP `GetTicket` 取 `Ticket` → 組 `GAME_URL?pid&game_id&channel=mobile&lang&ticket&practice=0` |
| 對帳 | Live Data Feed 推送事件（見 api.md）寫入 `PgBetRecord` |

## 陷阱（高風險）

### 1. 憑證明文落 log（違反專案規範）

`Driver::apiCall()` 兩處 `\Log::info('PG_'.$endPoint, $info_data)` 把 **`login` / `password` 明文**寫入 log（`$info_data` 含這兩鍵）。違反 CLAUDE.md「敏感資料記錄須 `Common::maskCredentials()`」。**整改建議**：mask 後再記錄，或移除憑證欄位。

### 2. 弱加密 + 關閉憑證驗證

SOAP option：`ciphers => 'RC4-SHA'`、`verify_peer => false`、`verify_peer_name => false`。RC4-SHA 為已淘汰弱加密；憑證驗證全關。對接時須與 Play'n GO 確認正式環境是否仍要求 RC4-SHA，以及能否改回憑證驗證。

### 3. 轉帳成功判定靠「缺欄位反推」

`transfer()` 無廠商提供的狀態碼，靠「回應**沒有** `UserAccount`」反推成功（Driver 註解：「沒有檢查用的 API，也沒有提供狀態，這裡通通給 Timeout」）。**無補單 API** → 逾時／回應異常時最終狀態無法確認，存在資損風險。

## 轉帳流程

```
存款：transferToVendor → SOAP CreditAccount
   │
進遊戲：getGameUrl → SOAP GetTicket → 組 GAME_URL（含 ticket）
   │
提款：transferToLocal → SOAP DebitAccount
   │
對帳：Live Data Feed 事件 → PgBetRecord
```

- 餘額：`getBalance` → SOAP `Balance` 取 `UserBalance->Real`，`Common::numFloor(.., 4)`。
- 語言：`zh_TW` / `zh_CN` / `ms_MY` / `id_ID`，預設 `en_GB`。

## 整合落地建議

| Play'n GO 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Pg/Driver.php`（`extends AbstractTransferWallet`，SOAP） |
| 帳號 / 注單 Model | `app/Vendors/Models/PgAccount.php`（`username`）/ `PgBetRecord.php` |
| 轉帳實作 | `transferToVendor`→`CreditAccount` / `transferToLocal`→`DebitAccount` |
| `vendor_infos.info`（`VendorInfoDetailData`） | `API_URL`(WSDL) / `API_LOGIN` / `API_PASSWORD` / `API_PID` / `Currency` / `Brand?` / `Country?` / `GAME_URL?` |
| `GAME_URL` 預設 | 硬編碼 `https://example.invalid/casino/ContainerLauncher`（Driver 內 default，見 gaps） |
| 註冊位置 | `config/vendor.php` → `VendorIdEnum::PG->value (20) => Vendors\Pg\Driver::class` |
| 回調 controller / routes | **無**（轉帳錢包；對帳走 Live Data Feed） |

## gaps / 待 Play'n GO / RD 確認（非確認 bug）

1. **憑證明文落 log**（見陷阱 1）：屬實作層面待整改，須改用 `maskCredentials()`。
2. **RC4-SHA + verify_peer=false**（見陷阱 2）：待確認正式環境加密／憑證要求。
3. **無補單 API、success 靠缺欄位反推**（見陷阱 3）：待確認 Play'n GO 是否有轉帳狀態查詢／對帳補單機制。
4. **`Amount` 未經 Common 正規化**：`transfer()` 直接傳 `$params->vendorAmount` 進 SOAP，待確認大額／小數在 SOAP 序列化後的精度。
5. **`GAME_URL` 預設值硬編碼**：Driver 內 default 指向特定網域，建議改由各白標 `info` 明確配置。
