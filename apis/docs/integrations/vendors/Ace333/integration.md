# Ace333（vendor_id=6）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 ACE333 端保管 |
| 進遊戲前 | DES+MD5 加密取 `actk` → 組遊戲 URL |
| 驗證（我方→ACE333） | HTTP Header `token`（API Service Token），明文 JSON Body |
| H5 簽章 | DES-CBC（key 兼 IV）+ MD5，詳見 `api.md §簽章` |
| 成功判定 | `error === '0'` |
| 時區 | UTC |
| 資料保留期 | **14 天**，對帳工具須在期限內完成 |

## 兩層 API 結構（易混淆）

| 層 | 驗證方式 | 加密 | 用途 |
|---|---|---|---|
| Integration / Transfer API | Header `token` | 明文 JSON（不加密） | topup / withdraw / getbalance / checkorder |
| H5 遊戲啟動 | `q`（DES）/ `s`（MD5）/ `accessToken` | DES-CBC + MD5 | 取 actk（Login），組遊戲 URL |

## 陷阱與落差

1. **createplayer 未在 Driver 直接呼叫**：`Ace333Account.player_id` 寫入來源不明，需確認是否在 `AbstractTransferWallet` 共用層或帳號建立流程中觸發。若玩家未在 ACE333 建號就嘗試 topup，會導致失敗。

2. **Driver 僅判 `error === '0'`，未回寫 `transactionID`**：topup/withdraw 成功後 PROVIDER 回傳的 `transactionID` 未保存，對帳時若需 PROVIDER 端交易 ID 須另行查單。

3. **Driver 用 `api/logout` 先踢人再提款**（transferToLocal）：分數常卡遊戲內，logout fire-and-forget（不檢查回傳）。

4. **H5 GameList 的 accessToken 需 URL encode，Login 不需要**：兩者格式不同，實作已處理，閱讀時留意。

5. **Datafeed Base URL 未設**：v2.39 新增 `API_DATAFEED_BASE_URL` 用於對帳資料流，Driver 統一用單一 `API_URL`。若日後接 AccountTransactions3 對帳須補設。

## 錢包流程（Transfer Wallet）

```
getGameUrl()
  └─ DES 加密組 QS → POST H5_TOKEN_URL/api/Acc/Login → 取 actk
  └─ 組遊戲 URL：{H5_GAME_URL}{remark}?actk={actk}&lang={lang}

transferToVendor (存款)
  └─ api/topup（`error === '0'` = SUCCESS）

transferToLocal (提款)
  └─ api/logout（fire-and-forget）
  └─ api/withdraw（`error === '0'` = SUCCESS）

checkTransferStatus (查單)
  └─ api/gettimepoint（以交易 created_at 毫秒）→ Timepoint
  └─ api/checkorder（referenceId + currency + timepoint）
  └─ transStatus 177→SUCCESS / 178/179→FAILED / 170/180/其他→TIMEOUT
```

Transfer Wallet OPERATOR 需對外提供：`api/authenticate`（玩家進遊戲時 ACE333 回呼驗證帳密）。

## 整合落地建議

| ACE333 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Ace333/Driver.php`（`extends AbstractTransferWallet`） |
| Account Model | `app/Vendors/Models/Ace333Account.php`（`player_id`、`username`） |
| BetRecord Model | `app/Vendors/Models/Ace333BetRecord.php` |
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::ACE333->value (6) => Vendors\Ace333\Driver::class` |
| `TOKEN` | `vendor_infos.info.TOKEN`（Integration API Header `token`） |
| `H5_TOKEN_URL` | `vendor_infos.info.H5_TOKEN_URL`（H5 Login） |
| `H5_GAME_URL` | `vendor_infos.info.H5_GAME_URL` |
| `H5_SECRETKEY` / `H5_MD5ENCRYPTKEY` / `H5_ENCRYPTKEY` | H5 DES+MD5 金鑰 |
| `DELIMITER` | `vendor_infos.info.DELIMITER`（`♂♫‼◄¶`，特殊字元，原樣使用） |
| `POSTFIX` | `vendor_infos.info.POSTFIX`（如 `@vworld`） |
| `CURRENCY` | `vendor_infos.info.CURRENCY` |
| 對帳 | Integration API（AccountTransactions3）每 5 分鐘不超過 10 次；近 14 天有效 |
| authenticate 路由 | Transfer Wallet 唯一外部 callback，舊系統可能在 API_External；需 RD 確認 APIs 的實際路由 |

## Gaps / 待確認

- `createplayer` 的實際呼叫觸發點（Driver 未見直接呼叫）
- `api/authenticate` endpoint 在 APIs 專案的確切 controller / route 位置
- 對帳資料流的 `API_DATAFEED_BASE_URL` 是否已設（v2.39 新增）
