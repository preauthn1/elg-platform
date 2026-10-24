# MR 報告範本

怎麼用：複製下面這個範本區塊的內容，貼進 MR description，再把每一個 `<…>` 角括號佔位符換成實際內容。某一節沒有內容就把那一節的佔位符換成「無」，不要整節刪掉。九個 `##` 標題與順序固定，不能多、不能少、不能調換。「報告資料」的 yaml 區塊裡，`generated.at`、`mergedAt` 這類時間一律要帶時區並加雙引號，例如 `"2026-10-19T14:00:00+08:00"`；寫的時候還不知道的值填 `null`，不要用猜的填。欄位的完整定義、允許值與 YAML 子集文法見 `report-data.md`。

```markdown
## 主旨

實作 [target/tickets#<工單編號>](http://example.invalid:1688/target/tickets/-/work_items/<工單編號>)：<一句話說明>

（沒有工單時整節改寫成：無工單：<說明緣由，例如自發的重構、文件整理>）

## 驗收進度

| 預期結果 | 自動 | 手動 | 備註 |
|---|---|---|---|
| <預期結果 1> | ✅ `<feature test 檔>` | ⬜ | |
| <預期結果 2> | 無 | ⬜ | <備註，例如「只能手動驗」> |

<手動驗收要等什麼才能做>

（動到知識庫的規則時，表頭與每一列在「預期結果」後面多一欄「規則」，填規則 ID，例如 `rules/vendor-spadegamingsw#R-7`；沒工單整節寫「無」）

## 變更

- `<檔案>`（新檔）：<為什麼改，或改成什麼行為>
- `<檔案>`：<為什麼改，或改成什麼行為>

## 設計決定

| 決定 | 理由 | 放棄的做法 |
|---|---|---|
| <決定> | <理由，有代價就寫出來> | <放棄的做法，不能留空> |

（沒有就寫「無」）

## 跟原計畫不同的地方

- **<改了什麼>**：<為什麼改>

（沒有就寫「無」）

## 測試

`<實際跑的命令>`：<總數> 項全部通過，其中 <新增數> 項是新加的。原有的 <原有數> 項照樣通過。

新增的測試：

- `<測試檔>`（<項數> 項）
  - <測試名稱>（對應 <預期結果或規則 ID>）

<測試的限制，例如三方回調是用假資料打的>

## 上線步驟

1. <第一步，寫到照著做就能完成的程度>
2. <第二步>

（流程之外沒有要做的事，或只改了文件，就寫「無」）

## 待決與後續

- <待決事項>（<相關工單或 MR 連結>）

（沒有就寫「無」）

## 報告資料

~~~yaml
type: Task Report
title: "MR <MR 標題>"
description: <一句話，跟「主旨」第一句相同>
tags: [<工單編號>, <主題詞>]
status: draft
generated: { by: <工具/版本 或 human:<GitLab 帳號>>, at: "<ISO 8601 含時區>" }
sources:
  - { id: "tickets#<工單編號>", resource: "<工單網址>", title: "<工單標題>" }
project: <GitLab project path，例如 target/gffv3/apis>
tickets: ["tickets#<工單編號>"]
commits: [<短 SHA 1>, <短 SHA 2>]
mergedAt: null
release: null
tests: { command: "<實際跑的命令>", total: <總數>, added: <新增數>, failed: <失敗數> }
acceptance:
  - { item: "<預期結果>", rule: null, automated: covered, manual: pending }
decisions:
  - { id: D-<工單編號>-1, summary: "<決定>", rejected: "<放棄的做法>", supersedes: null }
planChanges:
  - "<跟原計畫不同的地方，一項一句>"
openItems:
  - "<待決事項，一項一句>"
~~~

🤖 Generated with [Claude Code](https://example.invalid/claude-code)
```

報告資料不記 MR 編號與網址：讀它的程序必須先知道是哪個 MR 才拿得到描述。沒工單、無驗收表、無設計決定時，對應的 yaml 欄位改成空集合：`sources: []`、`tickets: []`、`acceptance: []`、`decisions: []`、`planChanges: []`、`openItems: []`；不用發版的 MR 把 `release` 寫成 `none`。
