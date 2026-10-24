# PegasusSW（target / BWG / ION-Slot 天馬遊戲平台）— 廠商原始 API 規格

> 原始來源：`PGS_SeamlessAPI_中文_v1.4.8.pdf`（v1.4.8，2026-03-27 RD 更新）。抓取日期：2026-06-26。
> 錢包模式：**Single Wallet（單一錢包 / 無縫錢包 Seamless）**。主 Vendor ID：**50（PGSSW）**。
> ⚠️ **聚合 Driver**：`app/Vendors/PegasusSW/Driver.php` 被多家 vid 共用（`config/vendor.php`：PGSSW=50、TIGER9=91、BWG=145、JOKER_BACCARAT=177），回調 `PegasusController` 於 runtime 從 `vendor_infos` 覆寫 `vendor_id`。本檔針對 PGSSW（vid 50）通用規格，不涵蓋各品牌差異。
> 本檔為忠實的第三方 API 規格重述。我方實作分析、陷阱與落地對應見同目錄 `integration.md`。

target / BWG / ION-Slot（天馬遊戲平台）為老虎機、捕魚與真人視訊遊戲供應商。單一錢包模式下玩家餘額由運營商（我方）保管，target 在投注 / 結算時回調我方扣加款。

---

## 通用約定

### 規格與標準

| 項目 | 規範 |
|---|---|
| 風格 | REST API |
| Content-Type | `application/json`（缺漏回 HTTP 415） |
| 編碼 | UTF-8 |
| HTTP Method | GET / POST / PUT；GET 參數放 queryString，POST 參數放 body(json) |
| 金額精度 | **最低至小數兩位**（不支援小數三位以下） |
| 時間欄位 | **時區一律 UTC（+0）**，格式 ISO8601（`yyyy-MM-ddTHH:mm:ssZ`） |
| 回應語系 | **預設英文**；Request Header 加 `locale: zh-cn` 改中文（僅支援 `zh-cn` / `en-us`） |

### 兩種 API 方向

| 方向 | 說明 |
|---|---|
| **運營商 → target**（編號 2.x / 3.x / 4.x） | 運營商主動呼叫 target（取啟動連結、投注紀錄、報表、遊戲列表、活動派獎紀錄、強制登出） |
| **target → 運營商**（編號 5.x） | target 在投注、結算等行為發生時回調運營商實作端點 |

### 通用回應格式（HTTP Status 恆 200）

```jsonc
{ "code": 0, "message": null, "data": { /* ... */ } }
```

> 運營商實作的回調 API **不論成功或錯誤一律回 HTTP 200**，成功與否完全以 body 內 `code` 為準；`data` 須回文件指定欄位，格式不符 / 缺必要欄位 target 將直接拋錯、終止玩家投注與當前流程。

### 安全與驗證

target 單一錢包**無加密簽章**，安全由三項組成：

1. **`merchantID` + `merchantSecretKey`**：每位運營商一組編號與密鑰，所有 API 共用；運營商主動呼叫 target 時於 body 帶入（預先提供）。
2. **`X-PROVIDER-IDENTITY` 標頭**：target 回調運營商時每次帶此 token，運營商以此判斷請求來源合法性（每商戶固定一組，範例 `X-PROVIDER-IDENTITY: 3978c177-6423-4756-a4e8-13064d008b42`）。
   > ⚠️ 標頭名稱於 **v1.3.9（2025-02-13）由 `X-target-IDENTITY` 正名為 `X-PROVIDER-IDENTITY`**；舊版 `X-target-IDENTITY` 仍會保留。
3. **IP 白名單**：雙向——運營商把 target 提供的 IP 加白；運營商把自己 outbound IP 給 target（每商戶最多 10 組）。

### 重試機制（Retry）

- 部分回調（placeBet / settle / cancel / event-bonus）有重試：我方判定失敗或逾時則 target 重發。**逾時判定大部分遊戲 10 秒，部分遊戲 5~10 秒。**
- **placeBet 逾時 / 未得正確回應 → target 立即呼叫取消投注（5.2.9），不 retry。**
- **settle / cancel / event-bonus 逾時或錯誤 → 重試最多 7 次**，每次間隔遞增：`10s → 3min → 9min → 27min → 81min → 243min → 729min`。超過 retry 次數不再通知，運營商須自行用 3.1.2 查單。
- 運營商須確保**同一單號不重複派彩 / 扣款**（冪等）。

### HTTP / 錯誤碼（運營商實作回調 5.1.1~5.9.9 指定回應編號）

