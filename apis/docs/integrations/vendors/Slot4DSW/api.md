# Slot4D — 三方 API 規格（api.md）

> **本檔性質**：廠商原文規格 SSOT（廠商說什麼，照搬不詮釋）。實作 Driver / Controller 一律以本檔為規格依據。
> 接入時踩到的坑、文件與實作的已驗證落差請另記 `integration.md`（本目錄），不要改寫本檔。
>
> **來源**：Google Docs `Slot4D Integration Documentation Simplified Chinese.docx`（文檔最新版本 v3.5.4，2025-08-11）。
> 原文為簡體中文，本檔標題與導讀為繁體，**規格值（參數名、型別、簽名串、狀態碼）照搬原文**。
>
> **接入範圍**：Slot4D 同一份文檔涵蓋三套 API — Seamless Wallet（無縫錢包）、Fund Transfer Wallet（資金轉帳錢包）、Integration（集成）。**我方採單一錢包（Seamless）接入**，故本檔完整收錄 Seamless callback 與必要 Integration API（GameLogin / GetGameList），**Fund Transfer 全套（原文第 4 章）不納入**；如日後需要轉帳版另補。

---

## 0. 架構視角（務必先讀，避免主客體顛倒）

原文以「**運營商**」與「**提供商 / 供應商**」二詞描述雙方：

| 原文用詞 | 實體 | 在我方系統的角色 |
|---|---|---|
| 運營商（Operator） | **我方平台（APIs）** | seamless callback 的**被呼叫方**（實作 Controller）；Integration API 的**呼叫方**（Driver） |
| 提供商 / 供應商（Provider） | **Slot4D 遊戲商** | seamless callback 的**呼叫方**；Integration API 的**被呼叫方** |

因此：

- **Seamless callback**（`GetBalance` / `Bet` / `GameResult` / `Rollback` / `CashBonus` / `Jackpot` / `BetNSettle`）：**Slot4D 打進我方**。我方在 `routes/external/sw.php` 提供 endpoint，於 Controller 實作，回應碼用 **§7.3.2 運營商狀態碼**。
- **Integration API**（`GameLogin` / `GetGameList` / `KickPlayer` / `SettleRound` / …）：**我方打給 Slot4D**（Driver `sendRequest`），Slot4D 回應碼用 **§7.3.1 提供商狀態碼**。

---

## 1. 通用約定

### 1.1 傳輸與 URL

- 所有請求與回應皆為 **JSON**。
- 請求標頭需含 `Content-Type: application/json`，HTTP 方法一律 **POST**（除演示 `GET GameList` / `GET LaunchGame` 外）。
- 提供商為「生產」與「測試」環境各自提供 **OperatorId** 與 **SecretKey**。
- 提供商 HTTPs 服務 URL 格式（我方 → Slot4D 的 Integration / Fund Transfer 端點）：
  ```
  https://<API service domain>/api/opgateway/v1/op/<method>
  https://<API service domain>/api/opgateway/v1/op/<path>/<method>
  https://<API service domain>/api/opgateway/v1/op/<path>/<path>/<method>
  ```
- 集成 HTTP 服務受安全保護，**不應從玩家瀏覽器（終端用戶）發起請求**；需向提供商提供 IP 位址做**白名單**設定。

### 1.1.1 Slot4D 出站回調來源 IP（我方須加白名單）

原文（無縫錢包章節）：「服務請求僅來自供應商伺服器，通過多個代理機器傳輸。請根據特定的環境允許供應商的出站 API 請求到達運營商系統，白名單如下」：

| 環境 | IP |
|---|---|
| UAT | `0.0.0.0` |
| PROD | `0.0.0.0` |
| PROD | `0.0.0.0` |
| PROD | `0.0.0.0` |

> 對接用途：這組 IP 即我方 `Common::verifyIPWhitelistByVendorId()`（vendor 254）IP 白名單的配置依據；反向的「我方 IP 提供給 Slot4D 加白」另行提供。

