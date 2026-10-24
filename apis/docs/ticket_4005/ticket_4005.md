# 未遷移清單

## 1. Vjackpot（5 個）- 階段１ 

| Endpoint                                  | 功能              | 新Endpoint |
| ----------------------------------------- | --------------- | --- |
| `api/event/lucky-draw/issues`             | Vjackpot－抽獎期數列表 | `frontend/api/event/lucky-draw/issues` |
| `api/event/lucky-draw/issues/detail`      | Vjackpot－期數明細   | `frontend/api/event/lucky-draw/issues/detail` |
| `api/event/lucky-draw/issues/tickets`     | Vjackpot－我的票券   | `frontend/api/event/lucky-draw/issues/tickets` |
| `api/event/lucky-draw/issues/receive`     | Vjackpot－領獎     | `frontend/api/event/lucky-draw/issues/receive` |
| `api/event/lucky-draw/issues/receive-all` | Vjackpot－一鍵領獎   | `frontend/api/event/lucky-draw/issues/receive-all` |

---

## 2. Wjackpot（10 個）- 階段１ 

| Endpoint                                                                       | 功能             | 新Endpoint |
| ------------------------------------------------------------------------------ | -------------- | --- |
| `api/event/wjackpot/issues`（+`/detail`、`/tickets`、`/receive`、`/receive-all`）   | Wjackpot－彩金    | `frontend/api/event/wjackpot/issues`（+`/detail`、`/tickets`、`/receive`、`/receive-all`） |
| `api/event/wjackpot6d/issues`（+`/detail`、`/tickets`、`/receive`、`/receive-all`） | Wjackpot－彩金 6D | `frontend/api/event/wjackpot6d/issues`（+`/detail`、`/tickets`、`/receive`、`/receive-all`） |

---

## 3. 邀請活動（2 個）- 階段2 

| Endpoint                      | 功能   | 新Endpoint |
| ----------------------------- | ---- | --- |
| `api/event/getNewInviteEvent` | 邀請活動 | `frontend/api/event/new-invite` |
| `api/event/getNewInvitePrize` | 邀請獎品 | `frontend/api/event/new-invite/receive` |

---

## 4. 活動列表／領獎（11 個）- 階段2 

| Endpoint                 | 功能      | 新Endpoint |
| ------------------------ | ------- | --- |
| `api/events`             | 舊版活動列表  | `frontend/api/event/legacy-list` |
| `api/eventsByDomain`     | 舊版活動列表  | `frontend/api/event/legacy-list-by-domain`（免登入） |
| `api/cancelPrize`        | 取消獎品    | `frontend/api/prize/cancel` |
| `api/event`              | 活動詳情    | `frontend/api/event/legacy-detail` |
| `api/getAllPrize`        | 取得獎品    | `frontend/api/prize/draw-all` |
| `api/getPrize`           | 取得獎品    | `frontend/api/prize/draw` |
| `api/getPrizeByDetailId` | 依明細取得獎品 | `frontend/api/prize/draw-by-detail` |
| `api/setPrize`           | 設定獎品    | `frontend/api/prize/set` |
| `api/choosePrize`        | 選擇獎品    | `frontend/api/prize/choose` |
| `api/event/validate`     | 活動驗證    | `frontend/api/event/legacy-validate` |
| `api/prizes`             | 獎品清單    | `frontend/api/prize/list` |

---

## ~~5. 登入活動（5 個，廢除）~~

> **PM 已確認（2026-08-03）：整組不需要遷移**，新版前端不使用登入活動功能。
> 其中 `getLoginEvent` / `getLoginEventByDomain` 曾短暫遷移完成，確認後已整段移除（Service／Controller／FormRequest／routes／測試）；`joinLoginEvent` / `unlockLoginEvent` / `checkLoginEvent` 未動工即取消規劃。
> `app/Models/LoginEvent` 保留不動——該 Model 於本次遷移前即存在，`LoginService::prepareResponseData()`（登入回應組裝）仍會用到。
> 詳見 `api-frontend-migration-4005.md` 第 7 章與 7-1。

| Endpoint                        | 功能         |
| ------------------------------- | ---------- |
| ~~`api/joinLoginEvent`~~        | ~~參加登入活動~~ |
| ~~`api/unlockLoginEvent`~~      | ~~解鎖登入活動~~ |
| ~~`api/checkLoginEvent`~~       | ~~檢查登入活動~~ |
| ~~`api/getLoginEvent`~~         | ~~取得登入活動~~ |
| ~~`api/getLoginEventByDomain`~~ | ~~取得登入活動~~ |

