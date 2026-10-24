# Astar（vendor_id=101）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，Astar 回調扣加款 |
| 回調方向 | **Astar → 本平台**（本平台為 server，被呼叫方）；與 JokerBaccarat 相反 |
| 驗證 | `Authorization` Header（固定 Token = [REDACTED] IP 白名單；**無簽章演算法** |
| 成功判定 | `status.code === "0"`，HTTP 一律 200 |
| 時區 | UTC-04:00（對帳換算注意） |
| 匯率 | 進入 `Common::numDiv($amount, rate)`；回傳餘額 `Common::numMul($balance, rate, 4)` |
| Outbound | **有兩組，本平台都尚未實作呼叫端**。① 注單查詢 API（`https://example.invalid/channelApi/V3/API/TH41/...`，先 `/auth` 換 Bearer token）—— 2026-10-01 實測可用，是拿棋牌真實投注額的管道。② 細單數據 API（`{GAME_URL}/client/api/{GAMEHALL}/detail`）—— 打不通，卡在我方 `detailtoken` 回調 |

## MTcode 校驗

- **rollout / rollin**：`AstarController::validateMtCode()` 以 `-` 切 3 段，要求：① 第 1 段（env）非空；② 第 2 段 === `rollout` / `rollin`；③ 第 3 段 === 該請求 `round`。
- **refund**：Astar 實際只送 `mtcode`（TPower 2026-09-05 事故 log 實證為純 round 字串，例 `TP213173059`），沒有 `round`、`id`、`gametoken`。`resolveRefundRoundCandidates()` 以 mtcode 推回 round 候選：**先把整串 mtcode 當 round 精確比對**，查無且為 `{env}-{action}-{round}` 三段式時再取第三段（action 段不檢查）；請求若另帶 `round` 則必須是候選之一，否則 `BAD_PARAMETERS(7)`。

## 結算／退款的帳號定位（op_tickets#3068 修正，2026-09-06）

`rollin()` 與 `refund()` 的帳號定位在 **`callAction()` 內、交易外**完成（2026-09-09 起，target/tickets#4294），順序固定為：**商戶授權 → 定位注單（不加鎖）→ 反查帳號**，見 `authorizeGamehall()`、`findRolloutRecord()`、`loadAccountForSettlement()`（三者由 `loadSettlementContext()` 串起）。進 `DB::transaction` 後第一個 SQL 是 `getMemberLockedWallets()` 鎖會員列，接著才以 `findRolloutRecord(..., lock: true)` **重取並鎖注單**。

> **為什麼要拆成交易外定位、交易內重取**：MySQL REPEATABLE READ 的快照定在「本交易第一次一般讀」那一刻。若在交易內先查帳號、商戶再等會員鎖，拿到鎖後讀注單與現金紀錄看到的仍是等鎖前的舊資料——這正是 TPower 2026-09-05 SR14 `point` 對不上餘額（target/tickets#4294）與取消請求判定「查無此單」（target/op_tickets#3075）的根因。交易外的一般讀不帶進交易，交易內第一句就是鎖定讀，快照改由鎖後的第一次一般讀建立。`authenticate` / `balance` / `rollout` / `detailtoken` 的 `loadAccountAndVerify()` 同樣移到 `callAction()`；`rollout()` 進交易第一句是 `Member::lockForUpdate()`。

