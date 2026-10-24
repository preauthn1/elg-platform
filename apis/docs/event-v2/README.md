# 活動 V2 系統文件

> 本目錄為活動 V2（Event V2）系統的完整技術文件。

## 文件索引

| 文件 | 內容 |
|---|---|
| [database.md](database.md) | 15 張資料表完整設計（關聯圖、欄位說明、status enum、風控稽核機制） |
| [field-reference.md](field-reference.md) | `event_v2_details` 所有欄位的規則、型別與設定範例（含 DB id 1–14 完整說明） |
| [event-types.md](event-types.md) | 所有活動類型 handler class 的說明與適用場景 |
| [agent-api.md](agent-api.md) | 後台管理 API 完整說明（查詢、新建、更新、強制結束、錢包調整） |
| [frontend-api.md](frontend-api.md) | 前台（會員端）API：列表、詳情、參加、紅包畫面資訊 `angpow/info` 與驗證碼對照 |

---

## 關鍵路徑

| 用途 | 路徑 |
|---|---|
| 抽象基底（生命週期入口） | `app/Services/EventV2/AbstractEventV2.php` |
| 活動類型工廠 | `app/Services/EventV2/EventV2Service.php`（`getEventClass()`） |
| 前台 Controller | `app/Http/Controllers/Frontend/EventV2Controller.php` |
| 各子服務（活動類型實作） | `app/Services/EventV2/` |
| 稽核流水 Model | `app/Models/FlowLimit.php` |
| 稽核流水狀態 Enum | `app/Enums/FlowLimitStatusEnum.php` |

---

## API 生命週期

```
POST event-v2/detail   → validate()（唯讀，不取鎖）→ 回傳 validate: true/false
POST event-v2/join     → 用 event_v2:validate:{member_id} 鎖包住 validate() → join() →
                          [withdraw_type=2 積分：join() 內直接 grant() 積分、紀錄即 COMPLETE，不進活動錢包、
                           不走下面的 withdraw()／initEventV2()；見 field-reference.md — withdraw_type]
                          [紅包 AngpowService：join() 建好紀錄後先 deduct() 扣門票積分（afterJoinRecorded 掛鉤），
                           餘額不足拋例外整筆回滾；見 event-types.md — AngpowService]
                          [若 join_withdraw=1 則同時 withdraw()] → DB::commit()，
                          鎖到 commit 完才釋放，避免併發重複參加
POST event-v2/withdraw → withdraw()（需滿足 balance_limit + flow_limit；靠 EventV2UserJoin
                          row 的 lockForUpdate() 防重複提領，不需額外的 distributed lock）
POST event-v2/quit     → 強制結束活動（不提款，依 is_recycle 決定是否記錄剩餘餘額）
```

`validate()` 本身不取鎖，唯讀情境（`detail()`、驚嘆號判斷）可以直接呼叫；只有接著要 `join()`/`withdraw()`
寫入時，呼叫端才需要自行用同一把鎖包住整段流程（詳見 `EventV2Controller::join()`）。

### validate() 共用檢查（依序，任何子類別都會先跑過這些才進到 getValidate()）

| 檢查 | 失敗代碼 | 說明 |
|---|---|---|
| 活動存在且在時間區間內、`status=1` | `EVENT_NOT_EXIST` | 對 `event_v2_details` 該筆 row 做 `lockForUpdate()` |
| 無未通過的稽核流水 | `EVENT_MODE` | 見下方「稽核流水鎖定」 |
| 未同時進行新人專享挑戰活動 | `ONLY_ONE_EVENT` | 依會員查 `newbie_challenge_progress.stage` 是否為非終態（不是 `BONUS_COMPLETED`/`QUIT`；若為 `BONUS_PENDING` 則另外判斷 `bonus_window_expire_at` 是否已過期，過期視同非進行中——無獨立的「已失效」狀態值），沿用 `ONLY_ONE_EVENT` 失敗碼；反向由 `NewbieChallengeService::join()` 檢查 `is_event_v2` 擋掉，兩邊互斥，詳見 `docs/新人專享挑戰活動/` |
| 未同時參加其他活動 | `ONLY_ONE_EVENT` | 依會員查 `event_v2_user_joins.status=JOIN` |
| 未重複參加本活動 | `HAS_JOIN` | 同上，比對 `event_v2_detail_id` |
| 未達 `condition_config.max_join` 人數上限 | `MAX_JOIN_REACHED` | 全站計數，非單一會員 |
| `phone_block` 設定時已完成手機驗證 | `PHONE_NOT_VALIDATE` | |