### 1.2 玩家 ID（PlayerId）

- PlayerId 是**運營商系統內用戶的唯一識別碼**，在發任何遊戲相關請求給提供商前，運營商必須先建立並儲存。
- 若玩家在提供商系統不存在，將依運營商送出的資料（透過 `CreatePlayer` / `GameLogin` 請求）**自動建立帳號**；已存在則以請求資料更新。
- **字元限制 50**。允許字元：字母數字與正常符號，**不允許空格**：`A-z`、`A-Z`、`0-9`、`. _ $ - ?`。

### 1.3 遊戲環節（Game Session）

- **1.5.1 下注與遊戲結果**：每個游戲回合包含一個下注、遊戲結果或下注退款的獨立實例。
- **1.5.2 交易 ID**：下注、遊戲結果、現金獎金、大獎所用的交易 ID，在提供商整個遊戲系統中**保持唯一**。
- **1.5.3 驗證令牌（AuthToken）**：運營商可在 `GameLogin` 傳入 `AuthToken`，透過回調實現會話控制。AuthToken **僅透過 `GetBalance`、`Bet` 回調傳回**運營商。備註：此僅適用於無縫運營商；資金轉帳可改用 `KickPlayer` API。

### 1.4 資料保留政策（1.6）

- **即時交易**：交易保留 **30 天**；**未完成 / 未結算的回合將在 1 小時後自動結算**。
- **操作員日誌提取**：日誌提取交易資料保留 **60 天**；逾期資料歸檔，後台不再可見。

### 1.5 回調響應時間

> **請確保運營商回調響應時間少於 3 秒**，以確保最佳遊戲性能。

---

## 2. 簽名機制（MD5）

### 2.1 演算法

`Signature` = **MD5 十六進位小寫字串**，由若干欄位**依序字串拼接**後取 MD5（UTF-8）。拼接**無分隔符**。

PHP 參考實作（原文 6.3）：

```php
<?php
$functionName    = "GameLogin";
$requestDateTime = "2023-06-09 12:34:56";
$operatorId      = "op1";
$secretKey       = "ABC123";
$playerId        = "player123";
// Concatenate the values
$stringToHash = $functionName . $requestDateTime . $operatorId . $secretKey . $playerId;
// Generate the MD5 hash
$hash = md5($stringToHash);   // 十六進位小寫
echo $hash;
```

- `FunctionName` = 該 API 的方法名（如 `GameLogin`、`Bet`、`GameResult`…）。
- `RequestDateTime` 用當前 **UTC+0** 時間，**專供簽名/驗證，勿用於交易記錄**。格式視 API 而定（多數 `yyyy-MM-dd HH:mm:ss`，部分 `yyyy-MM-ddTHH:mm:ssZ`）。
- `SecretKey` 為提供商核發之密鑰。

### 2.2 各 API 簽名串（拼接順序）

| API | 簽名串（依序拼接） |
|---|---|
| GetGameList | `FunctionName + RequestDateTime + OperatorId + SecretKey` |
| GameLogin | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| GetBalance | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **Bet** | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **GameResult** | `FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **Rollback** | `FunctionName + BetId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **CashBonus** | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **Jackpot** | `FunctionName + TranId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| **BetNSettle** | `FunctionName + ResultId + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| KickPlayer / GetUnfinishRound / CancelRound / SettleRound | `FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId` |
| GetRoundDetails | `FunctionName + RequestDateTime + OperatorId + SecretKey`（**無 PlayerId**） |

> ⚠️ Bet / GameResult / Rollback / CashBonus / Jackpot / BetNSettle 的簽名串**在 `FunctionName` 後多插一個交易 ID**（Bet/Rollback→BetId、GameResult/BetNSettle→ResultId、CashBonus/Jackpot→TranId）。這是驗簽最易錯處。

---

## 3. Integration API（我方 → Slot4D）

### 3.1 POST GetGameList（可選）