---

## 6. 遊戲商活動（APP）（1 個）- 階段3 

| Endpoint                | 功能    | 新Endpoint |
| ----------------------- | ----- | --- |
| `api/event/vendorGames` | 遊戲商活動 | `frontend/api/event/vendor-games` |

---

## 7. 老虎機競賽（5 個）- 階段3 

| Endpoint                                  | 功能   | 新Endpoint |
| ----------------------------------------- | ---- | --- |
| `api/event/slot_competition/list`         | 競賽列表 | `frontend/api/event/slot-competition/list` |
| `api/event/slot_competition/leader_board` | 排行榜  | `frontend/api/event/slot-competition/leader-board` |
| `api/event/slot_competition/ranking`      | 名次   | `frontend/api/event/slot-competition/ranking` |
| `api/event/slot_competition/receive`      | 領獎   | `frontend/api/event/slot-competition/receive` |
| `api/event/slot_competition/awards`       | 獎項   | `frontend/api/event/slot-competition/awards` |

---

## 8. 積寶活動（5 個）- 階段4 

| Endpoint                           | 功能   | 新Endpoint |
| ---------------------------------- | ---- | --- |
| `api/event/jackpot/show`           | 顯示   | `frontend/api/event/jackpot/show` |
| `api/event/jackpot/win_list`       | 中獎列表 | `frontend/api/event/jackpot/win-list` |
| `api/event/jackpot/receive`        | 領獎   | `frontend/api/event/jackpot/receive` |
| `api/event/jackpot/check_notify`   | 檢查通知 | `frontend/api/event/jackpot/check-notify` |
| `api/event/jackpot/confirm_notify` | 確認通知 | `frontend/api/event/jackpot/confirm-notify` |

---

## 9. 刷分排行榜（7 個）- 階段4 

| Endpoint                                   | 新Endpoint |
| ------------------------------------------ | --- |
| `api/event/refresh_ranking/list`           | `frontend/api/event/refresh-ranking/list` |
| `api/event/refresh_ranking/leader_board`   | `frontend/api/event/refresh-ranking/leader-board` |
| `api/event/refresh_ranking/awards`         | `frontend/api/event/refresh-ranking/awards` |
| `api/event/refresh_ranking/wallet_balance` | `frontend/api/event/refresh-ranking/wallet-balance`（回應改包成 `{"balance": "..."}`） |
| `api/event/refresh_ranking/vendor`         | `frontend/api/event/refresh-ranking/vendor` |
| `api/event/refresh_ranking/game`           | `frontend/api/event/refresh-ranking/game` |
| `api/event/refresh_ranking/game_url`       | `frontend/api/event/refresh-ranking/game-url` |

---

## 10. 贏家榜（2 個）- 階段5 

| Endpoint                       | 備註     | 新Endpoint |
| ------------------------------ | ------ | --- |
| `api/ranking/winning`          | 中獎排行   | `frontend/api/ranking/winning` |
| `api/ranking/platform_betting` | 平台投注排行 | `frontend/api/ranking/platform-betting` |

---

## 11. VIP（5 個）- 階段5 

| Endpoint                 | 功能       | 新Endpoint |
| ------------------------ | -------- | --- |
| `api/vip/info`           | VIP 資訊   | `frontend/api/vip/info`（另拆出新端點 `frontend/api/vip/contents`） |
| `api/vip/reward_history` | VIP 獎勵紀錄 | `frontend/api/vip/reward-history` |
| `api/vip/flow_limit`     | VIP 流水限制 | `frontend/api/vip/flow-limit` |
| `api/vip/receive`        | VIP 領取   | `frontend/api/vip/receive` |
| `api/vip/receive_rebate` | VIP 返水領取 | `frontend/api/vip/receive-rebate` |

---

## 12. 推薦遊戲（1 個）- 階段5 

| Endpoint                | 功能   | 新Endpoint |
| ----------------------- | ---- | --- |
| `api/getPromotionGames` | 優惠遊戲 | `frontend/api/game/promotion-games` |

---

## 13. 遊戲商費率列表（1 個）- 階段5 

| Endpoint               | 備註      | 新Endpoint |
| ---------------------- | ------- | --- |
| `api/vendor/rate/list` | 遊戲商費率列表 | `frontend/api/vendor/rate-list` |