#### 稽核流水鎖定（取代舊版 `members.event_mode`）

參加資格檢查 `flow_limits` 表是否存在符合以下全部條件的紀錄（`exists()`，不看 `reach` 實際數值——
即使剛建立、`reach` 還是 0 的 pending 紀錄也算數，只要還沒打完就擋），若存在則擋下參加
（失敗代碼沿用 `EVENT_MODE`）：

- `member_id` 符合目前會員
- `type` 不是 `ORDER_DEPOSIT(3)`／`DEPOSIT(4)`／`COUPON_CODE(107)`（排除儲值／優惠碼類型）
- `event_id != 0`（僅計入綁定 V1 活動的稽核流水，非活動來源如任務、邀請獎勵目前不計入）
- `flow_limit > reach`（用 `whereColumn()` 比較兩個欄位，代表流水還沒打完；注意不可寫成
  `where('flow_limit', '>', 'reach')`——`reach` 會被當成字面字串值而非欄位名稱，MySQL 把非數字字串
  轉型成 0 比較，條件會變成恆真／恆假，整個檢查會默默失效但不會報錯）
- `status = PENDING(0)`（尚未通過）

舊版只檢查 `members.event_mode > 0`，語意等同「有沒有綁定 V1 活動、流水還沒打完的鎖分紀錄」；新版直接查
`flow_limits` 本身，不再依賴 `members.event_mode` 欄位（該欄位不再參與這個判斷）。

#### 反向鎖定：V2 進行中擋所有增減分數操作

上一節是「V1 未完成擋 V2 加入」；反方向也擋——會員目前有 `status=JOIN` 的 V2 活動時，
禁止任何會改變主錢包餘額的操作（領獎、儲值、提款），避免同時疊加兩套活動的獎勵／流水規則，
或在活動的 `balance_limit`／`flow_limit` 門檻計算之外任意增減餘額。判斷式直接讀
`Member::isEventV2()` accessor（即 `$member->is_event_v2`，定義於 `app/Models/Member.php`），
不重查 `event_v2_user_joins` 表，與 `FlowLimitService`／`WalletService` 等既有慣例一致。

檢查點與舊版鎖分模式檢查（`event_mode > 0` → `error_message.1934`）緊鄰放置，
獨立判斷、獨立錯誤碼（語意不同：1934 是「V1 鎖分模式中」，前端需分辨顯示文案）：

**領獎類**（含 V1 活動獎勵與任務／簽到／代理反水等非 V1 專屬獎勵），統一走 `error_message.1991`（「V2 活動進行中」）：

| Service | 方法 |
|---|---|
| `app/Services/PrizeService.php` | `drawByDetail()`／`drawAllPrizes()`／`drawPrize()`／`choosePrize()` |
| `app/Services/LuckyDrawService.php` | `receive()`／`receiveAll()` |
| `app/Services/EventJackpotService.php` | `receive()` |
| `app/Services/SlotCompetitionService.php` | `receive()` |
| `app/Services/WJackpotService.php` | `assertClaimable()`（共用於 `receive()`／`receive6d()`／`receiveAll()`／`receive6dAll()`） |
| `app/Services/VipService.php` | `claimReward()`／`claimRebateRewards()` |
| `app/Services/Frontend/Missions/MissionService.php` | `claim()`（任務獎勵） |
| `app/Services/CheckinService.php` | `checkin()`（簽到獎勵） |
| `app/Http/Controllers/Frontend/ThreeHierarchyController.php` | `withdrawalCreate()`（三代反水獎勵領取，非銀行提款） |
| `app/Http/Controllers/Frontend/UnlimitHierarchyController.php` | `withdrawalCreate()`（全民代理獎勵領取，非銀行提款） |

