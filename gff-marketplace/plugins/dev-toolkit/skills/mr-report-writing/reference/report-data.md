# 報告資料：欄位、規則、YAML 子集文法

「報告資料」是「## 報告資料」節底下的一個 fenced ```` ```yaml ```` 區塊。前八節寫給人看，這一節是**同一份內容的結構化版本**，供之後的自動化程序轉成 MR 報告的知識檔；現在先照格式累積，不做轉換。兩邊必須一致：報告資料裡的數字、清單，要跟前八節的表格、條列對得起來。

## 欄位總覽

| 欄位 | 必填 | 寫什麼 |
|---|---|---|
| `type` | 是 | 固定 `Task Report` |
| `title` | 是 | `"MR <MR 標題>"`，加雙引號 |
| `description` | 是 | 跟「主旨」第一句相同 |
| `tags` | 否 | 工單編號與主題詞，flow 序列 |
| `status` | 是 | 開著 `draft`；合併 `stable`；關閉不合併 `deprecated` |
| `generated` | 是 | `{ by: <工具/版本 或 human:<GitLab 帳號>>, at: "<ISO 8601 含時區>" }`，時間一律加雙引號 |
| `verified` | 不填 | 寫報告當下不填；合併時由程序補上 |
| `sources` | 是 | 每張工單一項，一行一個 flow 對映：`- { id: "tickets#4137", resource: "<工單網址>", title: "<工單標題>" }`；沒工單 `[]` |
| `project` | 是 | GitLab project path，如 `target/gffv3/apis` |
| `tickets` | 是 | 工單 id 清單 `["tickets#4137"]`；沒有 `[]` |
| `commits` | 是 | 短 SHA 清單，依時間先後 |
| `mergedAt` | 是 | `draft` 時必須 `null` |
| `release` | 是 | 要發版的寫 `null`（發版後補 tag）；不用發版寫 `none` |
| `tests` | 是 | `{ command: "<命令>", total: n, added: n, failed: n }`，`command` 加雙引號；數字不知道（例如事後補寫）填 `null` |
| `acceptance` | 是 | 每一條預期結果一行：`- { item: "<預期結果>", rule: null, automated: covered, manual: pending }`；`rule` 是 `null` 或規則 ID，例如 `"rules/payout-submit-and-status#R-7"`；`automated` 是 `covered`／`none`／`not-applicable`；`manual` 是 `pending`／`passed`／`failed`／`not-applicable`；沒工單 `[]` |
| `decisions` | 是 | `- { id: D-4137-1, summary: "…", rejected: "…", supersedes: null }`；`id` 規則見下；沒有 `[]` |
| `planChanges` | 是 | 「跟原計畫不同的地方」每一項一句的區塊序列 `- "…"`；沒有 `[]` |
| `openItems` | 是 | 「待決與後續」每一項一句的區塊序列 `- "…"`；沒有 `[]` |

## 規則

- **時間一律帶時區，而且加雙引號**：例如 `"2026-10-19T14:00:00+08:00"`。Ruby 的 YAML 解析器對 flow mapping 內不加引號的時間值會解析失敗（會被當成特殊型別去解析，格式一不合就噴錯），所以一律加引號，不是風格選擇。
- **「誰」的寫法**：工具或模型寫 `<工具>/<版本>`，例如 `claude-code/claude-opus-5-5`；人寫 `human:<GitLab 帳號>`。
- **決定的 `id`**：寫 `D-<工單編號>-<序號>`，例如 `D-4137-1`；序號在同一張工單裡往上加，用過的不再用。不對應工單的 MR 用 `D-MR-<序號>`。
- **`supersedes`**：這條決定推翻了舊決定時，填被推翻的那個 `id`；沒有推翻填 `null`。
- **寫報告時不知道的值填 `null`**，不要用猜的填。沒工單的決定 `id` 寫 `D-MR-<序號>`。
- **不記 MR 編號與網址**：讀這段 YAML 的程序必須先知道是哪個 MR 才拿得到描述，寫進內容是重複的；需要來源連結的輸出由那支程序自己補。
- **`verified` 欄位不填**：留給合併時由程序補上 `{ by: human:<合併的人>, at: "<合併時間>" }`。
- **`release`**：這個 MR 合併後要發版，填 `null`（發版後由程序補 tag 號）；不需要發版的 MR 直接寫 `none`。
- **標準欄位名稱不要自己改**；不放 token、金鑰，也不放會員的姓名、電話這類個資。