Request path: `POST /GetGameList` — 取得可存取的遊戲集合。

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime `yyyy-MM-dd HH:mm:ss` | 必需 |
| Signature | String(200) | MD5(FunctionName + RequestDateTime + OperatorId + SecretKey) | 必需 |
| Lang | String(50) | Language code (ISO 639-1)，預設 `en-us` | 可選 |
| Currency | String(50) | Currency code，未指定則用預設 | 可選 |

**回應參數**：`Status`(Integer)、`Description`(String50)、`ResponseDateTime`(String50)、`Game`(Array)

**Game 物件**：`GameCode`(String50 唯一遊戲代碼)、`GameType`(Integer，見 §7.4)、`GameName`(String50 英文名)、`ImageUrl`(String100)、`HasDemo`(Bool)

回應範例：
```json
{"Status":200,"Description":"Success","ResponseDateTime":"2016-09-01 09:41:37","Game":[{"GameCode":"10000","GameName":"Game name 001","GameType":1,"ImageUrl":"https://<base-domain-url>/thumbs/Web/10000.png","HasDemo":true},{"GameCode":"10001","GameName":"Game name 002","GameType":1,"ImageUrl":"https://<base-domain-url>/thumbs/Web/10001.png","HasDemo":false}]}
```

### 3.2 POST GameLogin（必需 — 取遊戲啟動 URL）

Request path: `POST /GameLogin`

**請求參數**

| 名稱 | 類型 | 描述 | 狀態 |
|---|---|---|---|
| OperatorId | String(20) | Operator Id | 必需 |
| RequestDateTime | String(50) | UTC datetime `yyyy-MM-dd HH:mm:ss` | 必需 |
| Signature | String(50) | MD5(FunctionName + RequestDateTime + OperatorId + SecretKey + PlayerId) | 必需 |
| PlayerId | String(50) | 運營商玩家用戶名（字母數字，允許 `. _ $ - ?`，不允許空格） | 必需 |
| Ip | String(50) | 玩家 IP 位址，**僅支援 IPv4** | 必需 |
| GameCode | String(50) | 遊戲唯一代碼（傳 `0` 回大廳網址而非遊戲網址） | 必需 |
| Currency | String(5) | 幣別代碼（見 §7.2） | 必需 |
| Lang | String(5) | 玩家語言 ISO 代碼（見 §7.1，eg `en-us`） | 可選 |
| RedirectUrl | String(100) | 返回大廳按鈕重定向 URL | 可選 |
| AuthToken | String(500) | 驗證玩家遊戲會話用；加入後會透過回調（GetBalance/Bet）傳回 | 可選 |
| DisplayName | String(50) | （已棄用）玩家顯示名稱 | 可選 |
| PlayerBalance | Decimal | （已棄用）玩家當前餘額 | 可選 |
| LaunchDemo | Bool | 標記此登入回傳演示啟動 URL，預設 `false` | 可選 |

**回應參數**：`Status`、`Description`、`ResponseDateTime`、`Url`(String2000 遊戲啟動網址)

回應範例：
```json
{"Status":200,"Description":"Success","ResponseDateTime":"2016-09-01 09:41:37","Url":"https://<game-url>.com/slot/g/?p=2fht3VYQgXKMaUqxvomKPuz&rd="}
```

### 3.3 管理類 Integration API（可選，依需求實作）

以下請求皆含 `OperatorId` / `RequestDateTime` / `Signature`（簽名串見 §2.2）。

