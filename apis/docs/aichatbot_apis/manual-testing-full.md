# Livechat Bot API 完整手動測試案例

完整端到端測試手冊，涵蓋環境準備、資料種子、各端點所有場景、log 驗證與清理。只想快速確認 7 支端點都通，直接跑[附錄 C 的一鍵 smoke 腳本](#附錄-c完整-smoke-test-一鍵腳本)。

---

## 0. 測試環境總覽

### 必要前置

```bash
# 1. 確認 nginx + php-fpm 正常運作
curl -I http://apis.online.localhost:8080/external/api/health 2>&1 | head -1

# 2. 跑 migration
docker exec -it php-fpm8 bash -c "cd /var/www/APIs && php artisan migrate"

# 3. 種子必要資料（若 DB 為空）
docker exec -it php-fpm8 bash -c "cd /var/www/APIs && php artisan db:seed --class=LanguageSeeder"
docker exec -it php-fpm8 bash -c "cd /var/www/APIs && php artisan db:seed --class=SyncAgentSettingDefinesSeeder"
```

### 通用環境變數

```bash
export BASE='http://apis.online.localhost:8080/external/api/api-callback/livechat/bot'
export API_KEY_A='test-key-A-12345'  # bot A 用
export API_KEY_B='test-key-B-67890'  # bot B 用（跨 agent 測試）
export AGENT_A_ID=1
export AGENT_B_ID=2
export USER_A='member_a'  # 屬於 agent A 的會員 username
export USER_B='member_b'  # 屬於 agent B 的會員 username
# 對於需要 member id 的 SQL/PHP 種子操作，可保留：
export USER_A_ID=88
export USER_B_ID=99
```

---

## 1. 測試資料準備

### 1.1 建立兩個 livechat bot

```sql
-- bot A：綁定 agent_id=1
INSERT INTO livechat_bots (name, agent_id, api_key, vip_url, is_active, created_at, updated_at)
VALUES ('test_bot_a', 1, 'test-key-A-12345', 'https://example.invalid/en-my/vip', 1, NOW(), NOW());

-- bot B：綁定 agent_id=2（跨 agent 測試用）
INSERT INTO livechat_bots (name, agent_id, api_key, vip_url, is_active, created_at, updated_at)
VALUES ('test_bot_b', 2, 'test-key-B-67890', 'https://example.invalid/en-my/vip', 1, NOW(), NOW());
```

### 1.2 確認測試會員存在

```sql
-- USER_A_ID 必須屬於 AGENT_A_ID
SELECT id, agent_id, username FROM members WHERE id IN (88, 99);

-- 確認 agent hierarchy
SELECT id, hierarchy_path FROM agents WHERE id IN (1, 2);
```

若會員不存在，可用 factory 建立（tinker）：
```php
$agent = \App\Models\Agent::find(1);
$member = \App\Models\Member::factory()->create(['agent_id' => $agent->id]);
echo $member->id;
```

---

## 2. 共通鑑權測試（套用於所有端點）

以 `get-balance` 示範。其他端點行為一致。

### 2.1 缺 Authorization header → 401

```bash
curl -s -X GET "$BASE/get-balance/$USER_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：
- HTTP 401
- Body：`{"code":401,"message":"Invalid or missing API key"}`

### 2.2 錯誤 Bearer token → 401

```bash
curl -s -X GET "$BASE/get-balance/$USER_A" \
  -H 'Authorization: Bearer WRONG' \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 401

### 2.3 停用 bot → 401

```sql
UPDATE livechat_bots SET is_active = 0 WHERE name = 'test_bot_a';
```
```bash
curl -s -X GET "$BASE/get-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 401

**測試後恢復**：
```sql
UPDATE livechat_bots SET is_active = 1 WHERE name = 'test_bot_a';
```

### 2.4 不存在的 username → 404

```bash
curl -s -X GET "$BASE/get-balance/no_such_user" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 404 + `Member not found`

### 2.5 跨 agent 存取 → 404（核心安全測試）

```bash
# bot A 的 key 查 agent B 的會員
curl -s -X GET "$BASE/get-balance/$USER_B" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 404（不洩漏會員實際存在）

**驗證點**：訊息應為 `Member not found`，**不可**含「屬於別 agent」「unauthorized」等暗示性字眼。

### 2.6 username 格式錯誤 / 缺失 → 404

```bash
# username 含非法字元（如 `$`、空白）
curl -s -X GET "$BASE/get-balance/bad%20user" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 404（path regex `[A-Za-z0-9_.-]+` 不匹配 → Laravel route 視為 NotFound）

---

## 3. get-balance（餘額查詢）

### 端點
```
GET /external/api/api-callback/livechat/bot/get-balance/{username}
```

### 3.1 單錢包會員

**前置**：
```sql
-- 確認會員 main_wallet 餘額及所屬 agent 的多錢包設定
-- 多錢包設定存在 agent_customized_settings (setting_key = 6008 / MULTIPLE_WALLET_SWITCH)，
-- 透過 agents.customized_setting_group_id 對應
SELECT m.id, m.balance, COALESCE(acs.value, '0') AS is_multi_wallet
FROM members m
JOIN agents a ON a.id = m.agent_id
LEFT JOIN agent_customized_settings acs
  ON acs.agent_group_id = a.customized_setting_group_id
 AND acs.setting_key = 6008
WHERE m.id = 88;

-- 若需設定餘額：
UPDATE members SET balance = '420.00' WHERE id = 88;
```

> ⚠️ `Agent::is_multi_wallet` 是 model accessor，實際讀 `agent_customized_settings` 表（key=6008）。同一個 customized_setting_group 旗下所有 agent / member 共用此設定。

**Request**：
```bash
curl -s -X GET "$BASE/get-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**預期 Response**（重點欄位）：
```json
{
  "status": true,
  "code": 200,
  "msg": "Success",
  "output": {
    "main_wallet_balance": "420.00",
    "sum_provider_wallet_balance": "0.00",
    "total_wallet_balance": "420.00",
    "rolling_chips": "...",
    "event_wallet_balance": "...",
    "in_promo": false,
    "description": "Member ID: 88, Wallet Type: Single Wallet",
    "message": [...]
  },
  "error": []
}
```

**驗證 checklist**：
- [ ] HTTP 200
- [ ] `output.main_wallet_balance` 為字串
- [ ] **無** `output.game_wallet_balance` 欄位
- [ ] `output.message` 為字串陣列
- [ ] `output.message` 不含 `"Game Wallet Balance"` 字串

### 3.2 多錢包會員

**前置**（影響整個 customized_setting_group 旗下所有會員）：
```sql
INSERT INTO agent_customized_settings (agent_group_id, setting_key, value, created_at, updated_at)
SELECT a.customized_setting_group_id, 6008, '1', NOW(), NOW()
FROM agents a
JOIN members m ON m.agent_id = a.id
WHERE m.id = 88
ON DUPLICATE KEY UPDATE value = '1', updated_at = NOW();
```

**Request**：同 3.1

**預期**：
- `output.game_wallet_balance` 存在
- `output.total_wallet_balance` 包含 game_wallet 加總
- `output.message` 含 `"Game Wallet Balance: ..."`

### 3.3 會員有活動進行中（in_promo）

**前置**：
```sql
UPDATE members SET is_event_v2 = 1 WHERE id = 88;
```

**預期**：`output.in_promo` 為 `true`，`message` 含 `"In Promo: Yes"`

---

## 4. inquiry-deposit-withdrawal（存提紀錄）

> `transaction_type` 為 URL path segment，值為 `deposit` 或 `withdraw`（route 約束），一次只查單一類型；`output` 為單一陣列（非 deposit/withdraw 兩個 key）。

### 4.1 有紀錄會員（deposit）

**前置**：使用既有有交易紀錄的會員，或：
```php
// tinker
$member = \App\Models\Member::find(88);
\App\Models\Order::factory()->count(3)->create(['member_id' => $member->id]);
```

**Request**：
```bash
curl -s -X GET "$BASE/inquiry-deposit-withdrawal/$USER_A/deposit" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**驗證**：
- [ ] `output` 為 array，含創建的訂單
- [ ] 每筆有 `id`、`status`、`currency`、`amount`、`user_received_amount`、`processing_at`、`remark`、`message`
- [ ] 排序為 id DESC

### 4.2 有紀錄會員（withdraw）

**前置**：
```php
\App\Models\WithdrawalOrder::factory()->count(2)->create(['member_id' => 88]);
```

**Request**：
```bash
curl -s -X GET "$BASE/inquiry-deposit-withdrawal/$USER_A/withdraw" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**驗證**：
- [ ] `output` 為 array，含創建的提款單
- [ ] `remark` 對應 `withdrawal_orders.memo`

### 4.3 紀錄超過 50 筆

**前置**：建 60 筆 Order
```php
\App\Models\Order::factory()->count(60)->create(['member_id' => 88]);
```

**驗證**（用 `/deposit` segment 查）：
- [ ] `output` 最多 50 筆
- [ ] 取最新 50 筆（id DESC）

### 4.4 無紀錄會員

**前置**：清乾淨
```sql
DELETE FROM orders WHERE member_id = 88;
DELETE FROM withdrawal_orders WHERE member_id = 88;
```

**驗證**：deposit 與 withdraw 兩種查詢皆回 `output: []`

### 4.5 缺 segment / 非法值

| Request | 預期 |
|---|---|
| 缺 `transaction_type` segment（如 `/inquiry-deposit-withdrawal/$USER_A`） | 404（route 不匹配） |
| `transaction_type` segment 為非法值（如 `/inquiry-deposit-withdrawal/$USER_A/invalid`） | 404（path constraint 不匹配） |

---

## 5. get-withdraw-balance（提款資格）

### 5.1 餘額未達門檻

**前置**：
```sql
UPDATE members SET balance = '5.00' WHERE id = 88;  -- 假設門檻 10
```

**Request**：
```bash
curl -s -X GET "$BASE/get-withdraw-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**預期**：
- `output.eligibility` 為 `"NotEligibleByMinAmount"` 或對應 enum 字串
- `output.message` 含原因說明

### 5.2 流水未達

**前置**：餘額足夠但未完成流水
```php
$member = \App\Models\Member::find(88);
$member->update(['balance' => '500.00']);
// 假設有未完成流水紀錄
```

**預期**：`eligibility = NotEligibleByFlow`

### 5.3 有活動進行中

**前置**：建立 EventV2UserJoin status=JOIN
**預期**：`eligibility = InProgressEvent`

### 5.4 完全可提款

**預期**：`eligibility = Eligible`，`output.withdraw_balance > 0`

---

## 6. promotions（活動列表）

### 6.1 完全無活動

**前置**：
```sql
DELETE FROM event_v2_user_joins WHERE member_id = 88;
DELETE FROM prize_members WHERE member_id = 88;
```

**Request**：
```bash
curl -s -X GET "$BASE/promotions/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**預期**：
```json
{
  "output": {
    "activePromotions": [],
    "claimablePromotions": [],
    "conditionedPromotions": []
  }
}
```

### 6.2 有 v2 進行中活動

**前置**：建立 EventV2 + EventV2Detail + EventV2UserJoin (status=JOIN)
```php
// tinker
$event = \App\Models\EventV2::create(['name' => 'Test Event', 'handler_class' => 'WelcomeBonus50Service']);
$detail = \App\Models\EventV2Detail::factory()->create([
    'event_v2_id' => $event->id,
    'agent_id' => 1,
    'start_time' => now()->subDay(),
    'end_time' => now()->addDays(30),
]);
\App\Models\EventV2DetailLang::create([
    'event_v2_detail_id' => $detail->id,
    'language_id' => 1,
    'name' => 'Test Promo'
]);
\App\Models\EventV2UserJoin::create([
    'member_id' => 88,
    'event_v2_detail_id' => $detail->id,
    'reward' => 100,
    'flow_limit' => 1500,
    'balance_limit' => 300,
    'withdraw_limit' => 500,
    'status' => \App\Enums\EventV2\EventV2UserJoinStatusEnum::JOIN->value,
]);
```

**驗證**：
- [ ] `activePromotions` 含 1 筆
- [ ] 該筆 `promotionSource = "v2"`
- [ ] 含 `applyId`, `joinStatus`, `reward`, `turnoverProgress` 等欄位

### 6.3 v1 PrizeMember 未領

**前置**：
```php
$event = \App\Models\Event::create(['title' => 'V1 Event']);
$prizeMember = \App\Models\PrizeMember::create([
    'member_id' => 88,
    'event_id' => $event->id,
    'lottery_point' => 100,
    'status' => \App\Enums\Event\PrizeMemberStatusEnum::UNAWARD->value,
]);
```

**驗證**：
- [ ] `claimablePromotions` 含該筆
- [ ] `promotionSource = "v1"`
- [ ] 含 `applyId` (PrizeMember.id), `rewardAmount`

### 6.4 v2 驗證失敗（conditioned）

**前置**：建立 EventV2 但會員餘額不足、IP 被 block 等
**驗證**：
- [ ] `conditionedPromotions` 含該筆
- [ ] 含 `nonQualify[]` 失敗原因陣列

---

## 7. referral-progress（推薦進度）

### 7.1 會員無 MemberCode（首次查詢）

**前置**：
```sql
DELETE FROM member_codes WHERE member_id = 88;
```

**Request**：
```bash
curl -s -X GET "$BASE/referral-progress/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**驗證**：
- [ ] HTTP 200
- [ ] `output.referral_code` 為字串（自動建立）
- [ ] DB 有新增 `member_codes` row

### 7.2 無進行中 InviteFriend 活動

**前置**：確認沒有 EventV2 with handler_class='InviteFriendService' active

**預期**：
```json
{
  "output": {
    "referral_code": "XXXXXX",
    "register_referral_apply": {
      "current_count": 0,
      "target_count": 0,
      "reward_on_completion": "0.00",
      "downlines": [],
      "message": ["Referral Code: XXXXXX", "No active referral promotion"]
    }
  }
}
```

### 7.3 有活動 + 部分達標

**前置**：建立 InviteFriend 活動 + 部分下線會員（invitation_code = 上線 agent_code）

**驗證**：
- [ ] `current_count` = 篩選去重後的下線數
- [ ] `downlines` 含已註冊會員 username
- [ ] `message` 含 `"Complete N more registration referral to get bonus"`

### 7.4 已達標

**驗證**：
- [ ] `message` 含 `"Bonus reached!"`

---

## 8. get-vip-setting（VIP 設定）

### 8.1 會員無 MemberVip → 404

**前置**：
```sql
DELETE FROM member_vips WHERE member_id = 88;
```

**Request**：
```bash
curl -s -X GET "$BASE/get-vip-setting/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 404 + `Member VIP not found`

### 8.2 最低等級會員（VIP0）

**前置**：
```php
$vipContent = \App\Models\VipContent::create([
    'agent_id' => 1, 'level' => 0, 'name' => 'VIP 0',
    'target_amount' => 0, 'keep_amount' => 0,
]);
\App\Models\MemberVip::create([
    'member_id' => 88, 'vip_content_id' => $vipContent->id,
    'deposit_amount' => 0, 'keep_amount' => 0,
]);
```

**驗證**：
- [ ] `output.vipLevel = "VIP0"`
- [ ] `output.vipDailyBonus = "0.0000"`（無 reward 配置）
- [ ] `output.url` = bot 的 vip_url
- [ ] 8 個獎金欄位皆為 4 位小數字串

### 8.3 有下一級的中間等級

**前置**：建立 VIP0 + VIP1 + VipContentReward
**驗證**：
- [ ] `output.vipUpgradeBonus` 為下一級的達標獎金（4 位小數）
- [ ] `output.upgradeProgress` 格式 `"X.XXXX / Y.YYYY"`

### 8.4 最高等級（無下一級）

**前置**：會員為該 agent 的最高 VIP level
**驗證**：
- [ ] `output.vipUpgradeBonus = "0.0000"`
- [ ] `output.upgradeProgress = "0.0000 / 0.0000"`
- [ ] `message` 含 `"Vip Upgrade Progress: 0.0000 / 0.0000"`

### 8.5 8 種 reward 全配置

**前置**：建立全部 8 個 vip_content_rewards
**驗證**：
- [ ] 對應 7 個 enum 欄位（DAILY_REBATE / WEEKLY_GIFT / MONTHLY_GIFT / BIRTHDAY / COMMEMORATE / WEEKLY_RESCUE / UPGRADE）皆有非零值
- [ ] `vipDailyAidBonus` 與 `vipMonthlyAidBonus` 仍為 `"0.0000"`（規格無對應 vip_reward_id）

---

## 9. get-promotion-detail（關鍵字搜尋）

### 9.1 缺 promotionName segment → 404

```bash
curl -s -X GET "$BASE/get-promotion-detail/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' -w "\nHTTP %{http_code}\n"
```

**預期**：HTTP 404（path segment `promotionName` 缺漏，route 不匹配）

### 9.2 關鍵字無命中

**Request**：
```bash
curl -s -X GET "$BASE/get-promotion-detail/$USER_A/NEVER_EXIST_XYZ" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq
```

**預期**：`output: []`

### 9.3 v1 + v2 混合命中

**前置**：v1 Event title=`FREE100`、v2 EventV2 name=`FREE200`，會員可參加兩者
**Request**：`promotionName: "FREE"`
**驗證**：
- [ ] `output` 為扁平 array 含 2 筆
- [ ] 每筆有 `eligibilityStatus` (`active` / `claimable` / `conditioned`)
- [ ] 各筆有 `promotionSource` (`v1` / `v2`)

### 9.4 大小寫不敏感

**前置**：v2 EventV2 name=`FREE100`
**Request**：`promotionName: "free"`
**驗證**：應該命中（MySQL 預設 collation 不分大小寫）

### 9.5 多語言命中

**前置**：EventV2 name=`Welcome` + EventV2DetailLang name=`歡迎獎金`
**Request**：`promotionName: "歡迎"`
**驗證**：應命中

---

## 10. 跨端點整合測試

### 10.1 會員一致性

**情境**：對同一會員依序呼叫多個端點，回應應反映同一狀態。

```bash
# 取餘額
curl -s -X GET "$BASE/get-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq '.output.main_wallet_balance'

# 取提款資格
curl -s -X GET "$BASE/get-withdraw-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json' | jq '.output.withdraw_balance'

# get-balance 的 main_wallet_balance 應與 get-withdraw-balance 邏輯一致（依業務規則）
```

### 10.2 跨 bot 鑑權矩陣

| Bot | 查 `$USER_A` (agent A) | 查 `$USER_B` (agent B) |
|---|---|---|
| bot A | 200 | 404 |
| bot B | 404 | 200 |

逐一執行驗證（範例）：

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X GET "$BASE/get-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_A" -H 'Accept: application/json'
curl -s -o /dev/null -w "%{http_code}\n" -X GET "$BASE/get-balance/$USER_B" \
  -H "Authorization: Bearer $API_KEY_A" -H 'Accept: application/json'
curl -s -o /dev/null -w "%{http_code}\n" -X GET "$BASE/get-balance/$USER_A" \
  -H "Authorization: Bearer $API_KEY_B" -H 'Accept: application/json'
curl -s -o /dev/null -w "%{http_code}\n" -X GET "$BASE/get-balance/$USER_B" \
  -H "Authorization: Bearer $API_KEY_B" -H 'Accept: application/json'
```

---

## 11. Log 與監控驗證

### 11.1 確認 bot context 寫入 log

開另一個 terminal：
```bash
docker exec -it php-fpm8 bash -c "tail -f /var/www/APIs/storage/logs/laravel.log" | grep -E "livechat_bot"
```

呼叫任一端點，應看到含 `livechat_bot_name` 與 `livechat_bot_agent_id` 的 log。

### 11.2 401 / 404 應記錄

**驗證**：
- 401 應有 access deny log（middleware 寫入）
- 404 應有 ApiException log

---

## 12. 收尾清理

```sql
-- 刪除測試 bot
DELETE FROM livechat_bots WHERE name LIKE 'test_bot_%';

-- 還原會員測試資料（按需要）
UPDATE members SET is_event_v2 = 0 WHERE id IN (88, 99);

-- 還原多錢包設定（agent_customized_settings.setting_key = 6008）
UPDATE agent_customized_settings
SET value = '0', updated_at = NOW()
WHERE setting_key = 6008
  AND agent_group_id IN (
    SELECT a.customized_setting_group_id FROM agents a
    JOIN members m ON m.agent_id = a.id
    WHERE m.id IN (88, 99)
  );

-- 清測試交易紀錄
DELETE FROM orders WHERE member_id IN (88, 99) AND created_at > '2026-05-01';
DELETE FROM withdrawal_orders WHERE member_id IN (88, 99) AND created_at > '2026-05-01';
```

---

## 附錄 A：HTTP 狀態碼速查

| 狀態碼 | 情境 |
|---|---|
| 200 | 成功 |
| 400 | 其他 request 驗證失敗（一般情況；`transaction_type` / `promotionName` 已改為 path segment 故落 404） |
| 401 | Bearer token 缺失 / 錯誤 / bot 停用 |
| 404 | username 不存在、不符 path regex、跨 agent 或缺對應記錄（如 MemberVip）、`transaction_type` 不是 `deposit`/`withdraw`、缺 `promotionName` segment |
| 500 | 伺服器例外（應 log 追蹤） |

## 附錄 B：常見問題排查

| 症狀 | 可能原因 | 處理 |
|---|---|---|
| 全部端點 401 | 未建 livechat_bot row | 檢查 `SELECT * FROM livechat_bots` |
| 200 但欄位都 0 | 缺 seed 資料 | 跑 LanguageSeeder / SyncAgentSettingDefinesSeeder |
| 404 出現在預期 200 | bot 與 user 跨 agent | 確認 bot.agent_id 與 member.agent_id 同一 hierarchy |
| referral_code 每次不同 | 預期行為（lazy creation 後續會固定） | 用 `SELECT * FROM member_codes` 確認 |

## 附錄 C：完整 smoke test 一鍵腳本

```bash
#!/bin/bash
# 跑完所有端點，預期全 200
set -e

# 只需 username path 的端點
for endpoint in get-balance get-withdraw-balance \
                 promotions referral-progress get-vip-setting; do
  printf "%-30s " "$endpoint"
  STATUS=$(curl -s -o /tmp/resp.json -w "%{http_code}" -X GET "$BASE/$endpoint/$USER_A" \
    -H "Authorization: Bearer $API_KEY_A" \
    -H 'Accept: application/json')
  echo "HTTP $STATUS - $(jq -r '.msg // .message' < /tmp/resp.json)"
done

# inquiry-deposit-withdrawal 額外需要 transaction_type (path segment)
for tt in deposit withdraw; do
  printf "%-30s " "inquiry-deposit-withdrawal($tt)"
  STATUS=$(curl -s -o /tmp/resp.json -w "%{http_code}" -X GET "$BASE/inquiry-deposit-withdrawal/$USER_A/$tt" \
    -H "Authorization: Bearer $API_KEY_A" \
    -H 'Accept: application/json')
  echo "HTTP $STATUS - $(jq -r '.msg // .message' < /tmp/resp.json)"
done

printf "%-30s " "get-promotion-detail"
STATUS=$(curl -s -o /tmp/resp.json -w "%{http_code}" -X GET "$BASE/get-promotion-detail/$USER_A/FREE" \
  -H "Authorization: Bearer $API_KEY_A" \
  -H 'Accept: application/json')
echo "HTTP $STATUS - $(jq -r '.msg // .message' < /tmp/resp.json)"
```
