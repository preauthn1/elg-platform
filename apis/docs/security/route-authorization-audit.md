# 全站路由安全稽核報告：越權、IDOR、功能級授權、SQL Injection

這份報告登記「路由沒有擋好權限」這類問題。IDOR 指的是改一下請求裡的 ID 就能讀寫別人的資料。正文只列還沒處理的項目，已修補的移到最後的附錄。

- **初次稽核**：2026-08-14，範圍是 `routes/` 全部路由檔，涵蓋 Frontend、Agent、External 三個 API 面向，加上 `web.php` 與 `dev_tool.php`。
- **重審**：2026-10-08，對照 master `fcf26d3b7` 逐項重驗。行號以這個版本為準。
- **方法**：從路由一路讀到 middleware、Controller、Service。SQL injection 部分見下方專項。
- **沒涵蓋**：
  - 各金流商回調的驗簽實作品質。
  - nginx／ingress 層可能另有的 IP 限制，從代碼驗不到。
  - 前端 App 內嵌密鑰實際的保護強度。

## 嚴重度定義

| 等級 | 判準 |
|------|------|
| **Critical** | 未驗證或低權限即可造成資金損失、帳號接管、全服務中斷 |
| **High** | 需要一個可取得的前提（有效 token、洩漏的共享密鑰、可偽造的 header），達成後可造成資金或大範圍資料損失 |
| **Medium** | 跨租戶資料讀寫、功能級授權缺失，影響限於單一代理線或非資金資源 |
| **Low** | 資訊洩漏、帳號枚舉、需特殊環境才可達 |

---

## 尚未處理的項目

編號沿用初次稽核，方便對照 commit 與工單。

