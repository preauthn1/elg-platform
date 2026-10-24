# 解析 Schema（雙 mode 共用）

> Mode A 對 GitLab issue body + comment 串萃取；Mode B 對 N 張截圖 + 補充文字萃取。
> Schema 一致，差別在輸入來源。

---

## 1. 完整欄位表

| 欄位 | 必/選 | 型別 | 來源 | 範例 |
|---|---|---|---|---|
| `tg_group` | **必** | string | Mode A：description 識別 / Mode B：B2 直接問 user | `TPOWER-運維群` |
| `tg_contact` | 必 | string | 截圖 / description 中的 telegram username / 客服 ID | `@william` / `客服 william` |
| `station` | 必 | enum | label `站方平台:*` ＋ title 前綴 `[<X>]` ＋ 截圖中後台 logo | `TPOWER` |
| `vendor` | 選 | enum | label `遊戲商:*` ＋ 內文掃描 | `PP / JDB / PT` |
| `player_id` | 選 | string | 內文 / 截圖中後台會員 ID | `heng118928` |
| `order_no` | 選 | string | 內文 / 截圖 `單號\d+` / `#\d{5,}` | `385731` |
| `time_range` | 選 | datetime | 內文日期 / 截圖 timestamp / `created_at` | `2026-05-22 06:13` |
| `symptom` | 必 | string | AI 摘要客服口語 / 截圖對話內容（一句話） | `Telco PIN 100 扣手續費 92.5 後沒拿到活動獎勵` |
| `expected` | 必 | string | AI 推斷玩家想要的結果 | `領取活動獎勵` |
| `domain` | 必 | enum | AI 分類 | `payment / vendor / bonus / bank / login / ui / data / other` |
| `severity` | 必 | enum | Mode A：`.P?` label / Mode B：user 選 | `P2` |
| `priority_category` | 選 | enum | `問題類別:*` label / user 選 | `代付訂單相關` |
| `attachments` | 選 | array | Mode A：`/uploads/...` URL / Mode B：截圖檔本身 | `3 張截圖` |
| `assignee` | 選 | string | Mode A：issue.assignees / Mode B：依 domain 推薦 | `daniel` |
| `state` | 選 | enum | issue.state（Mode A only） | `opened / closed` |
| `author` | 選 | string | issue.author（Mode A only） | `yuusei` |

---

## 2. tg_group 為什麼必填

開發者收單後最常見的 pain point：**需要回問原始提問者進一步釐清**，但客服貼上的對話通常已脫離群組上下文，找不到對話脈絡。
把 `tg_group` 升為 description 第一行可解決此問題：

- 開發看單 → 第一眼看到 TG 群 → 直接到 TG 群找原始對話
- 不需要再透過 GitLab assignee 反向找 author 反向找客服反向找群組

> 已在讀單模式（Mode A）摘要顯眼處標 `⚠️ TG 群未註明，建議回問 author <username>`，提醒開發回追。

---

## 3. station 解析優先級

1. **label `站方平台:<X>`**（最權威）
2. **title 前綴 `[<X>]`**（次權威，op_tickets 既有慣例）
3. **截圖中後台 logo / URL**（Mode B 主要靠這個）
4. 內文文字提及（如 `TPOWER 後台...`）

衝突時以 label > title > 截圖 > 文字。若有衝突需 AskUserQuestion。

---

## 4. player_id 識別 regex（Mode A）

候選 pattern（按優先級）：
```
帳號\s*[:：]\s*(\S+)
玩家\s*(?:ID)?\s*[:：]?\s*(\S+)
會員\s*(?:ID)?\s*[:：]?\s*(\S+)
[a-zA-Z][a-zA-Z0-9_]{4,19}   ← 純英數帳號，5-20 字
```

> 注意：op_tickets player_id 通常英數混合 6+ 字（如 `heng118928`, `Xiang2240`, `abc123`, `fgfg99`）。
> 截圖中通常有 label `會員/帳號:` 緊跟值。

---

## 5. order_no 識別 regex

```
單號\s*[:：]?\s*(\d{5,})
訂單\s*[:：]?\s*(\d{5,})
#(\d{5,})
```

---

## 6. domain 分類

| domain | 觸發詞 / 截圖特徵 |
|---|---|
| `payment` | 充值 / 上分 / 代付 / 提款 / 銀行卡 / 手續費 / Telco PIN |
| `bank` | 銀行 / 銀行清單 / dgpay / surepay 代付通道 / IDR 銀行 |
| `bonus` | 活動 / 紅包 / 獎勵 / VIP / 流水 / 領取 |
| `vendor` | 遊戲商名稱 / 遊戲打不開 / 注單異常 / 派彩 |
| `login` | 登入 / 註冊 / 9992 / 9015 / 手機驗證 |
| `ui` | 顯示 / 按鈕 / 跳出 / 彈窗 / 排版 |
| `data` | 數據未更新 / 報表 / 統計 / 數值不對 |
| `other` | 上述皆非 |

允許多 domain 組合：`bonus + payment`、`bank + payment`。

---

## 7. Mode A 多單批次解析

對每張單獨立執行欄位萃取，最後輸出統計：

```
共 N 張 / 主要 domain: <出現最多的 domain>
站台分布: <station 計數>
嚴重度分布: P0×n / P1×n / P2×n / P3×n
```

---

## 8. Mode B 截圖整合策略

多張截圖時：

1. 逐張解析（依 `screenshot-extraction.md` 分類）
2. 合併欄位：
   - `tg_group / tg_contact / station / player_id / order_no` 取**所有截圖共識**；若衝突 → AskUserQuestion
   - `time_range` 取最早截圖時間到最晚截圖時間
   - `symptom` 由 AI 跨截圖綜合
   - `expected` 由 AI 從玩家投訴 / 客服訴求綜合
3. 列出每張截圖的角色（在 description 附件清單）