**進出款類**，各自獨立錯誤碼（沿用既有 `deposit_not_allowed` 或新增專屬 key，見 `resources/lang/{locale}/error_message.php` 的 `event_mode` 陣列）：

| Controller/Service | 方法 | 錯誤碼 |
|---|---|---|
| `app/Http/Controllers/Frontend/DepositController.php` | `createOrder()`（儲值） | `error_message.event_mode.deposit_not_allowed` |
| `app/Http/Controllers/Frontend/AutoDepositController.php` | `createOrder()`（自助上分儲值） | `error_message.event_mode.deposit_not_allowed` |
| `app/Services/WithdrawalService.php` | `checkEventV2Restriction()`（供 `WithdrawalController::createOrder()` 呼叫，銀行提款） | `error_message.event_mode.withdrawal_event_v2_not_allowed` |
| `app/Http/Controllers/Frontend/ThirdPartyController.php` | `assertMemberTransferAllowed()`（會員間轉帳／紅包，雙向） | `error_message.event_mode.transfer_out_event_v2_not_allowed`／`transfer_in_event_v2_not_allowed` |

> 會員間轉帳（紅包）與遊戲商 VIP 系列領獎在此規則實裝前即已支援 V2 擋，其餘 7 處（提款、簽到、任務、三代／全民代理反水領獎、自助上分）為 target/tickets#4221 補上。

**後台手動加減分**（`app/Http/Controllers/Frontend/ThirdPartyController.php` 的 `transaction()` / `newTransaction()`，即 `routes/frontend/third_party_auth.php` 的 `transaction` / `new-transaction`，供舊代理後台／CS 系統呼叫，走 IP 白名單非會員 token）：

- 這支 API 承載 10 種 `transaction_type`（4,5,6,7,8,9,10,11,12,14），其中「V1 鎖分模式」只擋發紅包(6)／領紅包(7)，其餘（開分/洗分/補點等 CS 手動調帳、紅包取消/退還等系統沖正）刻意不受影響——詳見 `EVENT_MODE_GUARDED_TRANSACTION_TYPES` 上方 docblock。
- **V2 進行中擋的範圍**：`EVENT_V2_GUARDED_TRANSACTION_TYPES` = 開分(4)／洗分(5)／發紅包(6)／領紅包(7)／開分贈點(10)／補點(14)，統一回 `error_message.event_mode.transaction_event_v2_not_allowed`。
- **刻意排除**紅包取消(8)／紅包退還(9)／拒絕洗分(11)／退還洗分(12) 這 4 種「系統把已扣的錢沖正退回會員」的動作——擋下沖正會讓錢卡在系統裡出不去，比不擋更危險，此點與 V1 鎖分模式的既有設計理由一致。
- **與 `target/tickets#4333` 的關係（已解決，不再衝突）**：`newTransaction()` 開分/洗分無論 `is_event_wallet`（`is_event_v2` 或新人專享挑戰）是否為真都直接寫 `members.balance`，`is_event_wallet` 只決定帳變流水寫進 `wallet_transaction_logs` 還是 `transaction_logs`（避免污染小時匯總報表），並不會把錢實際搬進活動錢包（`member_wallets`/`CODE_EVENT_WALLET`）。`ThirdPartyTransactionTest::test_new_transaction_deposit_for_event_wallet_member_does_not_touch_member_hourly_statistics` 驗證「活動錢包會員開分不進小時匯總」。這支測試原本用加入活動 V2 觸發 `is_event_wallet`，會和 target/tickets#4221 的擋下衝突；commit ce338a222 已改成用新人專享挑戰（`NewbieChallengeProgress` stage=CHALLENGING）觸發，兩者不再衝突。

所有子類別必須實作五個抽象方法：

