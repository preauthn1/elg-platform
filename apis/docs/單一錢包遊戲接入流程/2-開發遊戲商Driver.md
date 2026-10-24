# 二、開發遊戲商所屬的服務器 Driver

> ← [遊戲商接入完整操作流程](index.md)

> 本章為新手導引。詳細架構規範（Migration 設計、並發帳號綁定、轉帳結果規則等）以 game-integration skill 為**權威來源**，有衝突時以 skill 為準。skill 放在 GFF-Marketplace repo 的 `plugins/apis-toolkit/skills/game-integration/SKILL.md`；在 Claude Code 裡說「接遊戲商」「新增遊戲商」會自動載入。

## 帳號狀態驗證（必要，所有涉及餘額的 API）

在**所有**會讀取或異動餘額的回調方法（`balance`、`bet`、`settle`、`refund` 等）最頂端，必須先驗證帳號狀態：

```php
if ($this->account->status == 0) {
    return $this->errorResponse(/* 依遊戲商 API 文件的「玩家不存在/帳號停用」錯誤碼 */);
}
```

> `status == 0` 代表帳號已被 activity 系統注銷，必須拒絕所有後續操作。  
> **錯誤碼依各遊戲商 API 對接文件決定**，不可寫死固定值。

## 單一錢包投注前資格檢查（`bet` 專用）

在單一錢包的 `bet` 類 API 進行扣款前，需在帳號狀態驗證之後，額外呼叫 `BetHelper::isMemberAbleToBet($member, $vendorInfo, $game)`。

> ⚠️ `isMemberAbleToBet` 是 `bet` 專用，`settle` 與 `refund` 不需要呼叫（派彩/取消不需要再次作資格過濾）。

建議放置順序：
1. 完成請求驗證與簽名驗證。
2. 驗證 `account.status`（見上節）。
3. 取得 `member`、`vendorInfo`、`gameId`/`Game`。
4. 呼叫 `BetHelper::isMemberAbleToBet(...)`。
5. 若回傳 `false`，直接回遊戲商錯誤碼（例如 `BET_IS_NOT_ALLOWED`），不要進入 `changeBalance()`。

```php
if ($this->account->status == 0) {
    return $this->errorResponse(ErrorCodeEnum::USER_NOT_FOUND);
}

$isAbleToBet = BetHelper::isMemberAbleToBet($this->member, $this->vendorInfo, $request->input('gameId'));
if (!$isAbleToBet) {
    return $this->errorResponse(ErrorCodeEnum::BET_IS_NOT_ALLOWED);
}
```

## `isMemberAbleToBet` 實際檢查項目

以 `app/Helpers/BetHelper.php` 為準。檢查邏輯寫在 `assertMemberAbleToBet()`，有三種用法：

- `BetHelper::assertMemberAbleToBet(Member $member, VendorInfo $vendorInfo, Game|string $game, ?object $caller = null)`：任一項不通過就拋 `BetException`，例外訊息是下方各項括號內的英文字串。
- `BetHelper::isMemberAbleToBet(...)`（參數相同）：呼叫上一支，攔下 `BetException` 後記一筆 warning log（前綴 `[BetHelper][isMemberAbleToBet]`），再回傳 `false`。
- 無縫錢包回調 Controller 呼叫 `BaseController::verifyBettability($gameCode)`。它直接呼叫 `assertMemberAbleToBet()`，並把 Controller 自己傳進 `$caller`。

第 4 個參數 `$caller` 決定遊戲可用性怎麼檢查。`ShouldVerifyGameBettability` 是一個沒有方法的標記介面（位於 `app/Http/Controllers/External/SingleWallets/Contracts/`）。`$caller` 有實作它時走「簡化路徑」，否則走「一般路徑」。

依執行順序：

1. 會員狀態：`status` 必須是 `MemberStatusEnum::VERIFIED`，且 `sys_lock` 不是 1（`Member is locked or inactive`）。
2. 商戶狀態：`vendor_info.status === 1`（`Vendor is not available`）。
3. 遊戲存在：`$game` 傳字串時，以該商戶的 `vendor_id` 加 `games.game_type` 查遊戲，查不到就擋（`Game not found`）。
4. 遊戲商狀態：`vendors.status === 1`（`Vendor is not available`）。
5. 遊戲商維護中：`vendorLastMaintenance` 的維護類型是下列任一種，而且套用到這位會員的代理鏈（`agent_id` 在代理鏈內、`exclude_agent_id` 不在代理鏈內），一律擋（`Vendor is under maintenance`）：
   - `BOTH_CLOSED`：遊戲與錢包都關閉
   - `GAME_CLOSED`：關閉遊戲
   - `WALLET_CLOSED`：關閉錢包
