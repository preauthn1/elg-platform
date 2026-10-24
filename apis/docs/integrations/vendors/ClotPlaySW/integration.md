# ClotPlaySW（vendor_id=142）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless Wallet）— MOGI 介面 |
| 回調方向 | ClotPlay → 本平台（代理） |
| 驗證方式 | `Authorization` 固定 16 字元 token + IP 白名單（雙向相同 token） |
| 成功判定 | HTTP 200 且 `code=0` |
| 時區 | UTC+8（報表類；回調 `created_at`/`updated_at` 帶 ISO8601 時區偏移，轉存 UTC） |
| 共用 vendor ID | ClotPlaySW=**142**、ClotPlay P2P SW=**147**、ClotPlay Legend Slot SW=**213**（同規格） |
| API Host（主動呼叫） | `https://example.invalid/api/MOGI` |
| Controller | `app/Http/Controllers/External/SingleWallets/ClotPlayController.php` |
| Driver | `app/Vendors/ClotPlaySW/Driver.php` |
| 路由前綴 | `routes/external/sw.php` → prefix `clot` |

---

## 高風險陷阱

### 1. `cancel` 無重送機制（No Resend Policy）

官方明確標注 cancel 不支援重送（No Resend Policy）。與大多數廠商不同——若 ClotPlay 送 cancel 時超時，ClotPlay **不會再送第二次**，代理端需自行以 `Get Cancelled Transactions` API 定期（每 5 分鐘）主動比對，發現未回滾的回合手動處理。

**結果**：cancel 若 timeout，代理不會收到第二次通知，帳務缺口需主動偵測。

### 2. V1 `betSettlement` 已標 Deprecated

官方 V1（`betSettlement` 下注＋結算合一）已標 **Deprecated Soon**，正式規格為 V2（`bet` + `settlement` 分兩步）。APIs 專案目前同時掛 V1 + V2 路由（`sw.php`）。長期應確認 ClotPlay 端設定，並收斂到純 V2 接法。

### 3. `healthcheck` endpoint 路由缺失

V2 規範的 `healthcheck` callback（`POST /api/seamless/healthcheck`）在 `routes/external/sw.php` clot 群組目前**未登記路由**；若 ClotPlay 端啟用 healthcheck，會直接 404。屬已知缺口，需要時手動補路由。

### 4. 三個 vendor ID 共用同一套 Controller 邏輯

ClotPlaySW(142)、ClotPlay P2P SW(147)、ClotPlay Legend Slot SW(213) 共用相同 ClotPlay MOGI 規格，差異只在 `vendor_infos` 設定（agentCode、遊戲範圍）。`Common::verifyIPWhitelistByVendorId` 各 Controller 需分別傳入正確 vendorId（142/147/213）。

### 5. `settlement` 重送三次時的冪等性

官方規範 settlement timeout/HTTP error 最多重送 3 次。`bet`/`settle`/`betAndSettle` 當前以直接覆寫方式處理（未顯式以 `transaction_id` 作重複處理判斷），依 BaseController 差額機制達成最終一致。建議實測 settlement retry 場景確認不會重複加款。

---

## BetRecord 狀態機

ClotPlay V2 為兩步驟流程（bet → settlement），cancel 介入其間：

```
ClotPlay 送 bet callback
        │
        ▼
代理扣款 → BR 建立（bet_id = game_round_id）
        │
        ├─ bet 回應 code!=0 或 timeout ──▶ ClotPlay 觸發 cancel → 代理回滾 BR（settle_status=2）
        │
        ▼
ClotPlay 送 settlement callback
        │
        ▼
代理派彩 → BR 更新 bet/win/payout_time
        │
        ├─ settlement 回應 code!=0 ──────▶ ClotPlay 觸發 cancel → 代理回滾 BR
        │
        └─ settlement timeout/HTTP error → ClotPlay 重試最多 3 次
                │
                └─ 3 次仍失敗 → 標 Unsettled，等 Retry Unsettled Transactions API
```

**V1（betSettlement，Deprecated）**：bet+settle 合一，timeout 重試（非 cancel）；HTTP error 時 cancel。

---

## 冪等性

| 鍵 | 用途 |
|---|---|
| `game_round_id` | BR 的 `bet_id`；同 round 多次回調（multi-bet / 100-spins / buy free game）皆落在同一筆 BR |
| `settle_status==2` | cancel 重複防禦（已回滾則直接回餘額） |
| `transaction_id` | 官方識別單次交易，但當前 bet/settle 實作為直接覆寫，未以此做重複過濾 |

`cancel` 的 No Resend Policy 代表代理不需擔心 cancel 被重送——但也意味著第一次 cancel 若未成功，後續不會再來，需主動偵測。

---

## 整合落地建議

### vendor_infos 設定

| 鍵 | 說明 |
|---|---|
| `apiUrl` | `https://example.invalid/api/MOGI`（需向客服確認正式環境 URL） |
| `operatorToken` | ClotPlay 提供的 16 字元固定 token |
| `agentCode` | 代理代碼（Controller `vendorInfoOperatorField = 'agentCode'`） |
| `bonusConfigName` | Bonus Code config 名稱（需向 account manager 索取） |
| `rate` | 幣值換算比例（`Common::numDiv/numMul(..., rate, 4)`） |
| `currency` | 幣別代碼 |

