# Pp（vendor_id=54）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 廠商 | Pragmatic Play（PP），API 版本 3.225（2026-08） |
| 錢包模式 | 轉帳錢包（Transfer Wallet / 余额结转 §IV）；玩家餘額由 PP 端保管 |
| 流向 | **本平台 → PP**（本平台主動呼叫 PP；無回調，Transfer Wallet 不需提供 callback） |
| 驗證 | IP 白名單 + 每請求 MD5 `hash` |
| 成功判定 | `error === "0"`（或 `"8"` 於 Transfer，見下方陷阱） |
| 請求格式 | `application/x-www-form-urlencoded`（回應為 JSON；TransferTransactions 例外回 CSV） |
| 與 PPSW 的關係 | PPSW（vid=83）為 Seamless Wallet 獨立 vendor；本專案 vid=54 只對接 Transfer Wallet，兩者互相獨立 |

## 高風險陷阱

### 1. `error: 8`（交易已存在）必須視為成功

官方 §4.11 明確：`error: 8` = 「交易已存在，無需操作（視為成功）」。逾時重送命中既有交易時，PP 回 `error: 8`。

**現行實作**：`Driver::transfer()` 只把 `error === '0'` 視為 `SUCCESS`，其餘一律 `FAILED`。收到 `error: 8` 會被誤判為 FAILED，在逾時重送情境下可能誤判已成功的轉帳為失敗，導致誤回滾或改派。

**修復方向**：在 `transfer()` 增加 `error === '8'` → `SUCCESS` 分支。

### 2. GetTransferStatus `Not found` ≠ 失敗（是 pending）

官方語意：`status: "Not found"` = 該交易**尚未處理（pending）**，應繼續等待或重試。

**現行實作**：`Driver::checkTransferStatus()` 把 `Not found` 映射為 `FAILED`（程式碼內備註反而寫「Not found=處理中」，邏輯與備註矛盾）。在「轉帳仍在處理中」時會誤判為失敗，可能觸發誤回滾或改派。

**修復方向**：將 `Not found` 映射為 `TIMEOUT`（讓上層續查/重試），而非 `FAILED`。

### 3. StartGame 多送 `country` 欄位

`Driver::getGameUrl()` 固定多帶 `country='MY'`（不在 §4.8 StartGame 官方參數表），且此欄位進入 hash 計算。若 PP 端嚴格只用文件定義欄位算 hash，會造成簽章不符。目前實測可正常開局（PP 端可能直接忽略未知欄位），但屬待確認項。

### 4. 真人局 `status = F` 是取消，不能計入投注

`gamerounds/finished` 的 `status` 有 `C`（已完成）與 `F`（取消或自動完成）兩種，定義見 `api.md`「DataFeeds」。PP 客服 2026-10-21 回覆：**真人局不會自動完成，bet_id 以 `008` 結尾（＝`dataType=LC`）且 `status = F` 就是取消單**，PP 後台投注報表不計入。

**事故**：Driver 原本只認 `roundDetails.result = Betcancelled` 為取消單，真人取消局被當成一般注單，投注與有效投注多算（2026-10 Yes2Win 對帳：10/1、10/3、10/7、10/12、10/14、10/18 的投注差額，逐筆對上這類真人局）。

**現行處理**（`Driver::isCancelled()`）：

| 條件 | 處理 |
|---|---|
| `roundDetails.result = Betcancelled` | 取消單：`bet`、`win_lose` 歸零，`bet_status = 0` |
| `dataType = LC` 且 `status = F` | 同上，視為取消單 |
| `dataType = RNG` 且 `status = F` | 自動完成的回合，有真實輸贏，照一般注單入帳 |
| `status = C` | 一般注單（含派彩剛好等於投注的和局） |

> Yes2Win 實測：`LC` 注單的 bet_id 全部以 `008` 結尾，`RNG` 沒有，兩種判斷等價；程式用 `dataType` 判斷。

**已入庫的舊資料**：修正前寫入的真人取消局，要用 `fetch_bet_record --vendor=54 --update` 重拉該時段，金額才會改成 0。

## 錢包流程

```
（選用）CreatePlayer 建立玩家帳戶
               │
               ▼
Transfer（amount 正值）把本地餘額轉入 PP    ← transferToVendor
               │
               ▼
StartGame 取得 gameURL → 玩家於 PP 端遊戲（餘額由 PP 保管）
               │
               ▼
Transfer（amount 負值）把 PP 餘額轉回本地    ← transferToLocal
               │
補查：GetTransferStatus（checkTransferStatus）
對帳：TransferTransactions CSV
```

