# Livechat Bot API 文件

## 總覽

本文件說明 Livechat Bot 對接用的一組 External API。這些端點供 Livechat 機器人在會話中查詢會員資料時呼叫，目前提供：

- 查詢會員餘額（get-balance）
- 查詢會員儲值 / 提款紀錄（inquiry-deposit-withdrawal）
- 查詢會員可提款餘額與資格（get-withdraw-balance）
- 查詢會員活動列表（promotions，整合 V1 + V2）
- 查詢會員推薦進度（referral-progress，整合 V1 + V2 邀請好友活動）
- 查詢會員 VIP 設定（get-vip-setting）
- 依關鍵字查詢活動明細（get-promotion-detail，扁平 + 過濾版本的 promotions）

所有端點統一掛載於 Laravel 專案的 `external/api` 前綴底下，走 `GET` 方法並回傳 JSON。會員以 URL path 中的 `username` 標識。

## 認證機制

- 認證方式：**以 Header 傳遞 `Authorization: Bearer <api-key>`**。
- 驗證中間件：`App\Http\Middleware\External\LivechatBotApiKeyAuth`
- 採 **DB-based 多 bot 鑑權**（不再使用 config / env）：
  1. 從 `Authorization: Bearer <key>` 取出 token，查 `livechat_bots.api_key` + `is_active = true`
  2. 命中 → 注入 `LivechatBotContext` singleton 並 `Log::withContext` 記錄 `livechat_bot_name` / `livechat_bot_agent_id`
  3. 未命中或缺 header → 回 `401 Invalid or missing API key`

### 跨 agent 存取防護

每個 bot 綁定一個 `agent_id`，service 取得 member 後會透過 `MemberAgentGuard` 檢查：

- `bot.agent_id` 必須**位於 `member.agent.hierarchy_array` 中**（即 bot 為 member 所屬 agent 自身或祖先）
- 不符 → 統一 `throw ApiException('Member not found', 404)`（故意不洩漏「會員存在但屬於別 agent」）

## livechat_bots Schema

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | bigint, PK | |
| `agent_id` | int, indexed | bot 綁定的 agent，guard 會以此為準 |
| `name` | varchar(50), unique | 識別名稱（如 `default`、`brand_a`） |
| `api_key` | varchar(64), unique | Bot API key（明文） |
| `vip_url` | varchar(255), nullable | 該 bot 的 VIP 頁面 URL（供 `get-vip-setting`） |
| `is_active` | tinyint, default 1 | 啟用旗標 |
| `created_at` / `updated_at` | timestamps | |

### 建立 bot row（部署 / 新增品牌時）

```sql
INSERT INTO livechat_bots (name, agent_id, api_key, vip_url, is_active, created_at, updated_at)
VALUES (
  '{bot_name}',
  {agent_id},
  '{api_key}',
  '{vip_url}',
  1,
  NOW(),
  NOW()
);
```

或 tinker：
```php
\App\Models\LivechatBot::create([
    'name' => '{bot_name}',
    'agent_id' => {agent_id},
    'api_key' => '{api_key}',
    'vip_url' => '{vip_url}',
    'is_active' => true,
]);
```

## 共通 Request 參數

### Header

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `Authorization` | string | 是 | `Bearer <livechat_bot_api_key>`（由 middleware 驗證） |

### Path

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `username` | string | 是 | 會員帳號（`members.username`）。允許字元：`[A-Za-z0-9_.-]`，最大 50 字元 |

個別端點可能還需要追加 path segment 參數（如 `transaction_type`、`promotionName`），詳見各端點文件。

## 共通回應格式

### 成功（`ApiResponse::livechatBotOutput`）

```json
{
    "status": true,
    "code": 200,
    "msg": "Success",
    "output": { ... },
    "error": []
}
```

`output` 視端點而定可為物件（單筆）或陣列（清單）。

### 失敗

```json
{
    "code": <http-status-code>,
    "message": "<英文錯誤訊息>",
    "tid": "<request-id>"
}
```

| HTTP 狀態碼 | 情境 |
|---|---|
| `200` | 處理成功 |
| `400` | 請求驗證失敗（欄位缺漏、格式錯誤等；本組端點目前皆不會觸發） |
| `401` | Bearer token 缺失或不正確 |
| `404` | 會員不存在（含 username 不存在、路由不匹配，或會員所屬 agent 不在當前 bot 的 hierarchy 旗下） |

## 端點索引

| 端點 | 說明 | 文件 |
|---|---|---|
| `GET /external/api/api-callback/livechat/bot/get-balance/{username}` | 查詢會員各類錢包餘額 | [get-balance.md](./get-balance.md) |
| `GET /external/api/api-callback/livechat/bot/inquiry-deposit-withdrawal/{username}/{transaction_type}` | 查詢會員儲值 / 提款紀錄（`transaction_type` 限 `deposit`/`withdraw`） | [inquiry-deposit-withdrawal.md](./inquiry-deposit-withdrawal.md) |
| `GET /external/api/api-callback/livechat/bot/get-withdraw-balance/{username}` | 查詢會員可提款餘額與資格 | [get-withdraw-balance.md](./get-withdraw-balance.md) |
| `GET /external/api/api-callback/livechat/bot/promotions/{username}` | 查詢會員活動列表（V1 + V2） | [promotions.md](./promotions.md) |
| `GET /external/api/api-callback/livechat/bot/referral-progress/{username}` | 查詢會員推薦進度（V1 + V2） | [referral-progress.md](./referral-progress.md) |
| `GET /external/api/api-callback/livechat/bot/get-vip-setting/{username}` | 查詢會員 VIP 設定 | [get-vip-setting.md](./get-vip-setting.md) |
| `GET /external/api/api-callback/livechat/bot/get-promotion-detail/{username}/{promotionName}` | 依關鍵字查詢活動明細（扁平 + 過濾） | [get-promotion-detail.md](./get-promotion-detail.md) |

## 手動測試

- 快速 smoke test：[manual-testing-full.md 附錄 C](./manual-testing-full.md#附錄-c完整-smoke-test-一鍵腳本)
- 完整端到端測試手冊（含資料種子、log 驗證、清理）：[manual-testing-full.md](./manual-testing-full.md)
