# Get VIP Setting

查詢指定會員的 VIP 設定，包含目前等級資訊、各類獎金、升級進度，以及對應的 VIP 介紹頁 URL。

## Endpoint

```
GET /external/api/api-callback/livechat/bot/get-vip-setting/{username}
```

路由名稱：`external.livechat-bot.get-vip-setting`

## Headers

| Header | 必填 | 值 |
|---|---|---|
| `Authorization` | 是 | `Bearer <livechat_bot_api_key>` |
| `Accept` | 是 | `application/json` |

## Path Parameters

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | 是 | 會員帳號（`members.username`）。允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 |

## Response 包裝

採用 Livechat Bot 統一的 `{status, code, msg, output, error}` 格式：

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": {
        "id": 7,
        "vipLevel": "VIP0",
        "name": "VIP 0",
        "vipDailyBonus": "2.0000",
        "vipMonthlyBonus": "0.0000",
        "vipWeeklyBonus": "0.0000",
        "vipDailyAidBonus": "0.0000",
        "vipWeeklyAidBonus": "0.0000",
        "vipMonthlyAidBonus": "0.0000",
        "vipBirthdayBonus": "1.0000",
        "upgradePoints": "0.0000",
        "vipUpgradeBonus": "0.0000",
        "maintainPoints": "0.0000",
        "vipHolidayBonus": "0.0000",
        "upgradeProgress": "0.0000 / 3.0000",
        "url": "https://example.invalid/en-my/vip",
        "message": [
            "Vip Level: VIP0",
            "Vip Name: VIP 0",
            "Vip Daily Bonus: 2.00",
            "Vip Weekly Bonus: 0.00",
            "Vip Monthly Bonus: 0.00",
            "Vip Daily Aid Bonus: 0.00",
            "Vip Weekly Aid Bonus: 0.00",
            "Vip Monthly Aid Bonus: 0.00",
            "Vip Birthday Bonus: 1.00",
            "Vip Holiday Bonus: 0.00",
            "Vip Maintain Points: 0.00",
            "Next Level Vip Upgrade Bonus: 0.00",
            "Vip Upgrade Progress: 0.0000 / 3.0000",
            "Vip Setting Refer URL: https://example.invalid/en-my/vip"
        ]
    },
    "error": []
}
```

## Glossary

### `output` 欄位定義

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | integer | 目前 VIP 等級的 `vip_contents.id` |
| `vipLevel` | string | `"VIP" + vip_contents.level`，例：`VIP0`、`VIP3` |
| `name` | string | `vip_contents.name` |
| `vipDailyBonus` | string | 每日額外返水（4 位小數字串），未設定時為 `"0.0000"` |
| `vipWeeklyBonus` | string | 週禮包，未設定時為 `"0.0000"` |
| `vipMonthlyBonus` | string | 月禮包，未設定時為 `"0.0000"` |
| `vipDailyAidBonus` | string | 規格無對應 reward，恆為 `"0.0000"` |
| `vipWeeklyAidBonus` | string | 每周救援金，未設定時為 `"0.0000"` |
| `vipMonthlyAidBonus` | string | 規格無對應 reward，恆為 `"0.0000"` |
| `vipBirthdayBonus` | string | 生日獎金，未設定時為 `"0.0000"` |
| `vipHolidayBonus` | string | 紀念獎金，未設定時為 `"0.0000"` |
| `upgradePoints` | string | 目前等級 `target_amount`（4 位小數） |
| `maintainPoints` | string | 目前等級 `keep_amount`（4 位小數） |
| `vipUpgradeBonus` | string | **下一級**的達標獎金（vip_reward_id=4），4 位小數；無下一級回 `"0.0000"` |
| `upgradeProgress` | string | `"{deposit_amount} / {下一級 target_amount}"`，無下一級回 `"0.0000 / 0.0000"` |
| `url` | string | VIP 介紹頁面網址（取自當前 bot 的 `livechat_bots.vip_url`） |
| `message` | string[] | 人類可讀訊息陣列，金額一律 2 位小數 |

### vip_reward_id 對應表

| 規格欄位 | `vip_reward_id` | 名稱 |
|---|---|---|
| `vipDailyBonus` | 7 | 每日額外返水 |
| `vipWeeklyBonus` | 5 | 週禮包 |
| `vipMonthlyBonus` | 6 | 月禮包 |
| `vipBirthdayBonus` | 2 | 生日獎金 |
| `vipHolidayBonus` | 3 | 紀念獎金 |
| `vipWeeklyAidBonus` | 8 | 每周救援金 |
| `vipUpgradeBonus` | 4 | 達標獎金（取**下一級**的） |
| `vipDailyAidBonus` | — | 無對應，恆為 `"0.0000"` |
| `vipMonthlyAidBonus` | — | 無對應，恆為 `"0.0000"` |

> 對應表已透過 `database/procedures/SP_payout_vip_reward.sql`、
> `SP_payout_vip_other_reward.sql` 與 `SP_calc_member_vip_deposit_amount.sql` 確認。
> Enum 定義於 `app/Enums/LivechatBotVipRewardEnum.php`。

## 業務邏輯說明

1. **會員與 VIP 取得**
   - 從 `members.username = {username}` 取得會員，找不到 → `404 Member not found`。
   - 會員需有 `member_vips` 記錄並關聯到 `vip_contents`，否則 → `404 Member VIP not found`。

2. **目前等級欄位來源**
   - `id` / `vipLevel` / `name` / `upgradePoints` / `maintainPoints`：來自會員當前 `vip_contents`。
   - 8 個獎金欄位：來自當前 `vip_contents.vipContentRewards`，依 `vip_reward_id` 對應。
     - 找到 reward → 透過 `Common::numAdd('0', point, 4)` 正規化為 4 位小數字串。
     - 找不到 reward → 回傳 int `0`。

3. **下一級判定**
   - 條件：同 `agent_id`、`level > 目前.level`，依 `level ASC` 取第一筆。
   - 找到 → `vipUpgradeBonus` = 該級 `vip_content_rewards` 中 `vip_reward_id = 4` 的 `point`（4 位小數字串）；
     `upgradeProgress` = `"{deposit_amount} / {下一級 target_amount}"`。
   - 找不到（已最高級）→ `vipUpgradeBonus = "0.0000"`、`upgradeProgress = "0.0000 / 0.0000"`。

4. **URL 來源**
   - 取自當前請求所對應 bot 的 `livechat_bots.vip_url`（透過 `LivechatBotContext::vipUrl()`）。
   - 若 bot 未設定 vip_url → 回空字串。

5. **Message 格式**
   - 金額部分以 2 位小數呈現（與 `output` 4 位不同）。
   - `Vip Upgrade Progress` 使用 `output.upgradeProgress` 原字串（4 位小數）。

## 錯誤回應範例

### 401 Bearer token 缺失或錯誤

```json
{
    "code": 401,
    "message": "Invalid or missing API key"
}
```

### 404 會員不存在

```json
{
    "code": 404,
    "message": "Member not found",
    "file": "app/Services/LivechatBot/GetVipSettingService.php:48",
    "tid": "req-xxxxxxxx"
}
```

### 404 會員無 VIP 記錄

```json
{
    "code": 404,
    "message": "Member VIP not found",
    "file": "app/Services/LivechatBot/GetVipSettingService.php:58",
    "tid": "req-xxxxxxxx"
}
```

## curl 範例

```bash
curl -X GET 'https://{host}/external/api/api-callback/livechat/bot/get-vip-setting/{username}' \
    -H 'Authorization: Bearer [REDACTED]' \
    -H 'Accept: application/json'
```