| API | Path | 用途 | 額外請求欄位 | 主要回應 |
|---|---|---|---|---|
| KickPlayer | `POST /KickPlayer` | 暫時禁止玩家繼續遊戲（下個會話仍可登入） | `PlayerId` | Status/Description/ResponseDateTime |
| GetUnfinishRound | `POST /GetUnfinishRound` | 取回玩家未完成回合列表（無結果之回合） | `PlayerId` | `Rounds[]`：`RoundId`,`GameId`,`GameName`,`Turnover` |
| CancelRound | `POST /CancelRound` | 取消玩家持有的回合（退還下注、結束回合、踢出玩家）。**僅回合 ID 建立後 5 分鐘內可取消**；已結算回合不可取消 | `PlayerId`,`RoundId` | Status/Description/ResponseDateTime |
| SettleRound | `POST /SettleRound` | 結算玩家進行中的回合（彙總當前進度為派彩並結束回合）。**僅回合仍進行且運營商接受投注時受理** | `PlayerId`,`RoundId` | Status/Description/ResponseDateTime |
| GetRoundDetails | `POST /GetRoundDetails` | 取回特定回合詳情（是否結算/作廢） | `RoundId`(Long),`GameCode`（簽名**無 PlayerId**） | `HasSettle`,`HasVoid`,`RoundDetails{RoundId,RoundTranDT,ResultId,ResultTranDT,PlayerId,GameCode,BetAmount,Payout,ProviderTimeZone,ProviderRoundTranDT,ProviderResultTranDT}` |

### 3.4 其他可選 Integration API（本次未展開）

以下原文有定義，單一錢包核心接入非必要，需要時再依原始文檔補：
`GetTransactionDetails`(2.1.3)、`GetDaySummary`(2.1.4)、演示 `GET GameList`/`GET LaunchGame`(2.2)、免費回合系列 `CreateFreeRound`/`CancelFreeRound`/`GetFreeRoundInfoByPlayer`/`GetFreeRoundGames`/`GetFreeRoundDetails`/`GetFreeRoundInfoByOperator`(2.3)。

---

## 4. Seamless Wallet Callback API（Slot4D → 我方）

> 以下端點由 **Slot4D 呼叫我方**。所有回應碼用 **§7.3.2 運營商狀態碼**。所有請求含 `OperatorId` / `RequestDateTime` / `Signature`（簽名串見 §2.2）。
> **幂等性**：Bet/GameResult/Rollback/CashBonus/Jackpot/BetNSettle 皆為幂等 — 相同交易 ID 再次送達**只應建立一筆交易**，運營商系統**不得重複處理**同一交易 ID。

### 4.1 POST GetBalance — 查詢玩家餘額

**請求**：`PlayerId`、`Currency`、`AuthToken`（+ 通用三欄）
**回應**：`Status`、`Description`、`ResponseDateTime`、`Balance`(Decimal，例 `1233.5000`)

### 4.2 POST Bet — 扣款下注

檢查玩家資金是否充足並從餘額扣除，回傳更新後餘額。**幂等鍵：BetId**。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| PlayerId | String(50) | 運營商系統玩家 ID |
| Currency | String(5) | 幣別（§7.2） |
| RoundId | String(50) | 遊戲回合唯一 ID，**多個玩家可共享同一 RoundId** |
| BetId | String(50) | 提供商系統下注交易唯一 ID（**用此確保交易唯一性**） |
| GameCode | String(50) | 遊戲唯一代碼 |
| GameType | String(50) | 字串遊戲類型（eg Slots / Arcade） |
| BetAmount | Decimal | 下注金額（**用此值更新玩家餘額**） |
| ExchangeRate | Decimal | 匯率（eg 1.0000） |
| TranDateTime | DateTime | 提供商系統實際交易 UTC 時間 `yyyy-MM-dd HH:mm:ss`（實際下注時間） |
| AuthToken | String(500) | 玩家會話驗證（GameLogin 傳入時附帶） |
| ProviderTimeZone | String | 供應商側時區 |
| ProviderTranDt | DateTime | 基於供應商時區的實際交易時間 |

**回應**：`Status`、`Description`、`ResponseDateTime`、`OldBalance`(下注前餘額)、`NewBalance`(扣款後餘額)

### 4.3 POST GameResult — 遊戲結果 / 結算派彩

回合結束後將派彩加到玩家餘額，回傳更新後餘額。**幂等鍵：ResultId**。

