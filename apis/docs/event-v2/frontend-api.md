# 活動 V2 — 前台 API（會員端）

> 路由在 `routes/frontend/non_auth.php`（列表）與 `routes/frontend/auth.php`（其餘，需會員 token）。本文件只列活動 V2 自己的接口；V1、邀請好友、Jackpot 等各有自己的 Controller。

## 活動 id 怎麼來

一律**由前端發起、帶活動 id**：先打 `event/list` 拿到每檔活動的 `event_v2_id`（就是 `event_v2_details.id`），再帶 `event_detail_id` 打下面每一支。一個站可以同時有多檔同類型活動（例如兩檔紅包），所以沒有「後端自己找現在那檔」這回事——那是邀請好友、Jackpot 這種一站一檔的功能才有的模式。

---

## `POST event/list`（不用登入）

參數：`domain`。回該站這條代理線看得到的全部活動（V1、V2、其他入口混在同一陣列，依 `sort`、`created_at` 排）。

V2 的列：`event_v2_id`（活動 id）、`name`、`image`、`game_type_ids`、`end_time`（前台時區）、**`handler_class`**（類型 handler，例如 `AngpowService`；前端據此分辨紅包等要走專屬畫面的類型，target/tickets#4234）。

---

## `POST event-v2/detail`

參數：`event_detail_id`。回 `content`、`join_withdraw`、`validate`（能不能參加）、`message`（原因）、`bonus_code`、`end_time`。

會跑完整的 `validate()`（目標用戶、互斥、阻擋、各類型條件），所以 `validate=false` 時 `message` 就是前台該顯示的原因。

---

## `POST event-v2/join`

參數：`event_detail_id`。同一把會員鎖包住 validate → join → 參加即領獎的 withdraw，防併發重複參加。

回：
| 欄位 | 說明 |
|---|---|
| `bonus_code` | 優惠碼（有設遊戲商才有） |
| `redirect_path` | 參加後導向 |
| `reward` | **這次的獎勵金額**（4 位小數字串）；紅包＝抽到的那一包（target/tickets#4234） |
| `join_withdraw` | true＝已直接進主錢包；false＝進活動錢包，打流水後才領 |

被擋時回 400，`message` 為原因（紅包的 1601～1607 見下）。

---

## `POST event-v2/angpow/info`（紅包專用，target/tickets#4234）

參數：`event_detail_id`。活動不存在或不是紅包回 400 `此活動不是紅包`。

只回畫面會用到的數字（Max 2026-10-01 收斂）；**不做 validate**，能不能抽、為什麼不能抽由 `event-v2/join` 失敗時的 `message` 回，這支純查詢、不寫拒絕紀錄。`null`＝那個限制沒設（不限）。金額一律 4 位小數字串。

| 欄位 | 說明 |
|---|---|
| `is_open` | 活動是否開啟中＝現在在 start_time～end_time 內 **且** `in_time_window` 為 true（停用的活動也算未開啟；`AngpowService::isOpen()`）。false 不展示紅包；其餘數字照回 |
| `score_cost` | 每抽一次扣幾分 |
| `score_balance` | 會員可用積分（同一個數字也在 `member/detail` 的 `score`，首頁／錢包卡不用為了顯示積分多打這支） |
| `member_times_limit`／`member_times_remaining` | 個人本期（可重複參加週期）次數 上限／剩餘 |
| `site_times_limit`／`site_times_remaining` | 全站本期（包括期間）次數 上限／剩餘 |
| `site_amount_limit`／`site_amount_remaining` | 全站本期金額 上限／剩餘（最後一包可能超發，剩餘最低 0） |
| `interval` | 同一人兩次抽之間要隔幾秒，前端據此決定要不要做間隔處理；null＝不限 |
| `next_available_in` | 距下次可抽還要等幾秒，0＝現在可以（interval 沒設或還沒抽過也是 0）；做倒數用 |
| `time_window` | 每天可抽時段 `["起","迄"]`，**已轉成前台時區**；null＝全天 |
| `in_time_window` | 現在是否在時段內 |

「本期」的換日線是前台時區 00:00，與 `getValidate()` 擋人的算法同一把尺（`AngpowService::frontendInfo()`）。要再加欄位（已用數、週期、參加即領獎）從 `frontendInfo()` 加，git log 找 2026-10-01 收斂前的版本。

### 紅包的驗證碼（`event-v2/join`／`event-v2/detail` 的 `code`）

| 碼 | 訊息（zh_TW） | 原因 |
|---|---|---|
| `0000` | — | 可以抽 |
| `0021` | 您不符合此活動的參加資格 | 不在目標用戶內 |
| `1601` | 此紅包尚未設定消耗積分，請聯繫客服 | 條件配置缺 `score` |
| `1602` | 現在不在紅包開放時段 | `time_window` 之外 |
| `1603` | 領取間隔未到，請稍後再試 | `interval` |
| `1604` | 已達本期個人領取次數上限 | `member_times` |
| `1605` | 已達本期個人領取金額上限 | `member_amount` |
| `1606` | 紅包已發完 | `max_amount` |
| `1607` | 積分不足 | 可用積分 < `score` |
| 既有 | 活動人數已達上限 | `max_join` |
| 既有 | 已參加過／一次只能參加一個活動 | 不勾參加即領獎、上一包還在打流水 |

---

## `POST event-v2/withdraw`、`quit`、`join-detail`

不勾參加即領獎的活動用：領取（打完流水）、放棄、查目前參加中的那筆。紅包不勾時走同一套，沒有專屬接口。