### 三胞胎 vendor 對應表

| Vendor | vid | Controller | 路由 prefix |
|---|---|---|---|
| ClotPlaySW | 142 | `ClotPlayController` | `clot` |
| ClotPlay P2P SW | 147 | `ClotPlayP2PController` | `clotp2p` |
| ClotPlay Legend Slot SW | 213 | `ClotPlayLsController` | `clotls` |

### 落地檢核清單

- [ ] `vendor_infos` 設定：`apiUrl`、`operatorToken`(16字元)、`agentCode`、`bonusConfigName`、`rate`、`currency` 齊全。
- [ ] IP 白名單雙向：APIs outbound IP 申請進 ClotPlay；ClotPlay callback 來源 IP 設進 `verifyIPWhitelistByVendorId(142/147/213)`。
- [ ] V1 + V2 回調並存：betSettlement / bet / settlement / getBalance / cancel 五路由皆已註冊；確認 ClotPlay 端走 V1 或 V2。
- [ ] settlement retry 冪等：實測同 `game_round_id` 重複 settlement 時 `changeBalance` 差額計算是否冪等。
- [ ] healthcheck 路由：若 ClotPlay 啟用 healthcheck，補 `clot/api/seamless/healthcheck` 路由。
- [ ] 對帳定排：每 5 分鐘輪詢 `Get Cancelled Transactions`；計費交易 60 天保留期內完成拉取。
- [ ] 正式環境 URL：向 ClotPlay 客服確認 staging / production host。

---

## 對帳（拉注單乾跑）

Driver 實作 `SupportsBetRecordFetch`（**未**實作 `ShouldScheduleBetRecordFetch`，不進自動排程），
供 Olympus「拉取注單」頁與 `php artisan fetch_bet_record --vendor=142 --dry-run` 做三方 ↔ DB 比對。
對照舊系統 RD-Studio `php artisan clotsw_check_bet_record`。

| 項目 | 值 |
|---|---|
| 三方 endpoint | `GET {apiUrl}/get-transaction-details`（`updated_at_from` / `updated_at_to`，UTC+8 字串） |
| 單次查詢窗 | 30 分鐘（切片由 `BetRecordFetchService` 負責，結束端點退 1 秒避免重疊） |
| 頻率限制 | 10 req/min（`betRecordApiRateLimit()`） |
| 可回溯 | 60 天（計費交易保留期） |
| 憑證鍵 | `operatorToken`（API 不吃 `agent_code`，回傳範圍由 token 決定） |
| 回合聚合 | 三方以 transaction 為單位，同 `game_round_id` 多筆先加總再比對（DB 一回合一列） |

乾跑輸出三份清單：**新增**（三方有、DB 沒有＝缺單）、**更新**（金額不一致，需搭配 `--update`）、
**三方查無**（DB 有、三方整個窗口沒回的反向提示——對應舊系統 `ClotSwCheckBetRecord` 的「三方沒有找到投注紀錄」）。
反向清單以 `payout_time` 界定窗口，邊界前後可能誤報（DB 派彩時間在窗口內、三方 `updated_at` 落在窗口外），
窗口放寬一小時重跑即可排除；指定單一商戶（`--vendor-info`）時不做反向對帳（注單表沒有商戶欄位，無法排除其他商戶的列）。

> 🔒 **單一錢包只能乾跑**：注單已由 seamless 回調即時記帳，寫入模式會生出沒有錢包異動的孤兒注單。
> `BetRecordFetchService::fetchChunk()` 對 `vendors.is_single_wallet = 1` 直接拋 `LogicException`，
> Olympus 頁面則鎖住「乾跑模式」開關並在送出時強制帶 `--dry-run`。

> ⚠️ `/get-transaction-details` 的回應欄位廠商文件未載明。`total_bet_amount`、`winning`、`game_round_id`
> 由 RD-Studio 舊碼可證；`player_unique_id`、`game`、`created_at`、`updated_at` 依回調同名欄位推測，
> 取不到時留空（金額比對不受影響）。拿到實際回應樣本後應回頭校正 `formatBetRecord()`。

---

## Gaps

| # | 項目 | 說明 |
|---|---|---|
| 1 | healthcheck 是否需實作 | 路由缺；何種代理會被主動 healthcheck 文件未說明 |
| 2 | cancel timeout 後的帳務缺口偵測 | No Resend Policy 代表需主動偵測，輪詢頻率與告警機制需落地定義 |
| 3 | 正式環境 Base URL | 文件只給 `api-int`（疑 integration），正式 host 需向客服確認 |
| 4 | settlement retry 冪等實測 | 理論上 BaseController 差額機制可覆蓋，但尚未實測重送 3 次場景 |
| 5 | V1 停止時程 | 官方標「Deprecated Soon」但未給具體日期，需追蹤 |
| 6 | `/get-transaction-details` 回應欄位 | 廠商文件未載明，現以回調同名欄位推測（見「對帳」章節） |