**請求參數**（在 Bet 基礎上）

| 名稱 | 類型 | 描述 |
|---|---|---|
| ResultId | String(50) | 結果交易唯一 ID（**用此確保唯一性**；可用於 GetTransactionDetails） |
| BetId | String(50) | 對應下注交易 ID |
| RoundId | String(50) | 回合 ID（多玩家可共享） |
| GameCode / GameType / GameName | String | 遊戲代碼 / 類型 / 英文名 |
| ResultType | Integer | 遊戲結果類型（§7.5） |
| BetAmount | Decimal | 下注金額 |
| ValidBetAmount | Decimal | 有效下注金額 |
| Payout | Decimal | 派彩金額（無派彩則為 `0`；**用此值更新玩家餘額**） |
| WinLose | Decimal | 輸贏金額（Payout − 投注額），**可為負值** |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 提供商系統實際交易 UTC 時間 |
| ProviderTimeZone / ProviderTranDt | String / DateTime | 供應商時區 / 對應時間 |
| RoundType | Integer | 輪次類型（§7.7） |

**回應**：`Status`、`Description`、`ResponseDateTime`、`OldBalance`、`NewBalance`

### 4.4 POST Rollback — 取消投注（退款）

未完成遊戲取消下注時退還玩家餘額。**幂等鍵：BetId**。

**請求參數**（同 Bet，另加）：`RollbackType`(Integer，§7.6)。欄位：`PlayerId`、`Currency`、`RoundId`、`BetId`、`GameCode`、`GameType`、`BetAmount`（用此值更新餘額）、`ExchangeRate`、`TranDateTime`、`RollbackType`、`ProviderTimeZone`、`ProviderTranDt`。

**回應**：`Status`、`Description`、`ResponseDateTime`、`OldBalance`、`NewBalance`

### 4.5 POST CashBonus — 現金獎金派發

活動 / 比賽 / 獎金派發，增加玩家餘額。**幂等鍵：TranId**。`RequestDateTime` 格式為 `yyyy-MM-ddTHH:mm:ssZ`。

**請求參數**

| 名稱 | 類型 | 描述 |
|---|---|---|
| PlayerId | String(50) | 玩家 ID |
| Currency | String(5) | 幣別 |
| TranId | String(25) | 交易唯一 ID |
| BonusId | String(50) | 獎金代碼（代表不同獎金類型） |
| BonusName | String(50) | 獎金名稱 |
| BonusType | String(50) | 獎金類型（§7.9） |
| Payout | Decimal | 獎金金額（**用此值更新玩家餘額**） |
| ExchangeRate | Decimal | 匯率 |
| TranDateTime | DateTime | 提供商系統實際交易 UTC 時間 |
| Result | String(100) | 現金獎金結果字串（§8.0） |
| ProviderTimeZone / ProviderTranDt | String / DateTime | 供應商時區 / 對應時間 |

**回應**：`Status`、`Description`、`ResponseDateTime`、`OldBalance`、`NewBalance`

### 4.6 POST Jackpot — 大獎派發

通知玩家中獎金額，增加餘額。**幂等鍵：TranId**。

**請求參數**：`PlayerId`、`Currency`、`TranId`(String25)、`JackpotId`(String50)、`Payout`(Decimal，用此更新餘額)、`ExchangeRate`、`TranDateTime`、`Result`(String100)、`ProviderTimeZone`、`ProviderTranDt`。

**回應**：`Status`、`Description`、`ResponseDateTime`、`OldBalance`、`NewBalance`

### 4.7 POST BetNSettle / CancelBetNSettle（arcade / fishing，bet+settle 合一）

`POST /BetNSettle`：回合結束後將派彩加到餘額（**投注與結算合一**）。**幂等鍵：BetId**。簽名串用 **ResultId**。請求欄位為 Bet + GameResult 之聯集（`ResultId`,`BetId`,`RoundId`,`GameCode`,`GameType`,`GameName`,`ResultType`,`BetAmount`,`ValidBetAmount`,`Payout`,`WinLose`,`ExchangeRate`,`TranDateTime`,`ProviderTimeZone`,`ProviderTranDt`,`RoundType`）。