1. **商戶授權先行**：以 URL 的 `gamehall` 找啟用中的 Astar `vendor_infos`，比對 `info.GAMEHALL` 與 `Authorization` header（`CS_TOKEN`），不符即 `TOKEN_INVALID(4)`。放在查注單之前，是為了讓沒有 CS_TOKEN 的請求拿不到「round 是否存在」的差異回應（34 vs 4）。
2. **定位注單**：`bet_id = round` 且 `status = ROLLOUT` 的列；不存在或已終態一律 `ORDER_NOT_FOUND(34)`，維持冪等。交易外這次不加鎖、只為找帳號；交易內鎖會員後再 `lockForUpdate` 重取一次，此時若已被併發請求結算，同樣回 `ORDER_NOT_FOUND(34)`。
3. **反查帳號**：在該商戶（`vendor_info_id`）範圍內，以注單的 `vendor_username` 對 `astar_accounts.username` **或** `vendor_username` 命中（`orderBy id` 保證決定性）。兩欄都要查是因為注單的 `vendor_username` 有兩種格式：**2026-09-02 02:48 前由舊系統寫入的列存帳號的 `vendor_username`（帶 `TP` 前綴）**，之後 APIs 的 `rollout()` 存請求的 `id`（＝帳號 `username`）。
   - 請求有帶 `id` 時，必須是該帳號的 `username` 或 `vendor_username`，否則 `PLAYER_NOT_FOUND(2)`（防跨玩家沖帳；不直接拿 `id` 比注單欄位，就是為了對兩種格式免疫）。
   - 請求有帶 `gametoken` 時，須等於**注單當時存的 `session_token`** 或帳號**現行** `session_token`，否則 `INVALID_GAMETOKEN(9)`；refund 依規格不帶 token 則不比對。

`authenticate` / `rollout` / `detailtoken` 仍走 `loadAccountAndVerify()` 以帳號現行 token 驗證（那是活體 session 的授權，語意不同）。

> **背景與根因**：`Driver::getGameUrl()` 每次取遊戲網址都重發 `session_token` 並覆蓋帳號，所以「注單當時的 token ≠ 帳號現行 token」是常態，不是特例。舊實作對 rollin／refund 都用「帳號現行 `session_token` == 請求 `gametoken`」找帳號，等於假設結算一定在玩家再次進遊戲之前完成；三方延遲一長就破功。Astar 重送 rollin 帶的是下注當時的 gametoken（TPower 事故 log 1,345 個重送回合零例外），refund 更是根本沒有 gametoken，所以 refund 在 APIs 接手後從未成功過。
>
> **觸發點是遷移，不是 Astar 改行為**：TPower 的 Astar 回調 2026-09-02 02:30～02:48 UTC 從舊系統 API_External 切到 APIs，舊系統時期零卡單且成功處理過 2,005 筆 refund；APIs 接手後每天 1～6 筆卡單，09-05 的 DDoS 延遲把它放大成事故窗 95 筆／15,315（09-06 查時全表 130 筆／21,958，數字隨新卡單與自癒持續變動）。
>
> **已知取捨**：refund 沒有玩家側因子，唯一防線是 `CS_TOKEN`＋IP 白名單（`vendors.whitelist` 空值即放行，各站務必填值）；rollin 同時接受帳號現行 token 是為了相容 Astar 以當下 session 補送的情況，跨玩家防護由「注單 → 帳號」那條線負責，不靠 token。

## 錢包流程與 BetRecord 狀態機

`ROLLOUT(0)` →（rollin 結算）`ROLLIN(1)` 或（refund 取消）`REFUND(9)`，後兩者皆終態、不可逆。

- **Rollout**：建立 BR `status=ROLLOUT`、`win_lose = -amount`，扣玩家錢包。
- **Rollin**：`valid_bet = validbet/rate`、`win_lose = win/rate`（直接採 Astar 傳值，非自行套公式）、`status=ROLLIN`、寫 `payout_time` + TBR。
  - 🔴 **這個「直接採傳值」只對真人成立，捕魚棋牌寫出來的是錯的**，見下方「捕魚棋牌（BOARDCARD）金額口徑落差」。
- **Refund**：`bet/valid_bet/win_lose` 歸 0、`status=REFUND`、回退已扣金額 + 寫 TBR。
- **TBR 寫入時機**：`rollin()` / `refund()` 完成後才寫；**`rollout()` 不寫 TBR**（仍為未結算中間態）。

## 冪等性

- `rollout()` 捕捉 MySQL 1062（duplicate key）→ 回 `ROLLOUT_EXISTS(13)`。
- `rollin()` / `refund()` 以 `status=ROLLOUT` 為前置；已結算的回合查不到 ROLLOUT → `ORDER_NOT_FOUND(34)`，達成冪等。
- **狀態機不可坍縮**：中間態（ROLLOUT）不可被重複請求推成終態兩次。