6. 代理維護中：`AgentService::getMaintainAgents()` 回傳的維護中代理，有任一個在會員的代理鏈上（`Agent is under maintenance`）。
7. 遊戲可用性，依 `$caller` 分兩條路：
   - 簡化路徑（`$caller` 有實作 `ShouldVerifyGameBettability`）：只看該商戶的 `vendor_info_games` 有沒有這款遊戲，且 `bettable = 1`（`Game is not bettable`）。不做下面一般路徑的三項檢查。
   - 一般路徑（`assertRegularGameAvailability()`）：
     - 遊戲啟用：`games.status === 1`（`Game not available`）。大廳類遊戲商不檢查這項。大廳類遊戲商指 `vendor_infos.info` 的 `singleGame` 為 1，這類遊戲的 `status` 會設成 0。
     - 商戶遊戲配置：該商戶的 `vendor_info_games` 要有這款遊戲且 `status = 1`（`Vendor info game is not available`）。只有「會員正在使用活動錢包」而且「是大廳類遊戲商」時才跳過。「使用活動錢包」指 `Member` 的 `is_event_wallet` 為 true，也就是參加了活動 V2，或新人專享挑戰進行中。
     - 代理遊戲配置：`AgentGameService::getValidAgentGames()` 要有資料（`Agent game is not available`）。大廳類遊戲商不檢查這項。
8. 代理可用商戶：`VendorService::getVendorInfoIds()` 算出的可用商戶清單要包含當前 `vendor_info`（`Vendor info is not available`）。
9. 代理鏈鎖定：代理鏈上任一代理 `status != 1`（`Agent is locked`）。
10. 鎖分模式：`isMemberFlowLimitAllowed()` 不通過（`Member is not allowed by flow limit`），見下一節。
11. 未領取的活動獎品：`hasUnclaimedPrize()` 為真（`Member has unclaimed prize`）。只在代理設定 `AgentCustomizedSettingKeyEnum::PRIZE_REMIND` 為 1 時檢查，查的是活動 V1 的獎品。代理開了這個設定，會員有獎品沒領就整個不能下注。

## `isMemberFlowLimitAllowed` 補充

當會員在鎖分模式（`event_mode > 0`）時，會依 `prize_content_flow_limits` 判斷該會員是否允許在此遊戲商/遊戲類型下注。

- 命中條件時才允許下注。
- 不命中時 `isMemberAbleToBet()` 會回傳 `false`。
- 轉帳流程可傳 `game = null`，會走 `vendor_customized_items` 類型判斷。

## 排錯建議

若遊戲商回報無法下注，先查應用程式 log 的下列關鍵字。這些 log 只有呼叫 `isMemberAbleToBet()` 的地方才會出現。走 `verifyBettability()` 的 Controller，`BetHelper` 不記 log，例外訊息由 Controller 的 catch 接走，要看該 Controller 有沒有自己記下來（例如 `AcewinController` 只回錯誤碼，沒有記訊息）。

- `[BetHelper][isMemberAbleToBet] Member is locked or inactive`
- `[BetHelper][isMemberAbleToBet] Vendor is under maintenance`
- `[BetHelper][isMemberAbleToBet] Agent game is not available`
- `[BetHelper][isMemberAbleToBet] Vendor info game is not available`

可快速定位是會員、代理、商戶、遊戲、維護或鎖分規則造成。

## 常見情境與建議錯誤碼

以下以單一錢包 `bet` 流程為主，供接入與排錯時快速對照（實際仍以各遊戲商 `ErrorCodeEnum` 為準）。

| 類別 | 常見情境 | 建議錯誤碼 |
| --- | --- | --- |
| 驗證/身份 | IP 不在白名單、簽名錯誤 | `VERIFICATION_FAILED` |
| 驗證/身份 | 找不到會員、帳號停用 | `USER_NOT_FOUND` |
| 帳號停用 | `account.status == 0` | `USER_NOT_FOUND`（或遊戲商文件之對應碼） |
| 下注資格 | `isMemberAbleToBet()` 任一條件不通過（會員/代理/商戶/遊戲/維護/鎖分） | `BET_IS_NOT_ALLOWED` |
| 請求格式 | 參數驗證失敗、金額非法（例如下注金額小於 0） | `REQUEST_PARAMETER_ERROR` |
| 交易冪等 | `transactionId` 重複呼叫 | `REPEAT_REQUEST` |
| 資金不足 | 錢包餘額不足 | `BET_IS_NOT_ALLOWED`（現況） |
| 系統例外 | DB/程式例外、不可預期錯誤 | `SYSTEM_ERROR` |