| code | 意義 |
|---|---|
| `0` | 正常（成功） |
| `401002` | SessionToken / 身分驗證不合法、身分簽過期 |
| `403001` | 參數輸入不合法、格式不合法 |
| `403203` | 錢包餘額不足 |
| `403601` | 注單狀態不合法（重複結算 / 已關閉禁用） |
| `403612` | 注單狀態已是取消狀態（cancel）— **僅在 cancel callback 支持此 code** |
| `404016` | 查無注單 |
| `500029` | 注單單號重複（鍵值不允許重複） |
| `500001` | 系統錯誤 / 異常 / 其他 |

> 運營商**主動呼叫 target**（2.1.1~4.9.9）時，target 以 400 Http exception 回錯誤：`403001~403099` 參數異常、`404001~404099` 查無資料、`403601` 狀態不合法（關閉禁用）、`500029` 資料重複、`500001` 系統錯誤、HTTP `415` Content-Type 非 json。

---

## 商戶幣別與遊戲幣值

- 一組商戶配置一種幣別；多幣別須申請多組商戶。
- **部分貨幣因政策不支援**（如 CNY 人民幣），需先與 target 確認。
- **1000:1 K 貨幣**（`KVND` / `KIDR` / `KMMK` / `KCOP`…，K 字母開頭非正規代碼）：例 `1 KVND = 1000 VND`，平台端須**自行 1000:1 轉換**（遊戲端不自動換）。
- **MYR100（MYR 1:100）**：無縫錢包商戶用，平台端須在 balance / bet / settle API **自行 100 倍轉換**。
- **後台與 API 的 `betAmount` / `payoutAmount` 一律以「所設定貨幣」顯示**（IDR 1:1；MYR100 顯示 MYR 1:100；1000:1 顯示 1000:1）。
- 對應 `vendor_infos.rate`：回調進來 `Common::numDiv($amount, rate, 4)`、回應餘額 `Common::numMul($balance, rate, 2)`（回應須符合上表兩位精度，見 `integration.md` 陷阱 5）。
- 測試環境統一測試幣 **BETA COIN**（不分幣別，遊戲內最低 / 最高投注固定）。

---

## 運營商 → target API（運營商主動呼叫）

| 編號 | 名稱 | Method | url | 備註 |
|---|---|---|---|---|
| 2.1.2 | 取得遊戲啟動連結 | GET | `/game/launch-link` | |
| 3.1.1 | 取得投注紀錄 | GET | `/game-records` | ⚠️ rate limit |
| 3.1.2 | 依投注單號取得投注紀錄 | GET | `/game-records/transactionNo` | |
| 3.1.3 | 取得商戶總報表 | GET | `/game-records/report` | |
| 3.3.1 | 取得遊戲結果回放（僅特定遊戲） | GET | `/game-records/screenshot-history/link` | |
| 4.6.1 | 取得遊戲列表 | GET | `/games` | ⚠️ rate limit；`isPrePaidMode` 判捕魚預扣 |
| 4.8.1 | 取得活動派獎紀錄 | GET | `/event/prize-records` | |
| 4.9.9 | 強制登出玩家 | POST | `/player/logout` | |

> ⚠️ **部分 API 有每小時存取次數限制（rate limit）**，詳見 target 外部文件站。完整參數表 PDF 未展開，指向外部站 `http://example.invalid/api-document/singleWallet/{EN,CN}`。
> 啟動流程：玩家點擊 → 運營商呼叫 2.1.2 取 game-page-link → 導向玩家至 target 遊戲頁。

### 4.6.1 取得遊戲列表（GET `/games`）

> **來源標示**：三方規格書《PGS_SeamlessAPI_中文_v1.4.8》只在第 13 頁的 API 索引表列出本端點（`GET /games`、標註 rate limit），**未展開參數與回應結構**。以下欄位表、類型對照與語系對照皆**反推自 RD-Studio 舊指令 `GetPgsswGameList` 的實際實作**，未經三方規格書核對。要正式核對需取得 Merchant API 方向的文件（`api.md` 上方指的外部站）。

請求參數：

| 參數 | 來源 | 說明 |
|---|---|---|
| `merchantID` | `info.API_APPID` | 商戶代號 |
| `MerchantSecretKey` | `info.API_KEY` | ⚠️ **大寫開頭**。規格書通用約定寫的是小寫 `merchantSecretKey`（v1.4.8 p.303 定義、p.209 範例），未對本端點另作說明；大寫是舊指令與同家族 `target\Driver` 的實測值，沿用實測，勿逕自對齊文件 |