## 捕魚棋牌（BOARDCARD）金額口徑落差

> 查證來源：Yes2Win 正式庫 `astar_bet_records` 全量 316 筆 BOARDCARD 記錄（2026-10-01 查）。`tabletype` 實際出現 `100002`（二人麻將）、`100004`（百人牛牛）、`100033`（經典搶莊牛牛）。

**玩法模型**：一次 rollout（帶入整疊籌碼）＋ 一次 rollin（帶回當前餘額）＝ **一局**，不是一整個 session。同帳號相鄰兩局中 169/220 的下一局帶入金額剛好等於上一局帶回金額，間隔中位數 35 秒（25～85 秒）——籌碼每局整疊重鎖。

**三方送什麼**（316 筆無一例外）：

| 欄位 | 實際值 | 文件說 |
|---|---|---|
| `bet` | **負的**帶入金額（230/230 筆 = `-rollout.amount`） | 同局 rollout 帶入金額（正數） |
| `validbet` | 同 `bet`，負數 | 捕魚棋牌未定義 |
| `win` | = `amount` = 當前餘額 | 當前餘額；輸贏 = 當前餘額 − 帶入金額 |
| `betpoint` / `result` | 316 筆全 null | 僅限真人 |
| `rake` / `roomfee` | 全 0 | — |
| `roundnumber` | 直接複製 `round`，不是真局號 | 遊戲局號 |