### `BetHelper::isMemberAbleToBet` 會擋下的典型情境

1. 會員未驗證、被系統鎖定。
2. `vendor_info` 停用或 `vendor` 停用。
3. 遊戲不存在。
4. 遊戲商在維護時段（三種維護類型都擋，含代理層級的排除條件）。
5. 會員的代理鏈上有代理正在維護。
6. 簡化路徑下，該商戶的 `vendor_info_games.bettable` 不是 1。
7. 一般路徑下，遊戲停用（大廳類遊戲商除外）。
8. 一般路徑下，商戶未配置該遊戲（`vendor_info_games`）。使用活動錢包的會員在大廳類遊戲商例外。
9. 一般路徑下，代理未配置該遊戲（`agent_games` 無有效資料；大廳類遊戲商除外）。
10. 代理未配置該商戶（`vendor_info` 不在可用清單）。
11. 代理鏈路有任一代理被鎖定。
12. 鎖分模式下，`prize_content_flow_limits` 不允許此遊戲商/遊戲類型。
13. 代理開了領獎提醒（`PRIZE_REMIND`），會員還有活動獎品沒領。

> 建議：對外回碼可維持精簡（例如統一 `BET_IS_NOT_ALLOWED`），但 log 請保留 `BetHelper` 原始 warning 訊息，方便 OP 與 RD 快速定位根因。

## `settle` / `refund` 實作情境（以 VplusSW 現況為例）

> 以下為 VplusSW 的具體實作，不同遊戲商設計可能不同，請依各自 API 文件調整。

### 分流規則

- `bet` API：當 `completed=true` 時，會改走 `handleSettle()`。
- `settle` API：當 `type===101` 時，會改走 `handleRefund()`；否則走 `handleSettle()`。

### Settle（派彩）

- 以 `transactionId` 作為冪等鍵（對應 `bet_id`）。
- 若 `bet_id` 已存在，回 `REPEAT_REQUEST`。
- 派彩金額由 `balance / rate` 換算為 `win`。
- **VplusSW 特定行為**：`win > 0` 才建立 `total_bet_records`；`win = 0` 僅更新錢包與遊戲注單。其他遊戲商是否採用相同邏輯需依文件確認。

### Refund（取消）

- 以 `gameRoundId`（對應 `reference_bet_id`）批次查詢原注單。
- 逐筆處理：把原注單 clone 成退款注單（`status=REFUND`），並執行 `changeBalance()`。
- 已是 `REFUND` 的舊注單會略過，不重複退款。

### 查無原注單的現況

- 若 `gameRoundId` 查不到任何原注單，現況會直接回成功（`SUCCESS`），不丟錯。
- 若查到的注單都已退款，現況同樣回成功。

> 上述為目前 `VplusController` 實際行為；若後續要改成「查無原注單回錯誤碼」，請同步更新本文件。

## `bet` / `settle` / `refund` 常見情境總表

| API | 情境 | 建議錯誤碼 | 備註 |
| --- | --- | --- | --- |
| 全部 | `account.status == 0` | `USER_NOT_FOUND`（或對應碼） | 所有涉及餘額的 API 都要先判斷 |
| `bet` | IP 白名單/簽名驗證失敗 | `VERIFICATION_FAILED` | 前置驗證失敗 |
| `bet` | 會員不存在或停用 | `USER_NOT_FOUND` | 前置驗證失敗 |
| `bet` | 下注資格不符（`isMemberAbleToBet=false`） | `BET_IS_NOT_ALLOWED` | 含會員/代理/商戶/遊戲/維護/鎖分 |
| `bet` | 請求參數錯誤、金額非法 | `REQUEST_PARAMETER_ERROR` | 例如金額小於 0 |
| `bet` | `transactionId` 重複 | `REPEAT_REQUEST` | 冪等重複 |
| `bet` | 餘額不足 | `BET_IS_NOT_ALLOWED` | 現況與資格不符共用同碼 |
| `settle` | `transactionId` 重複 | `REPEAT_REQUEST` | 冪等重複 |
| `settle` | 請求參數錯誤 | `REQUEST_PARAMETER_ERROR` | 驗證規則不符 |
| `refund` | 以 `gameRoundId` 找不到原注單 | `SUCCESS`（現況） | 無異動但回成功 |
| `refund` | 原注單已全數退款 | `SUCCESS`（現況） | 逐筆略過已退款注單 |
| `bet/settle/refund` | DB/程式例外 | `SYSTEM_ERROR` | catch throwable |