| # | 端點 | 位置 | 等級 | 問題 | 建議修法 |
|---|------|------|------|------|----------|
| 5 | `sw/tfgaming/*`、`sw/jokerbaccarat/*`、`sw/besoft` | [TFGamingSWController.php:35](../../app/Http/Controllers/External/SingleWallets/TFGamingSWController.php#L35)、[JokerBaccaratSwController.php:63](../../app/Http/Controllers/External/SingleWallets/JokerBaccaratSwController.php#L63)、[BeSoftSWController.php:44](../../app/Http/Controllers/External/SingleWallets/BeSoftSWController.php#L44) | **High，白名單為空時等同 Critical** | 這三家遊戲商的回調能扣款、派彩，唯一防線是 IP 白名單，檔內沒有任何簽名或密鑰比對。白名單為空時直接放行（見 #19）。2026-10-08 用唯讀連線查六站 `vendors`：三家的白名單在有啟用的站全是空的。BeSoft（224）在五站啟用、只有 VWorld 有白名單但該站已停用；JokerBaccaratSw（235）六站全啟用；TFGaming（114）只在 Alfawin77 啟用。vendor 停用時回調會不會被擋下，**未驗證** | 止血由 ops 補白名單或停用 vendor，追蹤在 target/op_tickets#3085（政策是「先補設定」，BeSoft 優先）。根治要向三方要簽名機制 |
| 6 | `POST /frontend/api/tgBot/brandList` | [TgBotController.php:118-146](../../app/Http/Controllers/Frontend/TgBotController.php#L118) | High | 唯一驗證是比對 `config('encrypt.aes.md5')`，而且是寬鬆比較（`!=`）。這個值是所有前端 App 為了算請求簽名必須內嵌的共享值，逆向任一 App 就拿得到。回應帶出所有 brand 的 `bot_token`，等於 Telegram bot 全面被接管 | 改用不與前端共用的伺服器間密鑰，加 IP 白名單，回應拿掉 `bot_token` |
| 7 | `POST /frontend/api/third-party/check-password` | [ThirdPartyController.php:262-279](../../app/Http/Controllers/Frontend/ThirdPartyController.php#L262) | High | 以 `username` 指定任意會員，回傳登入密碼或支付密碼是否正確。沒有失敗計數，也沒有 IP 白名單。唯一前提是第三方共用密鑰 | 加 IP 白名單與失敗計數 |
| 8 | `POST /frontend/api/third-party/member-transfer` | [ThirdPartyController.php:1270-1292](../../app/Http/Controllers/Frontend/ThirdPartyController.php#L1270) | High | 付款方 `operator` 與收款方都由請求指定，付款方不做身分驗證，原始碼註解自承只信任 AES 密鑰。整組 `third-party/*` 等於「拿到靜態密鑰就能代操全部會員」 | IP 白名單，加上每次請求帶時間戳與一次性亂數的簽名 |
| 10 | 全站 | [RateLimitNotify.php:38-39、79](../../app/Http/Middleware/RateLimitNotify.php#L79) | High | 全專案沒有任何路由掛 `throttle`。唯一的頻率機制只告警不阻擋（`abort(429)` 那行是註解），而且只在 Frontend 生效。發驗證碼前有滑塊驗證（target/tickets#4188，`ValidationCodeController.php:143`），但各代理可自行關閉，也只擋發送那一步 | 對 `guest/*`、`google-auth/*`、`third-party/check-password`、代理登入掛 `throttle` |
| 12 | `POST /frontend/api/tgBot/newMemberList`、`homePageWithoutLogin` | [TgBotController.php:148-175](../../app/Http/Controllers/Frontend/TgBotController.php#L148)、[:222-225](../../app/Http/Controllers/Frontend/TgBotController.php#L222) | High | `newMemberList` 與 #6 用同一個前端內嵌值驗證，回傳會員與 Telegram 帳號的對應全欄位。`homePageWithoutLogin` 連這個值都不驗，只憑 `x-bot-ID` header | 同 #6；回應只回必要欄位 |
| 18 | `POST /frontend/api/guest/reset-password` 的副作用 | [ResetPasswordService.php:93-102](../../app/Services/ValidateToken/ResetPasswordService.php#L93) | Medium | 每次忘記密碼都把新密碼明文寫進 `member_binding_logs.value`。資料庫一旦外洩，等於這些會員的明文密碼外流 | 不記錄密碼本體，只記事件 |
| 19 | 全部單一錢包、轉帳錢包回調 | [Common.php:283-292](../../app/Helpers/Common.php#L283)、[:302-311](../../app/Helpers/Common.php#L302) | Medium | 白名單 fail-open：非 production 一律放行；vendor 的白名單為空一律放行 | 已有決策：不改成全域 fail-closed，因為有 12 支 controller 刻意依賴空值的行為，改了會斷。政策是由 ops 逐家補白名單（target/op_tickets#3085） |
| 20 | `GET /external/api/sw/nex4d/getBoUrl` | [PasswordProtected.php:38、42](../../app/Http/Middleware/External/PasswordProtected.php#L38)、[config/auth.php:147](../../config/auth.php#L147) | Medium | 路由註解裡的密碼已移除，但 `ROUTE_ACCESS_PASSWORD` 的 `env()` 預設值仍是明文寫在 config 裡。仍接受 `?password=` 查詢參數，密碼會落進 access log。比對用 `!==`，不是固定時間比較 | 拿掉預設值並輪替密碼；停收查詢參數前要先確認誰在用 |
| 21 | `/frontend/api/third-party/*`（21 條）、`/external/api/*` | [third_party_auth.php:24-43](../../routes/frontend/third_party_auth.php#L24)、[DetectIp.php:16-21](../../app/Http/Middleware/DetectIp.php#L16) | Medium | 這兩組的 middleware 只有 `DetectIp`，它只把 IP 寫進 Context，不做驗證，代碼層沒有路由級 IP 限制（`CLAUDE.md` 已標註）。初次稽核時是 17 條，現在是 21 條，其中 `transaction`、`new-transaction`、`agent-transaction` 也是開分、洗分這類搬錢的端點 | 補 IP 白名單 middleware（配合 #7、#8） |
| 23 | 重設密碼的驗證碼流程 | [CheckValidationCodeService.php:188-199](../../app/Services/ValidateToken/CheckValidationCodeService.php#L188) | Low | 驗證碼已改用安全亂數。剩下的問題是取有效驗證碼時用 `when($mobile \|\| $email)` 條件式過濾，重設密碼的路徑上沒有強制綁定手機或信箱 | 所有路徑都強制綁定；會動到重設密碼的現行流程，要先跟業務確認 |
| 17 | `ANY /external/api/cash-flow/balance/{agentCashFlowId?}` | [GetBalanceController.php:39-44](../../app/Http/Controllers/External/CashFlow/GetBalanceController.php#L39) | Low（原判 Medium） | 原判讀「未驗證就能讀商戶餘額」有誤：三方查詢回傳的是物件，`is_array()` 檢查永遠不通過，一律丟例外，所以餘額不會回給呼叫者。殘留風險是不用驗證就能觸發對三方的外連查詢，而且錯誤訊息會帶出金流商戶名稱，可拿來枚舉 ID。這個端點本身功能也是壞的 | 加 IP 白名單或內部密鑰；端點失效另開單處理 |
| 25 | `GET /docs/reports/*`、`GET /docs/{frontend,agent,external}` | [ReportDocsAccess.php:21-22](../../app/Http/Middleware/ReportDocsAccess.php#L21) | Low | 靠 `config('scramble.enabled')` 把關，production 關閉即 404。屬設計上可接受，風險在「開著這個設定的環境是否對外可達」 | 維持現狀 |

---

## 報告寫完後新增、沒掛功能級授權的代理路由

#13 已為代理後台導入功能級授權（`CheckAgentPermission`，逐條標註所需選單）。之後新增的路由有 31 條沒掛，等於部分回退。

| 路由 | 位置 | 等級 | 說明 |
|------|------|------|------|
| 新人挑戰 14 條 | [routes/agent/auth.php:110-125](../../routes/agent/auth.php#L110) | 初判 High | 完全沒掛 `CheckAgentPermission`。其中 `update-wallet-balance` 能增減會員的活動錢包，和活動 V2 共用同一顆錢包（`NewbieChallengeAgentService.php:204-252`）。服務層有代理線範疇檢查，但子帳號沿用主帳號的階層（`:581-587`），所以只有報表權限的子帳號，也能幫線下會員加活動錢包。等級取決於活動錢包能不能轉成可提現的錢，**未驗證** |
| social-link 3 條、game-maintenance 4 條、member-game-restriction 4 條、member-mail 5 條、`member/referral-info` 1 條 | [routes/agent/auth.php:165-231、425](../../routes/agent/auth.php#L165) | Medium | 沒有功能級授權，資料範疇在服務層有做 |

其他報告寫完後新增、值得留意的路由：

| 路由 | 位置 | 等級 | 說明 |
|------|------|------|------|
| `routes/shared/detect_ip.php` | [bootstrap/app.php:64、75、84](../../bootstrap/app.php#L64) | Low | 為了 target/tickets#4187 的 IP 偽造測試加入，掛在三個 API 面向的正式環境，不用驗證。會回顯全部請求 header 與信任代理設定，可被拿來偵察基礎設施。建議測完就下架 |
| `sw/sky1388/confirm_transfer` | [routes/external/tw.php:42](../../routes/external/tw.php#L42) | Low | 只有 IP 白名單，但是唯讀查詢，只能確認某筆訂單存不存在 |
| `tgBot/bindMobile` | `routes/frontend/tgbot_auth.php`、[TGBotAuthMember.php:23-50](../../app/Http/Middleware/Frontend/TGBot/TGBotAuthMember.php#L23) | 未定 | 初次稽核就存在但漏列。身分只認 `x-bot-tgID` header，而 #12 會洩出 Telegram 帳號對應。如果綁手機不需要驗證碼，可能串成「綁攻擊者的手機，再走忘記密碼」的帳號接管。**未驗證** |

新加入的 AiGamingSW、OnebxSW 回調都有簽名驗證，沒有問題。

---

## SQL Injection 專項

- **初次稽核**：全庫 419 處 raw SQL（`whereRaw`、`orderByRaw`、`selectRaw`、`havingRaw`、`DB::raw`、`DB::select`、`DB::statement`）逐一比對輸入來源。唯一以請求輸入直接拼接的是 #24，已改為綁定參數。結論是 production 路徑上沒有可利用的 SQL injection。
- **重審**：沒有全量重做。用較寬的條件搜尋，現在是 849 處，和初次的 419 計算口徑不同，不能直接比較。逐行看了 30 處用雙引號插值的寫法，值都來自 DB 讀出、常數或 grammar 的欄位包裝，例如 `GameController.php:741、773`、`BetRecordCorrectionQueryService.php:296-304`、`MemberTraceService.php:525`，沒有看到請求參數直接拼接。
- 全量重審另排。

---

## 已驗證正確的部分（信心邊界）

為了避免讀成「全站皆漏」，以下區域抽查後確認授權實作正確：

- **Frontend 已驗證區（`routes/frontend/auth.php`）**：`AuthMember` 建立 Context 後，controller 一律從 `getUserContext()->member` 取身分，再把 `$member` 傳進 Service 過濾。抽查 `AutoDepositController`、`PrizeController`／`PrizeService`、`SystemNotifyController::detail`、`EventV2Controller`、`GameController::getUrl`，都沒發現 IDOR。
- **Livechat Bot（`/external/api/api-callback/livechat/bot/*`）**：`LivechatBotApiKeyAuth` 驗 API key（`livechat-bot.php:22`），`LivechatBotContext::findMemberByUsername()` 以 bot 綁定代理的 `hierarchy_path` 做跨租戶隔離，查無即回 null，不洩漏帳號是否存在。設計正確，可當其他模組的範本。
- **Agent API 做對的**：`MemberTraceController`／`MemberTraceService`（`scopePath()`，`MemberTraceService.php:126、166`）、`MemberGroupController`、`MemberOnlineController`、`VendorController`、`EmailGatewayController`、`ReportController::sr12`。
- **`dev_tool.php`**：只在 local 加 debug 模式下註冊，`LocalDebugOnly` middleware 再擋一次，production 不可達。
- **單一錢包回調的第二道防線**：抽查確認 PPSW、KissH5、MegaH5、Slot4D、Nex4D 等驗 hash，ClotPlay 系驗 `Authorization`，Spadegaming 驗 digest（`SpadegamingSWController.php:128`）。

---

## 建議處理順序

1. **立即**：#5 由 ops 補白名單或停用 vendor（target/op_tickets#3085）。
2. **近期**：#6、#12 換獨立密鑰並拿掉 `bot_token`；#7、#8、#21 補 IP 白名單；#10 掛 `throttle`；新人挑戰 14 條補功能級授權（先確認活動錢包能否轉成可提現的錢）。
3. **技術債**：#18 停止記錄明文密碼；#20 拿掉密碼預設值並停收查詢參數；#23 驗證碼強制綁定；其餘 17 條新代理路由補功能級授權；`detect_ip` 路由下架；#17 端點修復。

> 註：碰到 `app/Services/EventV2/` 或活動 V2 相關 Controller 的修補，依 `CLAUDE.md`「活動 V2 修改紀律」要先讀 `docs/event-v2/`，發現與現有規則衝突要先向 PM 確認。

---

## 附錄：已修補的項目

| # | 原問題 | 修補方式 | commit |
|---|--------|----------|--------|
| 1 | 忘記密碼時，手機與信箱都為空的帳號會被當成同一人，可任意改別人的密碼（Critical） | 手機、信箱分開比對，只有非空值嚴格相等才算相符，fail-closed（`ResetPasswordService.php:44-50`） | `1a6e2328d` |
| 2 | 活動錢包調整沒有範疇檢查，任何代理可替任意會員加值（Critical） | 限定活動錢包型別並加鎖，再補代理範疇檢查（`EventV2Controller.php:957`）。金額上限屬業務決策，由 PM 決定 | `2aed2ad1d`、`8956d88a5` |
| 3 | `debug/redis/clear` 未驗證即可清空 Redis（Critical） | 移出正式路由，改掛只在 local 加 debug 模式註冊的 `LocalDebugOnly` 群組 | `2f8f97c95` |
| 4 | `getIp()` 直接信任 `X-Forwarded-For`，IP 白名單可被繞過（High） | `getIp()` 改為委派 `resolveClientIp()`：請求不是來自信任代理時，完全不採信轉送 header（`Common.php:100-103、152-186`）。各站信任代理清單有沒有設對，從代碼驗不到，要逐站確認 | `094fb81d4`、`2fd942ac0` |
| 5（部分） | AFB 回調只有 IP 白名單 | 補上 API Key 比對（`AFBSwController.php:72-121`）。其餘三家見正文 | `31a8dd109` |
| 9 | 代付出款與取款訂單查詢沒檢查訂單歸屬（High） | 取單後一律過 `isOrderInScope()`，之後新增的 fill-third-party-no、mark-failed 也套用（`Agent/WithdrawalController.php:40、60、120-140`） | `e696efcdc` |
| 11 | 會員 Google 驗證可無限次嘗試（High） | 錯誤次數達代理設定的上限即鎖定帳號（`GoogleAuthController.php:121-127`、`LoginService.php:176-188`）。理論上的副作用：未登入的人可以故意輸錯，把別人的帳號鎖住 | `01d60c65b` |
| 13 | 代理後台沒有功能級授權（Medium） | 導入 `CheckAgentPermission`，逐條路由標註所需選單（`CheckAgentPermission.php:36-45`）。之後新增的路由有回退，見正文 | `f437ec0a5` |
| 14 | 活動 V2 的 allow-join、reject-detail、quit 可跨代理操作（Medium） | 以 `Agent::canSeeAgent()` 比對活動所屬代理（`EventV2Controller.php:299-335、998`） | `d61ec98ce` |
| 15 | 打卡活動寫入端可跨代理改寫，甚至改掛到別的代理（Medium） | create、update、status、detail 一律過範疇檢查（`CheckinController.php:58、117、152-153、189`） | `2188a5ef2` |
| 16 | 網域管理可被任意代理改寫（Medium） | 收斂為平台端專用（`DomainManageController.php:38-106`） | `ef4516683` |
| 20（部分） | 路由註解寫出實際密碼 | 註解中的密碼已移除。其餘見正文 | `d8ad85bf0` |
| 21（部分） | `CLAUDE.md` 宣稱 External 全有 IP 白名單 | `CLAUDE.md` 已標註實際覆蓋範圍。其餘見正文 | `1f5715cd0` |
| 22 | 忘記密碼、Google 驗證可枚舉帳號（Low） | 帳號不存在與驗證失敗回相同錯誤（`ResetPasswordService.php:35-37`、`GoogleAuthController.php:117-119`） | `1a6e2328d`、`01d60c65b` |
| 23（部分） | 驗證碼用 `rand()` 產生 | 改用 `random_int()`（`SendValidationCodeService.php:131`）。其餘見正文 | `a6e32af7e` |
| 24 | `debug/try-sms` 以請求參數拼接 SQL（Low，只在 local 可達） | 改為綁定參數（`DebugController.php:276`） | `8bd1fbd3c` |