---

## 14. 股東分潤（1 個）- 階段5 

| Endpoint                 | 功能   | 新Endpoint |
| ------------------------ | ---- | --- |
| `api/shareholder/profit` | 股東分潤 | `frontend/api/shareholder/profit`（免登入，依 domain 判定品牌） |

---

## ~~15. 優惠錢包交易（1 個，廢除）~~

| Endpoint                              | 功能         |
| ------------------------------------- | ---------- |
| ~~`api/promotionWalletTransactions`~~ | ~~優惠錢包交易~~ |

---

## ~~16. 解鎖優惠錢包（1 個，廢除）~~

| Endpoint                        | 功能         |
| ------------------------------- | ---------- |
| ~~`api/unlockPromotionWallet`~~ | ~~解鎖優惠錢包~~ |

---

## ~~17. 活動支持的遊戲（1 個，廢除）~~

| Endpoint              | 功能          |
| --------------------- | ----------- |
| ~~`api/event/games`~~ | ~~活動支持的遊戲~~ |

---

## ~~18. 遊戲紀錄（1 個，廢除)~~

| Endpoint      | 備註                         |
| ------------- | -------------------------- |
| ~~`api/records`~~  | ~~遊戲紀錄~~ |

---
  
  
  
## 純新版 endpoint（無舊版對應，新功能）

- 新版活動系統：`frontend/api/event-v2/list`、`/detail`、`/join`、`/join-detail`、`/withdraw`、`/quit`（events/index.js:169,421,458,494,526,562）
- `frontend/api/event/claim-records` — 領取紀錄（events/index.js:1259）
- `frontend/api/game/recently-game-ids` — 最近遊玩遊戲（homeGames/index.js:1727）
- `frontend/api/game/widget-url` — 遊戲小工具 URL（playgame/index.js:368）
- `frontend/api/vendor/recently-vendor-ids` — 最近場館（vendors/index.js:247）
- `frontend/api/member/tracking-events` — 行為追蹤（List/index.js:409）
- `frontend/api/member/check-profile` — 檢查資料完整度（List/index.js:600）
- `frontend/api/deposit/types` — 存款類型(新)（deposit/index.js:98）
- `frontend/api/guest/get-member-accounts` — 取得會員帳號（驗證流程新增）

---

## 其他呼叫端遷移狀態（WEB_CustomerServiceSocket / RD-Studio-01 / API_Agent）

三個後端呼叫端已全面掃描（`frontend/api`、`frontend-api`、`enableNewApi` 等模式零命中），**呼叫端本身全部 100% 仍走舊版 `api/*`**。原文（2026-07-30 盤點）指新版 API 無對應端點可切；**WEB_CustomerServiceSocket 的 16 條已於 2026-08-06 補齊 apis 側對應端點**（見下），RD-Studio-01／API_Agent 兩批維持原結論（見各自章節）。

### WEB_CustomerServiceSocket — 16 條

> **更新（2026-08-06）**：apis 側已補齊全部 16 條對應端點（`frontend/api/third-party/*`），詳見 `api-frontend-migration-4005.md` 第 16-1 章（遷移細節、刻意偏離舊版之處、順帶修正的 apis 既有問題）。**WEB_CustomerServiceSocket 呼叫端本身尚未修改**，`DEFAULT_API_URL` 仍指向舊版 `API_Frontend`，實際切換非本次範圍。

Base URL 來自 env `DEFAULT_API_URL`（= api-frontend 的 `/api/`），實際發送在 `app/ThirdParty.php:580` 的私有 `post($data, $action)`（:585 讀 env、curl POST）。這批屬 api.php 的 `third_party` 群組（:634-670）。