> `BetNSettle` / `CancelBetNSettle`(3.2.8) 主要用於 Fish Arcade 類單次投注即結算場景。**Slot（老虎機）流程以 Bet + GameResult 為主**；若 Slot4D 老虎機不觸發 BetNSettle，本次可暫不實作，接入時以實測為準（有落差記入 `integration.md`）。

### 4.8 無縫投注流程（3.3）

1. 玩家下注 → 提供商系統呼叫 `GetBalance` 驗證資金是否充足。
2. 資金充足 → 提供商觸發 `Bet` 扣款。
3. 扣款成功 → 提供商產生結果並以 `GameResult` 與運營商結算。
4. 玩家在畫面查看結果。

運營商系統須：以各 API 指定欄位**準確更新餘額**；保證**幂等調用不被多次處理**（用交易 ID 確認唯一性）；**回調響應 < 3 秒**。

---

## 5. 對帳定義（原文第 5 章）

- **5.1 投注交易**：下注失敗時提供商發起回滾；此情況下**投注交易不套用對帳流程**。
- **5.2 回滾交易**：重試 3 次仍未從運營商收到適當回應 → 回滾請求進後台交易佇列，**對帳流程由此開始**；運營商另每小時定期獨立收到回滾請求。運營商可回「回滾成功」或錯誤（如「找不到交易」）表示已終止該交易。
- **5.3 遊戲結果交易**：結果 / 結算交易到達運營商遇困難時，系統重試 3 次後路由到對帳流程。**已結算交易不可逆、不能取消**；提供商每小時持續重試直到成功交付。
- **5.4 獎金與彩金交易**：重試機制同遊戲結果交易。

> **對帳防重**：運營商在對帳重試場景，須確保**相同參考 ID / 交易 ID 的交易不被多次處理**，可用適當錯誤碼停止對帳流程。

---

## 6. vendor_infos.info 憑證欄位（依 api.md 命名）

> 依整合手冊 §5：key 命名跟廠商參數名走。

| Key | 對應廠商參數 | 說明 |
|---|---|---|
| `API_URL` | —（base URL） | Slot4D API base：`https://<domain>/api/opgateway/v1/op/`（必填，所有 vendor 通用） |
| `OPERATOR_ID` | OperatorId | 提供商核發之運營商 ID |
| `SECRET_KEY` | SecretKey | 簽名密鑰（**正式環境必填**；空字串會使驗簽被跳過） |
| `CURRENCY` | Currency | 幣別代碼（與 `vendor_infos.currency` 一致，見 §7.2） |

---

## 7. 附錄

### 7.1 支援的語言代碼

| 語言 | 代碼 |
|---|---|
| English | en-us |
| Chinese | zh-cn |
| Thai | th-th |
| Burmese/Myanmar | my-mm |
| Khmer | km-kh |
| Indonesia | id-id |
| Korean | ko-kr |
| Chinese (Trad) | zh-tw |

### 7.2 支援的貨幣代碼

MYR(馬來西亞林吉特)、THB(泰銖)、SGD(新加坡元)、USD(美元)、VND(越南盾)、VNK(越南盾 1 VNK:1000 VND)、IDR(印尼盾)、IDK(印尼盾 1 IDK:1000 IDR)、MMK(緬甸元)、MK1(1 MK1:100 MMK)、MKK(1 MKK:1000 MMK)、HKD、BDT、RMB、INR、PKR、AUD、KRW、KRK(1 KRK=100 KRW)、LAK、LKK(1 LKK=1000 LAK)、NPR、PHP、TWD、KHR、KHK(1 KHK=1000 KHR)、CAD、BRL、NGN、BND、EUR、JPY、GBP、SEK、NOK、DKK、AED、CHF、LKR、NZD、RUB、TRY、ZAR、MXN、FUN(演示貨幣)、USDT、MBT(Milli-Bitcoin)、CLP、CZK、UZS、XOF、AZN、XAF、IRR、ARS、GHS、ZMW、PEN、UGX、AMD、CDF、COP、TJS、TZS、PYG。