回應遵循規格書通用格式 `{ code, message, data }`（v1.4.8）：`code = 0` 為成功，非 0 見錯誤代碼對照表。HTTP status 依規格書為 `200 正常 / 400 參數與邏輯錯誤 / 500 伺服器錯誤`——本平台實作對 4xx、5xx 拋例外，對 `200 + code != 0` 回空清單。

回應 `data[]` 逐筆欄位與站內欄位對照：

| 三方欄位 | 站內欄位 | 說明 |
|---|---|---|
| `gameID` | `games.game_type` | 規格書其他端點標型別為 `int`（游戏代号），本平台轉字串儲存。唯一鍵，缺此欄的列一律略過，否則會以空字串互相覆蓋並讓差集比對誤停用既有遊戲。已知區段：`1000~9999` 舊版遊戲、`570001~570999` BWG 遊戲（v1.4.8 §5.9.1）、`570001~579999` 可能出現 `betAmount = 0`（§10.2.1） |
| `gameName` | `games.name` | |
| `imageUrl` | `games.image` | |
| `gameType` | `games.type_id` | 見下方類型對照 |
| `gameCode` | `games.remark` | 三方另一組人類可讀代碼，與 `gameID` 並存，供營運比對 |
| `isPrePaidMode` | `games.memo` | 預付／預扣款模式（捕魚類：進房先扣、退房返還），為真時寫入字串 `isPrePaidMode`；僅新增時寫入。規格書 §10.1.1 明確指出「要確認哪些遊戲屬於此模式請參考 4.6.1 的 `isPrePaidMode` 欄位」，並說明此類遊戲注單的 `betAmount`／`payoutAmount`／`validAmount` 語意與一般遊戲不同 |
| `gameNameMappings[].languageCode` / `.text` | `game_names` | 見下方語系對照 |

遊戲類型對照（三方 `gameType` → 站內 `type_id`）：

| 三方 | 三方說明 | 站內 |
|---|---|---|
| 0 | 無分類 | `EVENT_AND_OTHERS` (98) |
| 1000 | 老虎機 | `SLOTS` (2) |
| 2000 | 棋牌 | `TABLE` (100) |
| 2500 | 視訊 | `LIVE_CASINO` (1) |
| 3000 | 機台 | `ARCADE` (15) |
| 4000 | 角鬥場 | `ARCADE` (15) |
| 5000 | 彩票樂透 | `LOTTERY` (4) |
| 6000 | 柏青斯洛 | `ARCADE` (15) |
| 7000 | 捕魚 | `FISHING` (5) |

未列於對照表的分類碼一律落回 `EVENT_AND_OTHERS`。

> ⚠️ **上表未經三方規格書核對**，來自舊指令的註解。v1.4.8 只在回調 API 出現 `extraInfo.gameType`（標「游戏分类」、型別 number），**未附分類碼列表**。若三方新增分類，站內會落到「活動與其他」而非正確分類——需要外部站的 Merchant API 文件才能確認完整清單。

語系對照（三方 `languageCode` → `game_names.language_id`）：

| 三方 | 站內 `languages` |
|---|---|
| `en-us` | 1 `en` |
| `zh-tw` | 2 `zh-tw` |
| `zh-cn` | 3 `zh-cn` |

其餘語系略過不寫。

> ⚠️ **三方實際支援的語系清單未確認**。上表三種是舊指令認得的範圍，v1.4.8 沒有 `gameNameMappings` 的相關說明。若三方另有 th／vi 等語系，站內目前不會寫入（略過，不致出錯）。

> ⚠️ **RD-Studio 舊指令 `get_pgssw_games` 的語系對照是錯的**：它用 `['en-us' => 1, 'zh-cn' => 2, 'zh-tw' => 3]`，而 `languages` 表實際為 `2 = zh-tw`、`3 = zh-cn`，等於把簡繁名稱寫顛倒。本實作依 `LanguageEnum` 正確對照，是刻意偏離舊行為。

---

## target → 運營商回調 API（target 呼叫運營商實作端點）

> url 形式 `{VendorCode}/...`，`VendorCode` 為 url 路徑（PGSSW 用 `target`、BWG 用 `bwg`），與金鑰一同提供；本平台完整 path `external/api/sw/target/...`，對應 `routes/external/sw.php` 與 `PegasusController`。callback 前綴可於後台＞代理管理自設、**後綴固定**。每請求帶 `X-PROVIDER-IDENTITY`；HTTP 恆 200，成功以 `code` 為準。重複請求須冪等。

### 5.1.1 取得遊戲錢包金額（POST `{VendorCode}/players/wallet`）

- Request：`sessionToken`（string，用戶識別，來自 API 2.1.2）。
- Response：`balance`（decimal，僅小數兩位）。
- 錯誤碼：`401002`（身分不合法）/ `500001`。