**本平台寫出來的結果**（[AstarController.php:263-268](../../../../app/Http/Controllers/External/SingleWallets/AstarController.php#L263-L268)）：

```php
$validBetAmount = Common::numDiv($request->input('validbet'), $this->vendorInfo->rate);
$winLoseAmount  = Common::numDiv($request->input('win'), $this->vendorInfo->rate);
```

- `valid_bet` 變**負數** → 有效投注倒扣會員流水、活動投注統計、代理佣金與 SBR。
- `win_lose` 記成**帶回全額**而非淨輸贏（正確值應為 `win − bet`）。

**錢包餘額不受影響**：`BaseController::changeBalance()` 算的是前後差額，rollout 扣 `amount`、rollin 補 `getWin() − cashBet`，兩段相加等於帶回金額，玩家淨值正確。壞掉的只有注單欄位口徑與其下游報表。

**實際受影響資料（Yes2Win，2026-10-01 10:00 盤點）**：寫入負 `valid_bet` 的注單共 230 筆，`total_bet_records` 同步寫入同樣的負數。其中 226 筆已由人工分兩批批改，**剩 9/25 的 4 筆仍是負的**。

| 批次 | 筆數 | 修復時間 | 修成什麼 |
|---|---|---|---|
| 9/29 | 167 | 2026-09-30 04:12 | `bet = valid_bet = \|淨額\|`、`win_lose = ±淨額` |
| 9/28 | 59 | 2026-10-01 03:47 | 同上 |
| 9/25 | 4 | **未修** | 仍為負值 |

> 兩批修完的值經實測等同三方的 `validBetAmount`（有效投注／洗碼量），**不等於三方的 `betAmount`（真實投注額）**，詳見下方「棋牌真實投注額」一節。

## 細單鏈路現況（2026-10-01 查，尚未修）

新版文件提供了 **細單數據 API**（`GET {GAME_URL}/client/api/{GAMEHALL}/detail`），回傳 `AMOUNT`（下注金額）、`BETPOINT`、`WINPOINT`、`USER_RESULT`、`COMMAMOUNT`、`INNINGID`——正是捕魚棋牌回調缺的東西。Yes2Win 的呼叫位址為 `https://example.invalid/client/api/TH41/detail`（`GAMEHALL` 大寫）。

**2026-10-01 實打驗證（Yes2Win 正式）**：以四種 token（`bet_id`、`md5(bet_id)` 大小寫、注單的 `session_token`、文件範例 `lp63835`、空字串）呼叫 `https://example.invalid/client/api/TH41/detail`，全部回 `{"state":100007,"message":"服务器繁忙","value":null}`。GCP log（`gff-yes2win`，03:16:45Z）證實 **Astar 每一次都確實回呼了我方 `detailtoken`**（`POST /external/api/sw/astar/gamepool/th41/game/detailtoken`，UA `Apache-HttpClient/5.3 (Java/17.0.18)`，來源 `0.0.0.0`，帶對的 `Authorization`），而我方每一次都回 `code 2 Player not found or invalid`（空 token 那次回 `code 7`）。**失敗點在我方回調，不在 Astar。**

> 另外兩個實測得到的呼叫細節：① 請求**必須帶 `User-Agent`**，否則回 HTML 而非 JSON；② `state 100007` 是通用錯誤，無法從回應區分「token 無效」「回調失敗」「gamehall 不被允許」。

**三個前置都沒到位：**

1. **`detailtoken` 回調必定回 `PLAYER_NOT_FOUND(2)`**。[AstarController.php:83-84](../../../../app/Http/Controllers/External/SingleWallets/AstarController.php#L83-L84) 把 `detailtoken` 併進 `loadAccountAndVerify()`，而該方法在 [:377-379](../../../../app/Http/Controllers/External/SingleWallets/AstarController.php#L377-L379) 以 `request()->input('gametoken')` 找帳號——但 detailtoken 的請求規格**只有 `token`，沒有 `gametoken`**。Laravel 把 `where('session_token', null)` 轉成 `whereNull`，實跑 SQL 為 `session_token is not null and session_token is null`，命中 0 筆。
2. **token 語意用錯**。[:293](../../../../app/Http/Controllers/External/SingleWallets/AstarController.php#L293) 拿 `token` 比對 `session_token`，但文件定義 token 是「一個 token 對應一筆 roundid」的自訂識別碼（建議 `md5(roundid)`）。拿 session_token 當 key 的話，同一個 session 數十局共用一個值，`->first()` 會回任意一筆——查 A 局給 B 局。
3. **捕魚棋牌的 `AMOUNT` 語意未知**。文件範例是百家樂（`AMOUNT` = 押注額）；棋牌的 `AMOUNT` 是該局真實押注還是帶入金額，文件兩處都沒寫。

**這條路已不是取得棋牌投注額的必要前提**（改走注單查詢 API，見下節），但 1 與 2 仍是該修的 bug：細單頁打不開，客服與玩家查不了任何一筆 Astar 注單明細。修法是讓 `detailtoken` 改以可對應單一 roundid 的 token 反查注單、再由注單定位帳號，不要走 `loadAccountAndVerify()`。

## ✅ 棋牌真實投注額：有，走轉帳錢包注單查詢 API（2026-10-01 實測確認）

**結論：捕魚棋牌的真實投注額拿得到**，在 `getRecordByCondition` / `getRecordByMaxId` 的 `betAmount` 欄位。回調（rollin）永遠拿不到，但拉單 API 有。

**存取方式**：`https://example.invalid/channelApi/V3/API/TH41/...`，先 `GET .../auth?apiKey=[REDACTED] 換 Bearer token，再帶 `Authorization: Bearer {token}`。`apiKey` 不入 repo。詳見 `api.md`。

### 三方對一局棋牌給的三個數

| 三方欄位 | 語意 | 與我方資料的關係 |
|---|---|---|
| `betAmount` | **真實押注額** | 回調完全沒有，只能從這裡拿 |
| `validBetAmount` | **有效投注（洗碼量）** | 實測 **= \|淨輸贏\|**（9/28 59/59、9/29 167/168 成立） |
| `money` | **淨輸贏** | = 我方「rollin `win` − rollout `amount`」，9/28 58/59 逐筆吻合 |

對照用的欄位還有 `serviceMoney`（抽水）、`tableId`（= `tabletype`）、`cname`/`ename`（`newqznn` 經典搶莊牛牛、`brnn` 百人牛牛）。
**`roundId` 在棋牌恆為 `"0"`**，不能拿來對我方 `bet_id`；配對要用 `userName`（= `TH41` + `vendor_username`）＋ `createTime`（**北京時間**，減 8 小時換 UTC），實測 ±3 分鐘內可一對一配對成功。

### 三種口徑的量級差距（Yes2Win 實測）

| 口徑 | 9/28 窗（59 筆） | 9/29 窗（168 筆） |
|---|---|---|
| 帶入金額（**原本錯誤寫進 `bet` 的值**） | 12,738.49 | 389,524.04 |
| 我方 `bet` 現值（人工修過） | 2,133.13 | 16,065.78 |
| 三方 `validBetAmount`（有效投注） | 2,136.13 | 17,554.73 |
| 三方 `betAmount`（**真實投注額**） | **554.50** | **7,029.50** |

> **兩次人工修對齊的是「有效投注」不是「投注額」**：9/30 04:12 修 9/29、2026-10-01 03:47 修 9/28，兩批修完的 `bet` 都幾乎等於三方 `validBetAmount`（9/29 有 167/168 筆逐筆相等），而只有 16/168 筆等於 `betAmount`。所以目前 DB 裡棋牌的 `bet` / `valid_bet` 是**洗碼量口徑**。這是不是業務要的口徑，要與 PM 確認——代理佣金通常用有效投注，但「投注額」報表用的應該是 `betAmount`。

### 對不上的兩筆（待查）

- **9/28 `astar_bet_records.id = 133692`**（`TH41331790620719354`）：rollout 帶入 236.84、rollin `win` 也回 236.84（我方算淨 0），但三方注單是 `money = -3.0`、`betAmount = 1.0`。**三方的 rollin 回調與自己的注單互相矛盾**，差 3.00。
- **9/29 有一筆差 1,488.95**：167/168 筆的 `bet` 與 `validBetAmount` 相等，剩下那筆吃掉了整個差額。

## 兩條路的比較（細單 API vs 注單查詢 API）

| | 細單數據 API | 轉帳錢包注單查詢 API |
|---|---|---|
| 位址 | `{GAME_URL}/client/api/{GAMEHALL}/detail` | `https://example.invalid/channelApi/V3/API/TH41/...` |
| 取得欄位 | `AMOUNT`、`BETPOINT`、`USER_RESULT`、`COMMAMOUNT` | `betAmount`、`validBetAmount`、`money`、`serviceMoney` |
| 批量 | ✗ 一單一查 | ✅ 單次最多 200 筆 |
| 依賴我方回調 | ✅ 需 `detailtoken` 驗證（**現行必定失敗**） | ✗ 不需要 |
| 棋牌語意確定 | ✗ 文件只給百家樂範例 | ✅ `playType=4` 明載，且已實測 |
| 現況 | ❌ 打不通 | ✅ **2026-10-01 實測可用** |

**結論：走注單查詢 API，細單 API 不必再追。** `detailtoken` 壞掉仍是獨立的 bug（細單頁打不開，客服／玩家查不了注單明細），該修還是要修，但不再是取得棋牌投注額的必要前提。

## 實作對照重點

- **驗證流程**：`authenticate` / `rollout` / `detailtoken` 走 `loadAccountAndVerify()`（以帳號現行 `session_token` 對 `gametoken`）；`rollin` / `refund` 先 `authorizeGamehall()` 再 `loadAccountForSettlement()`（由注單反查帳號，見上節）。兩者最後都經 `verifyVendorInfoAndMember()` 校驗 `GAMEHALL`（須等於 URL 的 `gamehall` 大寫）與 `Authorization`（須等於 `CS_TOKEN`），任一不符回 `TOKEN_INVALID(4)`。
- **範圍外待補（審查 2026-09-06 提出）**：`roundcheck` 與 `logout` 不驗 `CS_TOKEN`（通過 IP 白名單即可列舉全部未結算 round）；`rollout` 未比對 `id` 與 token 解出的帳號是否同一人；帳號 `status=0` 時 rollin 會標已結算但不入帳（`BaseController::changeBalance`）；缺 ROLLOUT 帳齡告警與 refund 頻率告警。
- **Balance 額外檢核**：`member->status !== 1` 或 `sys_lock === 1` 或 `isMemberFlowLimitAllowed()` 未過 → `PLAYER_IS_FROZEN(6)`。
- **detailtoken**：以 `session_token = [REDACTED] 反查 BR 取 `bet_id` 作為 `roundid`——**語意錯誤且現行必定失敗**，見上方「細單鏈路現況」。

## 整合落地建議

| Astar 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Astar/Driver.php`（`AbstractSingleWallet`，僅 `getGameUrl`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/AstarController.php` |
| 回調路由 | `routes/external/sw.php`，prefix `astar/gamepool/{gamehall}` |
| `vendor_infos.info` | `GAME_URL` / `CS_TOKEN` / `GAMEHALL` / `CURRENCY`；**每站不同**，Yes2Win 實際值為 `https://example.invalid` / `TH41` / `MYR`（TPower 早期為 `TP`）。DTO `Astar/Datas/AstarVendorInfoDetail.php` |
| 帳號 Model | `App\Vendors\Models\AstarAccount`（`session_token` 作為遊戲 token） |
| BetRecord Model | `App\Vendors\Models\AstarBetRecord`；狀態 `Astar\Enums\BetRecordStatusEnum`（`ROLLOUT=0`/`ROLLIN=1`/`REFUND=9`） |
| 錯誤碼 | `App\Vendors\Astar\Enums\ErrorCodeEnum` |
| IP 白名單 | `Common::verifyIPWhitelistByVendorId(101)`；正式加白 `0.0.0.0` |
| 對帳保留期 | API 數據 **90 天**、前台 7 天；查詢時間條件為**投注時間** |

## gaps / 待 Astar 確認

- **Refund mtcode 格式**：已依實證改為「純 round 字串或三段式皆收」（見上方「MTcode 校驗」），不再要求 action 段為 `refund`。
- **簽章**：文件未定義，僅 Authorization Token + IP 白名單。
- **主動查詢**：~~Astar 端無「供本平台主動呼叫」的 API~~ —— 這句依 v3 文件寫的，**2026-10-01 已推翻**：有注單查詢 API（實測可用）與細單數據 API 兩組。
- **錯誤碼跳號** `48`/`51`/`52`/`54`：原因不明（見 api.md 錯誤碼表）。
- 🔴 **捕魚棋牌 `bet` 為什麼送負數**：文件寫正的帶入金額，實際 230 筆全送負值。需確認是 Astar 送錯還是文件漏寫符號。
- 🔴 **捕魚棋牌的 `validbet` 怎麼解讀**：回調送的是負的帶入金額，而拉單 API 的 `validBetAmount` 是 `|淨輸贏|`，**兩者差了一個量級且互不相等**。要確認回調的 `validbet` 到底代表什麼、以及哪一個才是對帳基準。
- 🔴 **棋牌該用哪個口徑**（**待 PM 拍板，不是問 Astar**）：三方同時給 `betAmount`（真實投注額）與 `validBetAmount`（有效投注／洗碼量），兩者量級差約 2.4～3.9 倍。代理佣金通常用有效投注，「投注額」報表該用 `betAmount`。目前 DB 裡是有效投注口徑。
- **細單數據 API 對捕魚棋牌的 `AMOUNT` 語意**：文件範例僅百家樂，棋牌未說明。優先序已降低（改走注單查詢 API）。
- **三方自我矛盾的一筆**：`TH41331790620719354`（9/28）rollin 回調說淨 0，注單說 `money = -3.0`。
- **連續同額全輸的異常樣態**：Yes2Win `TH41331790682983306`～`TH41331790683180385`（會員 `Y2WASTAR00004409`，2026-09-29 11:56–11:59）連續五局皆「帶入 1567.32、帶回 0」，且下一局帶入未接續上一局帶回。round 各自不同故非重送，但形狀可疑，尚未查明。
- **`betthreshold`（限紅）**：Auth 回應固定 `{maximum:0, minimum:0}`，尚無動態限紅來源。