| 抽象方法 | 用途 |
|---|---|
| `getValidate()` | 回傳 validate 公式串（呼叫 AbstractEventV2 check 方法組合） |
| `getReward()` | 計算獎勵金額 |
| `getBalanceLimit()` | 計算最低餘額門檻（提款條件） |
| `getFlowLimit()` | 計算流水門檻（提款條件） |
| `getWithdrawLimit()` | 計算可提款金額 |

---

## 主錢包扣款邏輯（`main_wallet_deduction`）

**觸發條件**：`condition_formula` 含 `'deposit'` 字串（即 `checkDeposit()` 被呼叫）。

**儲值候選來源**：`checkDeposit()` 預設只看 `orders` 表的真實儲值訂單；代理組別設置 `AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT` 開啟時，真實訂單全部不合格才會退而求其次看 CS 後台手動開分（`transaction_logs`，`type=DEPOSIT`），詳見 [field-reference.md — 後台開分視為儲值](field-reference.md#後台開分視為儲值-elgtickets4352)（target/tickets#4352）。以下小節的 `TransactionLog（order_id=deposit->id, type=ORDER_DEPOSIT）` 查詢只適用真實訂單來源；後台開分來源時 `$this->backendDeposit` 本身就是要找的那筆 `TransactionLog`，直接用其 id 比對 `FlowLimit.reference_id`，不必先查 order_id。

### Validate 階段（`checkDeposit()`）

0. `withdraw_type=2`（積分）**整段跳過**：積分不進活動錢包，沒有「主錢包→活動錢包」這回事，不扣主錢包、不拿主錢包餘額擋人。
   `FirstDepositService::checkFirstDeposit()` 同。
1. 計算扣除金額（三種情境）：

   | `main_wallet_deduction` | 扣款金額 |
   |---|---|
   | 未設定（null） | `deposit->amount` 全額 |
   | 設定 `0` | 不扣（`mainWalletDeduction` 維持 null，後續 join 不執行扣款） |
   | 設定正數 | `min(config, deposit->amount)`，不超過本次儲值額 |

2. 扣款金額 > 0 時，檢查 `member->balance >= deductionAmount`，不足回傳 `BALANCE_NOT_ENOUGH`

### Join 階段（`join()`）

1. 安全二次餘額確認
2. `reward += mainWalletDeduction`（同額加入活動錢包）
3. `member->balance -= mainWalletDeduction` + 寫 `TO_EVENT_V2_WALLET` 交易紀錄
4. 扣除玩家稽核流水：
   - 透過 `TransactionLog`（`order_id=deposit->id`, `type=ORDER_DEPOSIT`）找對應 `FlowLimit`
   - 增加 `FlowLimit.reach`（上限 cap 至 `flow_limit`）
   - 若 `reach >= flow_limit` → `status = FlowLimitStatusEnum::PASSED`

---

## 活動錢包 vs 主錢包

| | 主錢包（`members.balance`） | 活動錢包（`WalletEnum::CODE_EVENT_WALLET`） |
|---|---|---|
| 扣款時機 | 參加時扣 `mainWalletDeduction` | — |
| 入帳時機 | 提領時入 `withdraw` 金額 | 參加時入 `reward`（含扣款金額） |
| 交易型別 | `TO_EVENT_V2_WALLET` | `EVENT_WALLET_JOIN` |

> `withdraw_type=2`（積分，target/tickets#4231）：**兩個錢包都不碰**。`join()` 依提款限制公式算出積分數量、經 `MemberScoreService::grant()` 發成積分批次
> （投影在 `member_wallets` `WalletEnum::CODE_SCORE`），紀錄即 COMPLETE；不扣主錢包、不進活動錢包、不記 SR11 活動成本。
> 規則全文見 [field-reference.md — withdraw_type](field-reference.md#withdraw_type)。

> `CODE_EVENT_WALLET` 這個錢包 bucket 目前也被「新人專享挑戰活動」共用（詳見 `docs/新人專享挑戰活動/`），命名已從 `CODE_EVENT_V2` 改為通用的 `CODE_EVENT_WALLET`；`WalletTransactionEnum` 的 `EVENT_V2_WALLET_*` 系列 case 也同步改名為 `EVENT_WALLET_*`。