### 5.2.1 玩家投注（POST `{VendorCode}/bet/placeBet`）

> 玩家投注、扣款、產生投注單號。⚠️ 運營商未正確回應或逾時 → target 立即呼叫取消投注（5.2.9）。我方一旦成功回應且該單未被取消，**帳務上即視為有效單**，不接受運營商片面取消。

- Request：`sessionToken` / `betAmount`（decimal，部分遊戲可能 0）/ `gameID`（int）/ `transactionNo`（string，投注/結算/取消共用同一單號）/ `playerUniqueID`（string）/ `extraInfo`（object，僅部分商戶）/ `extraInfo.roundID`（視訊局號）/ `extraInfo.gameType`（number，遊戲分類）。
- Response：`balance`（更新後餘額）/ `transactionNo`（**回遊戲商系統的投注單號、不回運營商的單號**，target 以此判斷是否正確回應）。
- 錯誤碼：`401002` / `403203`（餘額不足）/ `500029`（單號重複）/ `403601` / `500001`。

### 5.2.2 遊戲結算 — 一般投注（POST `{VendorCode}/bet/settle`）

> 輸或贏都觸發。逾時 / 異常數秒後 retry（最多 7 次），運營商須確保同一單號不重複派彩；超過 retry 用 3.1.2 查單。

- Request：`transactionNo`（與 5.2.1 匹配）/ `gameID` / `betAmount` / `taxAmount`（**已廢棄、請忽略**）/ `payoutAmount`（派獎金額，含投注額，加進玩家錢包）/ `validAmount`（有效流水）/ `settlementTime`（datetime，UTC+0）/ `playerUniqueID` / `sessionToken`（**勿在 settle 流程驗證 sessionToken 有效性 / 時效**，避免因此導致結算失敗）/ `extraInfo` / `extraInfo.isJackpot`（boolean，是否 Jackpot 彩金）/ `extraInfo.gameType` / `extraInfo.roundID`（視訊局號，其他遊戲類型為 null）。
- Response：`balance` / `transactionNo`（回遊戲商系統交易單號）。
- 錯誤碼：`500029` / `403601`（重複結算）/ `404016`（查無注單）/ `500001`。

### 5.2.5 活動獎勵派彩（POST `{VendorCode}/transaction/event-bonus`）

> 派發特殊活動獎勵至玩家錢包（紅包活動、捕魚每日獎勵）；數據報表獨立統計加總。逾時 retry，須冪等。

- Request：`transactionNo` / `amount`（decimal，**可能為 0，請勿拒絕 0 元資料**，避免雙方有效紀錄不一致）/ `playerUniqueID` / `eventDetailInfo`（object：`eventID` / `eventPrizeType` / `receiveTime`…）/ `eventDetailInfo.receiveTime`（帳務計算以此時間為基準）。
- Response：`transactionNo`（必要）/ `merchantOrderNo`（必要，運營商系統自訂單號）/ `beforeAmount`（更新前餘額）/ `afterAmount`（更新後餘額）。
- 錯誤碼：`500029` / `403601` / `403612` / `500001`。

### 5.2.9 取消投注（POST `{VendorCode}/bet/cancel`）

> 投注逾時 / 未得正確回應時呼叫；運營商確認有此注單則**退還金幣**。

- Request：`gameID` / `transactionNo` / `playerUniqueID` / `sessionToken`。
- Response：`transactionNo`。
- 錯誤碼：`403601` / `403612`（已是取消狀態）/ `404016` / `500001`。

### 5.3.1 結算改單（POST `{VendorCode}/bet/settle-change`）— ⚠️ **已廢棄**

> v1.4.8 標記**此 API 已廢棄**（原用於對已結算注單做數字調整，真人視訊較常見）。新接入不需實作；保留歷史欄位供既有實作參考：`after*` / `before*` 各欄位（BetAmount / PayoutAmount / ValidAmount / TaxAmount / WinLossAmount），`settlementTime` 與首次結算相同。

### 5.9.1 遊戲結算 — bonus 獎勵 / 免費遊戲（POST `{VendorCode}/bonus/settle`）— *選擇性*

> **非必要項目**。僅在運營商開通以下遊戲時需實作：GameID `1000~9999`（舊版遊戲）、`570001~570999`（BWG 遊戲）。**對接 target 品牌可忽略此 API；對接 BWG 品牌必須實作。**
> bonus 專屬單號，無 placeBet 投注行為（可視作 `betAmount=0` 的投注），但輸贏計算上仍視為一筆紀錄。逾時 retry，須冪等。