| 呼叫位置 | endpoint | 對應路由（api.php） | 用途 |
|---|---|---|---|
| app/ThirdParty.php:37 | `getInfo` | :640 `ThirdPartyController@info` | 取會員餘額/資訊 |
| app/ThirdParty.php:60 | `getAgentInfo` | :642 `@agentInfo` | 取代理餘額/資訊 |
| app/ThirdParty.php:83 | `getLoginSetting` | :662 `@getLoginSetting` | 取登入設定 |
| app/ThirdParty.php:107 | `sendSms` | :664 `@sendSms` | 發簡訊驗證碼 |
| app/ThirdParty.php:126 | `checkCode` | :666 `@checkCode` | 驗證簡訊碼 |
| app/ThirdParty.php:146 | `checkAgentToken` | :668 `@checkAgentToken` | 驗證代理 token |
| app/ThirdParty.php:164 | `googleAuth` | :670 `@googleAuth` | Google 驗證 |
| app/ThirdParty.php:186 | `checkPassword` | :644 `@check_password` | 確認會員密碼 |
| app/ThirdParty.php:228 | `transaction` | :646 `@transaction` | 紅包/開洗分 |
| app/ThirdParty.php:269 | `newTransaction` | :648 `@newTransaction` | (新)紅包/開洗分 |
| app/ThirdParty.php:318 | `agentTransaction` | :650 `@agentTransaction` | 代理間轉帳 |
| app/ThirdParty.php:353 | `newAgentTransaction` | :652 `@newAgentTransaction` | (新)代理間轉帳 |
| app/ThirdParty.php:395 | `memberTransfer` | :654 `@memberTransfer` | 玩家轉帳 |
| app/ThirdParty.php:479 | `checkFlowLimit` | :656 `@checkFlowLimit` | 查稽核/流水狀態 |
| app/ThirdParty.php:543 | `getBankList` | :660 `@getBankList` | 取銀行帳號列表 |
| app/ThirdParty.php:560 | `liveChat/{op}` | :634-637（4 條） | LiveChat token 操作 |

`liveChat/{op}` 的 `{op}` 由 `app/Http/Controllers/LiveChatController.php` 傳入：`getToken`（:21）、`getFreshToken`（:27）、`hasToken`（:33）、`invalidate`（:39）。

另註：`OrderTypeController.php:805`、`UserController.php:1344` 會把 `DEFAULT_API_URL` 寫進 `Setting.api_url`，但該欄位只被 `SettingController` 讀寫顯示，從未用於實際呼叫（`ThirdParty::post()` 原本讀 Setting 的程式已被註解，改讀 env），屬歷史遺留。

### RD-Studio-01 — 5 條，全部未遷移

Base URL 來自 env `FRONTEND_API_URL`（含 `/api/`）或 `F2E_API_URL`（網域根，呼叫端自補 `api/`），全部在排程 Commands。新版 API 無代理分紅派發類端點；`login`/`getVendorBalance` 雖有前台新版對應（`guest/login` 等），但為會員導向設計，是否適用排程服務需另評估。

| 呼叫位置 | endpoint | 對應路由（api.php） | 用途 |
|---|---|---|---|
| app/Console/Commands/PayoutSettlementReportAgent.php:152 | `paySettlementReportAgent` | :27 `SettlementReportController@userSettlementReportBonus` | 派發代理結算報表分紅 |
| app/Console/Commands/PayoutThreeHierarchyBonusAgent.php:131 | `agentThreeHierarchyBonus` | :26 `ThreeHierarchyController@userThreeHierarchyBonus` | 派發代理三階層獎金 |
| app/Console/Commands/PayoutUnlimitBonusAgent.php:132 | `agentThreeHierarchyBonus`（同上 action） | :26（同上） | 派發代理獎金 |
| app/Console/Commands/VendorDetect.php:98（post 定義 :234-236） | `login` | :152 `Auth\LoginController@login` | 偵測前先登入取 token |
| app/Console/Commands/VendorDetect.php:107 | `getVendorBalance` | :319 `VendorController@getBalance` | 取遊戲商餘額 |
| app/Console/Commands/TurnMemberBalanceToPlatform.php:101（host :100，`F2E_API_URL`） | `api/singleWalletBalance` | :335 `VendorController@singleWalletBalance` | 查單一錢包餘額轉回平台 |

### API_Agent — 2 條，全部未遷移

Base URL 來自 env `F2E_API_URL`（網域根），發送用 `curlPost()`（`VendorBalanceController.php:181`，URL 拼接 :200）。`unlockPromotionWallet` 連前台都還列為未遷移，後端更無新版可切。

| 呼叫位置 | endpoint | 對應路由（api.php） | 用途 |
|---|---|---|---|
| app/Http/Controllers/VendorBalanceController.php:74（host :73） | `api/getVendorBalance` | :319 `VendorController@getBalance` | 取遊戲商餘額 |
| app/Http/Controllers/VendorBalanceController.php:148（host :147） | `api/unlockPromotionWallet` | :339 `VendorController@unlockPromotionWallet` | 解綁優惠錢包 |