- 轉入用正值 `amount`，轉出用負值（Driver 前置負號 `'-'`）。
- `externalTransactionId` 為唯一冪等鍵：逾時後以**相同 `externalTransactionId`** 重送，最多 1 小時。
- **CreatePlayer 選用**：Driver 未呼叫，依賴 PP 端「首次請求自動建立帳戶」的行為。若 PP 端日後要求 Transfer 前帳戶必須先存在，需補上 CreatePlayer。

## 冪等性

| 情況 | Transfer 回應 | 正確處理 |
|---|---|---|
| 首次成功 | `error: 0` | SUCCESS |
| 重試命中既有成功交易 | `error: 8` | SUCCESS（視為成功，無需操作）|
| 傳輸中 / 進行中 | `error: 100`（GetTransferStatus 回） | 依頻率繼續重試 |
| 未找到（GetTransferStatus） | `status: "Not found"` | pending，應 TIMEOUT 繼續查 |

## 整合落地建議

| PP 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Pp/Driver.php`（`AbstractTransferWallet`） |
| Account Model | `App\Vendors\Models\PpAccount`（`username` = `externalPlayerId`） |
| BetRecord Model | `App\Vendors\Models\PpBetRecord` |
| config 註冊 | `config/vendor.php`：`VendorIdEnum::PP->value (54) => Vendors\Pp\Driver::class` |
| Callback Controller | **無**（Transfer Wallet 不需代理提供 callback） |
| Routes | **無**（轉帳錢包，無 external 回調路由） |

### vendor_infos.info 欄位

| info 欄位 | PP 概念 | 說明 |
|---|---|---|
| `API_URL` | Base URL | 須含到 `.../IntegrationService/v3/http`；Driver 各 endpoint 再接路徑 |
| `SECURELOGIN` | `secureLogin` | API 驗證用戶名 |
| `SECRETKEY` | `SECRET` | MD5 hash 金鑰 |
| `RETURN_URL` | `lobbyURL` | 行動版返回大廳 URL（可空） |
| `BET_URL` | DataFeeds Base URL | 須含到 `.../IntegrationService/v3/`；拉注單接 `DataFeeds/gamerounds/finished/` |
| `DATA_TYPE` | `dataType` | 選填，逗號分隔限縮要撈的產品（`RNG`、`LC`）；空值兩者都撈 |

### Driver 方法 ↔ PP API 對應

| Driver 方法 | Endpoint | PP API |
|---|---|---|
| `getGameUrl()` | `/game/start/` | 4.8 StartGame |
| `getBalance()` | `/balance/current/` | 4.7 GetBalance |
| `checkTransferStatus()` | `/balance/transfer/status/` | 4.6 GetTransferStatus |
| `transferToVendor()` | `/balance/transfer/` | 4.5 Transfer（正金額） |
| `transferToLocal()` | `/balance/transfer/` | 4.5 Transfer（負金額） |
| `fetchBetRecords()` | `BET_URL` + `/DataFeeds/gamerounds/finished/` | 8.2 遊戲回合 |

## Gaps / 待 PP 確認

1. **正式 / 測試 Base URL（domain）**：PDF 僅給 `api.prerelease-env.biz`（pre-release 測試），正式 domain 須 PP 客服提供。

2. **StartGame `country` 參數**：Driver 固定多送 `country='MY'`（不在 §4.8 官方參數表），且進入 hash 計算。目前實測可開局，需向 PP 確認此欄位是否被接受，以及 hash 是否以文件欄位集合為準。

3. **對帳資料保留期**：v3.225 §VIII 註明 DataFeeds「最晚可以提取上個月的數據」，更早的資料須向 PP 客服申請。

4. **`platform` 固定 `MOBILE`**：Driver 目前無論裝置類型皆送 `MOBILE`，桌面玩家也拿到 MOBILE 啟動參數。待確認是否需依 deviceType 動態切換 `MOBILE`/`WEB`。

5. **GetBalance `balance` 精度**：PP 回傳 JSON float，`Driver::getBalance()` 直接 `(string)` 轉型回傳。使用端若參與運算/比較需以 `Common::numAdd('0', (string) $value, 4)` 正規化。

6. **`error: 2`（簽章失敗）vs `error: 5`**：§4.2 提到失敗應回代碼 5，但 §4.11 Balance Transfer 錯誤碼表中簽章/帳密驗證失敗實際回 `error: 2`。對接 Transfer Wallet 以代碼 2 為準。

7. **遊戲清單時效**：離線 xlsx 為 2023-11 版，正式清單以 GetCasinoGames（`/getCasinoGames/`）即時查詢為準。
