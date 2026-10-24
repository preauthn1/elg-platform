# target（vendor_id=24）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 target 端保管，進遊戲前需 transfer-in，離開後 transfer-out |
| 驗證方式 | **無簽章雜湊**；每次請求 body/queryString 帶明文 `merchantID` + `merchantSecretKey` + IP 白名單（最多 10 組） |
| 成功判定 | `code === 0`，HTTP 200；失敗 HTTP 400 |
| 時區 | UTC (+0)；格式 ISO8601 |
| 金額精度 | float，小數 2 位；API 回傳餘額以 `Common::numFloor($amount, 4)` 正規化 |
| 進入遊戲 | `GET /game/link` → 取得遊戲 URL（pc 與 mobile 共用同一連結） |
| 共用 Driver | JILI(61)、PGS_LIVE(62)、FUNKY(70) 也 map 到同一 `Vendors\target\Driver` |

## 高風險陷阱

### 1. `merchantSecretKey` 明文出現在每次請求中

target 不用簽章演算法——密鑰以明文形式放在每次請求的 body（POST）或 queryString（GET）。

> ⚠️ **`Http::logAction("PGS_*")` 會完整記錄請求參數。必須確認 logAction 是否已對 `merchantSecretKey` 做遮罩，或補加 `Common::maskCredentials()` 過濾後再送 log。洩漏等同暴露所有 API 的存取金鑰。**

### 2. 勿混淆 PGSSW(50) 與 target(24)

`PGSSW(50)` 使用 `Vendors\PegasusSW\Driver`（**單一錢包**），與本 vid 24 的 `Vendors\target\Driver`（**轉帳錢包**）**完全不同**。路由、帳號 Model、BetRecord 全無關聯。

### 3. 預付/預扣款模式（捕魚遊戲）欄位語意不同

`isPrePaidMode=true` 的遊戲（含捕魚）其欄位語意如下，對帳時須分路處理：

| 欄位 | 一般遊戲 | 預付模式（捕魚） |
|---|---|---|
| `betAmount` | 投注金額 | 進房預扣全部金幣 |
| `payoutAmount` | 派彩金額 | 退房返還剩餘金幣 |
| `validAmount` | 有效流水 | 實際發射子彈金幣 |

### 4. 逾時不還錢，先補查

`transfer-in` / `transfer-out` 遇連線逾時、回應非 JSON、無 `code` 欄位 → 回 `VendorTransferResultEnum::TIMEOUT`，**不還錢**，待 `checkTransferStatus`（`GET /transfer`）補查後決定。

### 5. `/games` 端點的密鑰參數是大寫 `MerchantSecretKey`

PDF 通用約定寫所有請求帶 `merchantSecretKey`（小寫 m），但 `GET /games` 實際要用**大寫 M 開頭的 `MerchantSecretKey`**——線上每日同步以此命名運作多年（RD-Studio `get_pgs_games` 生產驗證）。實作 `getGameList()` 沿用大寫命名；其他端點（`game/link`、`wallet/*`、`transfer`）維持小寫。

### 6. Live Casino 有效流水平均分攤

同一局多次下注時，`validAmount`（有效流水）以**局為單位**計算後平均分給每筆注單（例如同局 3 筆、有效流水 600 → 每筆 200）。對押時 `validAmount` 歸 0。

## 轉帳流程與冪等性

```
進入遊戲：
  (可選) GET /player/exist  檢查玩家是否存在
    └─ 不存在 → POST /player  建立玩家
  POST /wallet/transfer-in   運營商餘額 → target（存款）
  GET  /game/link            取得遊戲 URL

離開遊戲：
  POST /wallet/transfer-out  target 餘額 → 運營商（提款）

逾時補查：
  GET /transfer              以 merchantOrderNo 查單筆轉帳最終狀態
```

- `merchantOrderNo`（`VendorTransferData::$transactionNo`）為轉帳唯一識別。
- `POST /wallet/transfer-in` → `Driver::transferToVendor()`
- `POST /wallet/transfer-out` → `Driver::transferToLocal()`
- `GET /transfer` → `Driver::checkTransferStatus($transactionNo)`

| 補查條件 | 結果 |
|---|---|
| 逾時 / 無 JSON / 無 `code` 欄位 | `TIMEOUT`（繼續補查） |
| `code == 0` 且 `data` 非空 | `SUCCESS` |
| 其餘 | `FAILED` |

## 整合落地建議

| target 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/target/Driver.php`（繼承 `AbstractTransferWallet`） |
| Account Model | `App\Vendors\Models\PgsAccount`（`username` = `playerName`） |
| BetRecord Model | `App\Vendors\Models\PgsBetRecord` |
| 轉帳結果 Enum | `VendorTransferResultEnum`（SUCCESS / FAILED / TIMEOUT） |
| `vendor_infos.info.API_APPID` | `merchantID` |
| `vendor_infos.info.API_KEY` | `merchantSecretKey`（敏感，log 必須遮罩） |
| `vendor_infos.info.API_URL` | Base URL（含 `/v1`；建構時 `trim(..., '/')`) |
| `vendor_infos.info.LIVE_BET_LIMIT` | Live Casino 限紅等級 `liveCasinoLevelID`（空則不帶） |
| Callback / Controller | 無（Transfer Wallet 無下注回調） |
| 路由 | 無 external 回調路由（`sw.php`、`tw.php` 均無 target） |
| IP 白名單 | target 端設定運營商 IP（每商戶最多 10 組）；本平台驗 target IP 用白名單防火牆 |
| 對帳 API | `GET /game-records`、`GET /game-records/report`、`GET /event/prize-records`（Driver 未直接呼叫，待確認排程） |

## gaps / 待 target 確認

- **`merchantSecretKey` log 遮罩**：確認 `Http::logAction("PGS_*")` 是否已遮罩敏感欄位，或需補 `Common::maskCredentials()`。
- **對帳資料保留期**：PDF 未明定 `/game-records` 資料保留天數，需向 target 客服確認。
- **Driver 未呼叫的 API**：`player/exist`、`player`、`game-records`、`game-records/report`、`event/prize-records` 在 Driver 中未實作——投注紀錄寫入 `PgsBetRecord` 的排程/模組待確認。（`games` 已由 `getGameList()` 實作，走遊戲清單同步。）
- **`lang` 支援清單**：實作帶 `zh-tw`，但 PDF 的 `locale` header 僅提 `zh-cn`、`en-us`；`game/link` 的 `lang` 實際可否傳 `zh-tw` 待向客服確認。
- **餘額精度**：實作以 `numFloor($amount, 4)` 正規化，但 PDF 規範金幣 2 位小數；target 是否可能回傳超過 2 位待確認。
- **完整錯誤碼表**：PDF 僅列區間級（`403001~403099`），完整明細在外部開發環境連結（`example.invalid`），需確認可否存取。
