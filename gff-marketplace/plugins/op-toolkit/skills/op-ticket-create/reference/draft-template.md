# Mode B 工單草稿模板

> 用途：把萃取後的欄位套入此模板 → 產生標準化 GitLab issue title + description + labels + assignee。
> description 必須**第一行**就是 `**TG 群**：xxx`，方便開發收單時直接找到原始提問者。

---

## 1. 標題模板

```
[<station>] <主題>
```

- `<station>` = `站方平台` label 對應，如 `TPOWER` / `V-World` / `Yes2win`
- `<主題>` = 10-30 字精簡描述，不複製整段 telegram 對話
- 動詞偏好：協助確認 / 排查 / 優化 / 修復 / 補帳 / 配置
- 若涉及多站台 → 用 `[<X>, <Y>]` 並列；超過 3 站時用 `[多站台]` 並在 description 列出所有 station

**範例**（取自既有 op_tickets）：
- `[TPOWER] Telco Pin 充值 100 玩家未拿到活動獎勵 (heng118928)`
- `[YES2WIN] dgpay 代付通道 IDR 銀行 bank code 設定異常`
- `[V-World] Hacksaw 遊戲玩家未綁定遊戲帳號`
- `[TPOWER, Wintoto, Yes2win] PT x DTK/APEXG/ONEMIX 活動對接需求`

---

## 2. Description 模板（最關鍵）

```markdown
**TG 群**：<tg_group>
**聯絡人**：<tg_contact>
**玩家**：<player_id>
**站台**：<station>
**時間**：<UTC+8 timestamp，格式 YYYY-MM-DD HH:mm>
**現象**：<symptom，一句話清晰描述>
**期望**：<expected，玩家想要什麼結果>

---

**附件**：
- 截圖 1：<簡述> → `<MCP upload markdown>`
- 截圖 2：<簡述> → `<MCP upload markdown>`
- 截圖 N：<簡述> → `<MCP upload markdown>`

---

<details>
<summary>客服原始對話（展開）</summary>

> <時間> [客服 william]
> <逐字內容>

> <時間> [站方/玩家]
> <逐字內容>

...

</details>
```

### 欄位填寫規則

| 欄位 | 規則 |
|---|---|
| `tg_group` | **絕對不可空**；缺漏 → AskUserQuestion 補齊 |
| `tg_contact` | 可空（用 `-`）但盡量從截圖萃取；標 `@username` 形式 |
| `player_id` | 可空（用 `-`）；不要編造 |
| `station` | 必填 |
| `時間` | 用 UTC+8（站台所在時區），與 GitLab 預設 timestamp 一致 |
| `現象` | 一句話；技術術語 OK，但保留客服原意；不要過度精煉到失去 context |
| `期望` | 一句話；明確說明玩家期待的結果（補款 / 補獎勵 / 客服回覆等） |

---

## 3. 截圖嵌入策略（MCP 模式）

MCP 上傳每張截圖後會回傳：
```json
{
  "alt": "image",
  "url": "/uploads/<hash>/filename.png",
  "markdown": "![image](/uploads/<hash>/filename.png)"
}
```

把 `markdown` 字串放到附件清單對應的 `<MCP upload markdown>` 占位符。

**注意**：
- 上傳是 project-scoped；`upload_markdown` 必須指定 `project_id=492` 與 `file_path=<本地圖片路徑>`
- 若 MCP 工具支援 `alt` 自訂 → 用 `截圖N` 取代預設的 `image`
- 大圖會自動壓縮顯示，width / height 可選擇性套用（既有工單慣例用 `{width=600 height=600}`）

---

## 4. Labels 組合

依 `label-conventions.md` 第 9 節「Mode B Label 決策樹」：

```python
labels = [
    f".P{severity}",
    f"站方平台:{station}",
]
if vendor:
    labels.append(f"遊戲商:{vendor}")
if priority_category:
    labels.append(f"問題類別:{priority_category}")
```

範例：
```
labels:
  - .P2 中優先(3個工作日)
  - 站方平台:TPOWER
  - 遊戲商:PragmaticPlay
  - 問題類別:紅包相關
```

---

## 5. Assignee 推薦

依 `../../op-ticket-read/reference/routing-rules.md` 第 3 節：

| domain | 預設 |
|---|---|
| `payment` 三方代付 | Tim |
| `payment` 帳變 | rb |
| `bank` | rb |
| `bonus` | daniel |
| `vendor` | leo |
| `login` | mars |
| `ui` | ryan |
| 不確定 | leah |

> 由 user 在 B5 confirm 環節覆蓋，預設值僅為建議。

---

## 6. 預覽完整範例

```
========== OP 工單預覽 ==========
標題：[TPOWER] Telco Pin 充值 100 玩家未拿到活動獎勵 (heng118928)

labels：
  - .P2 中優先(3個工作日)
  - 站方平台:TPOWER
  - 問題類別:紅包相關

assignee：daniel

----- description -----
**TG 群**：TPOWER-運維群
**聯絡人**：@yuusei
**玩家**：heng118928
**站台**：TPOWER
**時間**：2026-05-22 06:13
**現象**：玩家使用 Telco PIN 充值金額 100，扣除手續費後實收 92.5，但未能領取活動獎勵
**期望**：補發活動獎勵 / 釐清活動觸發條件

---

**附件**：
- 截圖 1：TG 對話，yuusei 反饋玩家 heng118928 問題 → ![image](/uploads/aaa/screenshot1.png)
- 截圖 2：後台訂單畫面，顯示扣款 92.5 → ![image](/uploads/bbb/screenshot2.png)

---

<details>
<summary>客服原始對話</summary>

> 5/22 06:13 [客服 yuusei]
> 您好 这个玩家账号充值使用 Telco Pin 充值金额100 但是扣除了手续费=92.5 但是却无法领取到活动奖励 还请贵司协助查询

</details>
-----

❓ 確認動作？
  1. 建立（透過 MCP 直接建單 + 上傳截圖）
  2. 修改：哪個欄位？
  3. 取消
```