## YAML 子集文法

驗證器只接受這些寫法：

- 頂層 `key: value`，不縮排。
- value 四選一：
  1. 純量。
  2. flow 序列：`[a, b]`。
  3. flow 對映：`{ k: v, k2: v2 }`；值只能是純量或 flow 序列，不能再巢狀一層 flow 對映或序列。
  4. 區塊序列：key 後面空著，下一行起縮排兩格，每行 `  - <純量或 flow 對映>`。
- 純量的寫法：裸字（不加引號）、`"…"` 雙引號字串、`'…'` 單引號字串、`null`、整數、`true`／`false`。
  **字串含半形冒號加空白、逗號、`#`、引號時一律加雙引號**，避免跟 flow 語法的分隔符衝突。
- `#` 開頭的整行是註解，會被忽略。

**不接受、會解析失敗的寫法**：

- 縮排的 block mapping（`key:` 換行後接 `  sub: v`）：這個子集只有「key 後面接區塊序列」一種換行寫法，不支援換行接 mapping。
- 多行字串（`|` 或 `>`）。
- anchor（`&`）與 tag（`!`）。
- 文件分隔線 `---`。

## 給自動化程序的約定

- **在 MR 合併的那一刻抓一次快照**，不要定期回頭抓。MR description 隨時可能被改。
- 抓「## 報告資料」底下的 yaml 區塊當 frontmatter，前八節當 body。
- 合併時補上 `status: stable`、`mergedAt`、`verified`；要發版的 MR 在發版後補上 `release`（填實際 tag）。合併之後才知道的事，從 GitLab 取得，**不回頭改 MR description**。
- `acceptance` 的手動驗收狀態合併後還會變。知識檔記的是 `generated.at` 那個時間點的狀態；要看現況，回到 `sources` 指向的工單，那裡才是事實來源。
- 某份報告的 `decisions` 裡 `supersedes` 指向舊的 `id` 時，讀取端應該把舊的那條決定當成已被取代。

## 完整範例

場景：APIs 專案的 MR !1999，對應工單 [tickets#4137「代付事務邊界改造」](http://example.invalid:1688/target/tickets/-/work_items/4137)。

```yaml
type: Task Report
title: "MR !1999 refactor(payment): 代付建立訂單與扣款交易邊界改造"
description: 代付建立訂單與扣款不同交易導致金額可能不一致，改為同一交易內完成並補齊回調冪等。
resource: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/1999
tags: [4137, payout, transaction-boundary]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-19T14:00:00+08:00" }
sources:
  - { id: "tickets#4137", resource: "http://example.invalid:1688/target/tickets/-/work_items/4137", title: "代付事務邊界改造" }
project: target/gffv3/apis
mr: 1999
tickets: ["tickets#4137"]
commits: [a1b2c3d, b2c3d4e]
mergedAt: null
release: null
tests: { command: "docker exec php-fpm8 bash -c 'cd /var/www/APIs && php artisan test --compact tests/Feature/Payment/PayoutTransactionBoundaryTest.php'", total: 8, added: 5, failed: 0 }
acceptance:
  - { item: "代付建立訂單與扣款在同一個資料庫交易內完成，扣款失敗時訂單不會建立", rule: "rules/payout-submit-and-status#R-7", automated: covered, manual: pending }
  - { item: "代付回調處理具備冪等性，同一筆回調重複送達不會重複入帳", rule: "rules/payout-submit-and-status#R-7", automated: covered, manual: pending }
decisions:
  - { id: D-4137-1, summary: "建立代付訂單與扣款包進同一個 DB::transaction()", rejected: "分成兩次個別寫入後靠排程做事後對帳", supersedes: null }
  - { id: D-4137-2, summary: "回調冪等改用 findAndLockWithdrawalOrder() 搭配 checkWithdrawalOrderStatus()", rejected: "沿用舊的「先查後判斷」寫法", supersedes: null }
planChanges:
  - "原計畫只改 XxxPay 一家金流商，實作時發現 YyyPay、ZzzPay 有相同問題，一併納入"
openItems:
  - "代付狀態查詢 API 的交易邊界尚未檢視，留待下一張單處理"
```