> 特殊幣別請洽提供商團隊。

### 7.3 狀態 / 錯誤代碼

#### 7.3.1 提供商狀態代碼（Slot4D 回給我方 — Integration/Fund Transfer 回應）

| 代碼 | 描述 |
|---|---|
| 200 | OK |
| 400 | 錯誤請求 |
| 403 | 禁止訪問 |
| 503 | 服務維護 |
| 900300 | 不符合資格 |
| 900401 | 無效的運營商 ID |
| 900402 | 無效的玩家 |
| 900403 | 無效簽名 |
| 900404 | 無效的遊戲 ID |
| 900408 | 無效的 TranID |
| 900500 | 內部伺服器錯誤（列表外任何其他錯誤/情況） |

#### 7.3.2 運營商狀態代碼（**我方 seamless callback 回給 Slot4D** — Controller errorResponse 依據）

| 代碼 | 描述 |
|---|---|
| 200 | OK |
| 900404 | 無效的玩家 / 密碼，請重試 |
| 900405 | 運營商 ID 錯誤 |
| 900406 | 傳入請求資訊不完整 |
| 900407 | 無效的簽名（Invalid Signature） |
| 900409 | 重複交易 |
| 900415 | 找不到投注交易 |
| 900416 | 玩家不活躍 |
| 900605 | 餘額不足 |
| 900500 | 內部伺服器錯誤 |
| 800401 | 已達到最大派彩（運營商可用此碼表示玩家已達平台最大贏取限制） |

### 7.4 遊戲類型（GameType）

| 值 | 類型 |
|---|---|
| 0 | 其他 |
| 1 | 老虎機 |
| 2 | 街機 |
| 3 | 桌上 |
| 4 | 活動 |
| 5 | 迷你遊戲 |
| 6 | 捕魚街機 |
| 101 | 現金獎勵（已棄用） |

### 7.5 比賽結果類型（ResultType）

| 值 | 描述 |
|---|---|
| 0 | 單次 / 快速遊戲回合結果 |

### 7.6 回滾類型（RollbackType）

| 值 | 描述 |
|---|---|
| 1 | 單次 / 快速遊戲退款 |

### 7.7 輪次類型（RoundType）

| 值 | 描述 |
|---|---|
| 0 | 正常回合 |
| 1 | 購買免費旋轉 / 特別回合 |

### 7.8 交易類型

| 值 | 描述 |
|---|---|
| 0 | 基礎 |
| 2 | 獎勵 |

### 7.9 獎金類型（BonusType）

| 值 | 描述 |
|---|---|
| 0 | None |
| 2 | Angpao |
| 3 | Event |
| 4 | FreeRound |

### 8.0 結果（Result 欄位格式）

| 情況 | Result 字串格式 |
|---|---|
| 4（FreeRound） | `gameName\|minLineBet\|noOfLine\|betValue\|gameId\|freeRoundId` |
| 其他 | `gameName\|minLineBet\|noOfLine\|betValue` |

---

## 附：接入層級判定線索（供設計參考，非廠商原文）

> 以下非規格照搬，是從原文歸納的**設計輸入**，正式設計時再確認：

- 原文 1.5.1 明言「每個遊戲回合包含一個下注、遊戲結果或下注退款的**獨立實例**」，且 `Bet.BetId`、`GameResult.ResultId` 各自唯一、`RoundId` 多玩家可共享 → 傾向 **Transaction 層級 BR**（一 round 對應一 Bet + 一 GameResult）。
- 待接入時以實測確認：同一 RoundId 是否會有多筆 Bet（multi-bet），若會則需依手冊 §3.3.1 累加。落差記入 `integration.md`。