- Request：`transactionNo`（bonus 專屬）/ `gameID` / `betAmount`（一律 0）/ `taxAmount`（已廢棄）/ `payoutAmount` / `validAmount` / `settlementTime`（UTC+0）/ `playerUniqueID` / `sessionToken` / `extraInfo`。
- Response：`balance` / `transactionNo`。

---

## 其他進階 API（僅開放部分商戶）— Freespin API 系列

> ⚠️ 無縫錢包模式需先完成對接 **5.2.5 event-bonus API**；目前僅開放部分商戶；**僅 GameID `290001~290999` 有效**。freespin 帳務視為活動數據（event-bonus / event cost）。

- 建議調用順序：`/v1/event/FreespinSpin/betting-info`（取得可設置 freespin 的遊戲與可設置投注額）→ `/v1/event/FreespinSpin/Set`（指定特定玩家發送 freespin）。
- 完整規格見外部站 `https://example.invalid/api-document/freespin/{EN,CN}`。

---

## 遊戲特別補充

### 參數格式限制（#10.0.1）

`playerName` / `playerUniqueID`：限英文、數字，特殊符號僅可含 dash(`-`)、underline(`_`)；長度 **4~36 字**。

### 捕魚 / 魚機預扣模式（#10.1.1，查 4.6.1 `isPrePaidMode`）

進房**預先扣金幣**、退房返回；金額即時性高。注單欄位語意不同：`betAmount`=進房預扣、`payoutAmount`=退房返回、`validAmount`=房內實際發射子彈金幣。可能需實作 5.2.4 / 5.4.1 / 5.4.2（GameID `430000~430999`）。

### betAmount=0（#10.2.1）

GameID `570001~579999` 範圍部分遊戲 placeBet 可能 `betAmount=0`；實作 5.2.1 時**針對這些 gameID 不要阻擋 `betAmount=0`**。

### PayoutAmount（#10.2.5）

`payoutAmount` = **包含投注額**的輸贏金額，結算時整筆加進玩家錢包。百家樂例：投注 10 中獎（賠率 0.95X）→ `payoutAmount=19.5`、`WinLoss=9.5`；押錯 → `payoutAmount=0`、`WinLoss=-10`。

### 有效流水（#10.2.6，Live Casino 視訊）

有效流水 = valid turnover。視訊用輸贏流水作有效流水；對押情境流水扣為 0。**同局多次下注時，target 以「局」為單位將該局有效流水均分給每筆注單**（同局下注三次、總流水 600 → 每筆 200）。促銷派彩請用有效流水而非投注金額。3.1 game-records API 指定參數可額外回應局號與該局玩家總流水。

---

## 非必要開發 API 對應遊戲

| API | 對應 GameID |
|---|---|
| 5.9.1（bonus settle；附錄表仍標 5.2.3） | `1000~1999`、`390000~390999` |
| 5.2.4 / 5.4.1 / 5.4.2（捕魚預扣 / 充值 / 提款） | `430000~430999` |

> ⚠️ 文件自身編號不一致：bonus settle 於正文為 **5.9.1 `/bonus/settle`**，但附錄「非必要開發 API」表仍以舊編號 **5.2.3** 標示；以正文 5.9.1 路徑為準。

---

## Sources

- `PGS_SeamlessAPI_中文_v1.4.8.pdf`（v1.4.8，2026-03-27 RD），41 頁，PegasusSW 下載包，2026-06-26 讀取。部分 API（2.1.2 launch-link / 3.x 詳細參數 / Freespin）PDF 未展開，指向外部文件站 `http://example.invalid/api-document/singleWallet/{EN,CN}`、`/api-document/freespin/{EN,CN}`、編號對照 `/document/enums/{en,cn}`。
- 交叉驗證（我方實作）：`app/Vendors/PegasusSW/Driver.php`（`AbstractSingleWallet implements BonusCodeInterface`）、`OperatorStatusEnum.php`、`BetRecordStatusEnum.php`；`app/Http/Controllers/External/SingleWallets/PegasusController.php`；`routes/external/sw.php`；Models `PgsswAccount` / `PgsswBetRecord` / `PgsswCancelLog` / `PgsswEventRecord`。
- 版本沿革（節錄）：v1.2.3 event-prize 加 `merchantOrderNo`/`beforeAmount`/`afterAmount`；v1.2.8 callback 加 `extraInfo.roundID`；v1.3.9 Header 正名 `X-target-IDENTITY`→`X-PROVIDER-IDENTITY`；v1.4.0 MYR100 貨幣；v1.4.2 BWG 客戶必實作 5.2.3；v1.4.5 標記 rate limit API；v1.4.8 增進階 Freespin API。
