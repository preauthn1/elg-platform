---
paths:
  - "docs/knowledge/**/*.md"
---

# 知識庫編輯規則

`docs/knowledge/` 是系統行為的唯一正本（SSOT）：系統在什麼情況下、做什麼操作、會怎麼表現，只在這裡寫一份。格式是 Open Knowledge Format（OKF），一份文件講一件事，檔頭是 YAML frontmatter，內文是 Markdown。**Outline 只給客服看，不放規則正本**；客服頁引用規則時指向這裡的文件，不重寫。決策見 [decisions/0004-ssot-lives-in-git-knowledge-bundle](../../docs/knowledge/decisions/0004-ssot-lives-in-git-knowledge-bundle.md)。

每份文件都要能單獨讀懂、單獨被引用、單獨更新（原子知識）。文件之間的關係寫在 frontmatter，日後可以直接抽成知識圖譜。

通用的寫作規則（先講結論、名詞首次出現要解釋、用詞以 `GLOSSARY.md` 為準）由 `.claude/rules/docs.md` 管，本檔只寫知識庫特有的事。**動筆前先用 Skill 工具叫起 `dev-toolkit:tech-doc-writing`（規則、架構、決策文件）或 `dev-toolkit:plain-writing`（敘述段落）**，交給子代理寫時這一條要寫進它的指令。

## 目錄

```
docs/knowledge/
  index.md          保留檔：frontmatter 只有 okf_version，內文是五區的目錄
  log.md            保留檔：bundle 層級的異動，新的在上
  architecture/     系統邊界與子系統，人寫
  apis/
    frontend/       routes/frontend 的 Controller，一支一份，機器產骨架
    agent/          routes/agent 的 Controller
    external/       routes/external 的 Controller（金流回調、單一錢包、客服機器人）
    console/        artisan 命令，一支一份，機器產骨架
  rules/            業務或技術規則，一個業務主題一份，人寫
  data-model/       一張表一份，機器產骨架
  decisions/        架構決策，一個決定一份
```

- 規則只寫在 `rules/`。`apis/`、`data-model/` 的骨架只描述入口與表，用「相關規則」連到 `rules/`。
- `index.md`、`log.md` 是 OKF 保留檔名，不能拿來寫內容。

## 檔名與 ID

- 檔名：`<slug>.md`，不帶中文標題；標題只寫在 frontmatter 的 `title`。slug 用 kebab-case 英文，或照程式原名。
- **文件 ID**＝`<區>/<slug>`，例：
  - `rules/withdrawal-member-fee`
  - `apis/frontend/WithdrawalController`
  - `apis/console/cash-flow-sync`
  - `data-model/withdrawal_orders`
  - `decisions/0004-ssot-lives-in-git-knowledge-bundle`
- **slug 不能改**：它是 ID 的一半，程式的 `@knowledge` 與測試的 Group 都靠它。中文標題可以改，改 `title` 就好，檔名不動。
- 機器產骨架的 slug 照程式原名：
  - Controller：類別短名；external 底下撞名時前面加子目錄，例 `SingleWallets-BaseController`。
  - 命令：簽名的冒號改成連字號，例 `cash-flow:sync` → `cash-flow-sync`。
  - 表：表名原樣。
- 連結一律用從自己出發的相對路徑，不用 `/` 開頭（編輯器與 GitLab 才點得開）：同區 `[rules/withdrawal-member-fee](withdrawal-member-fee.md)`，跨區 `[rules/withdrawal-member-fee](../rules/withdrawal-member-fee.md)`，`apis/frontend/` 底下再多一層 `../`。`lint` 會擋 `/` 開頭的連結。

## frontmatter

### 共用鍵

每種文件都有這些鍵：

| 鍵 | 必填 | 意思 |
|---|---|---|
| `type` | 是 | `Architecture`、`Api`、`Command`、`Rule`、`Table`、`Decision` 六選一 |
| `id` | 是 | 文件 ID，必須等於 `<區>/<slug>` |
| `title` | 是 | 中文標題，不含 slug |
| `description` | 是 | 一句話說這份文件回答什麼；各區 `index.md` 的條目照抄這句 |
| `domain` | 是 | 單值，功能分類表的模組名：會員、代理、金流、活動、遊戲與遊戲商、報表、內容與通知、Olympus、系統。機器骨架先填「待分類」 |
| `tags` | 是 | 多值：正名、別名、英文、相關領域。跨領域檢索靠它，例如金流的文件可以放「會員」 |
| `status` | 是 | `draft`、`stable`、`deprecated`；骨架還沒補敘述時是 `skeleton` |
| `generated` | 是 | `{ by, at }`，`at` 是含時區的 ISO 8601 時間（`"2026-10-21T10:00:00+08:00"`），最後一次有意義的修改。AI 寫 `claude-code/<model>`，人寫 `human:<id>`，機器骨架寫 `script:knowledge-tools`（2026-10-21 以前產的骨架保留原本的 `artisan:knowledge:scaffold`，不改） |
| `verified` | 否 | list of `{ by, at }`，PM 審過加一筆，`by` 一律 `human:<id>` |
| `evidence` | 是 | list，每筆 `{ kind, ref, title?, at? }`，見下 |
| `related` | 否 | list of 文件 ID，單純的相關連結 |

`evidence` 的寫法：

- `kind` 是 `code`、`test`、`openapi`、`migration`、`mr`、`ticket`、`outline` 其中一個。
- `ref` 是 repo 路徑（`/app/...` 開頭）或完整網址。
- code 類的第一筆要帶 `at`，代表最後一次對照程式碼的日期（校對日期）。

### 各類型的專屬鍵

| type | 鍵 |
|---|---|
| `Rule` | `entities`：這份規則管到的東西，list，每筆是 `{ api: "POST frontend/api/..." }`、`{ class: "App\\..." }`、`{ table: "..." }`、`{ command: "cash-flow:sync" }` 其中一種；`subdomain`：選填，功能分類表的子模組名 |
| `Api` | `entity: { class, routes }`；`routes` 照 `route:list` 的寫法全部列出 |
| `Command` | `entity: { class, signature, schedule? }` |
| `Table` | `entity: { table, model?, columns, read_by, written_by }`；`columns` 每筆 `{ name, type, nullable, comment? }`；`read_by`、`written_by` 是 FQCN 清單，由 Model 關聯與靜態掃描產，抽不到就給空 list |
| `Decision` | `decided_at`；`decision_status`：`proposed`、`accepted`、`superseded`；`supersedes`：選填，被取代的文件 ID |
| `Architecture` | 無 |

### 例子

Rule 文件：

```yaml
---
type: Rule
id: rules/withdrawal-member-fee
title: 會員取款的會員手續費
description: 會員取款時，會員手續費怎麼算、從哪裡扣。
domain: 金流
subdomain: 取款
tags: [會員手續費, 取款, withdrawal, fee, 會員]
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:00:00+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Withdrawal/WithdrawalService.php, at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2325 }
entities:
  - { api: "POST frontend/api/withdrawal" }
  - { class: "App\\Services\\Withdrawal\\WithdrawalService" }
  - { table: withdrawal_orders }
related: [data-model/withdrawal_orders]
---
```

Api 文件（機器產）：

```yaml
---
type: Api
id: apis/frontend/WithdrawalController
title: 會員取款
description: 會員 App 取款相關的入口。
domain: 待分類
tags: []
status: skeleton
generated: { by: script:knowledge-tools, at: "2026-10-21T10:00:00+08:00" }
evidence:
  - { kind: code, ref: /app/Http/Controllers/Frontend/WithdrawalController.php, at: 2026-10-21 }
entity:
  class: /app/Http/Controllers/Frontend/WithdrawalController.php
  routes: ["POST frontend/api/withdrawal", "GET frontend/api/withdrawal/banks"]
---
```

## 內文

### Rule 文件

四節，固定順序：

1. `## 這個主題在講什麼`：一段，含「本份目前收錄的範圍」。
2. `## 怎麼觸發`：畫面路徑、命令、排程或回調；查不到寫「（待確認）」。
3. `## 規則`：規則表，規範見下。
4. `## Examples`：選填，情境表，每列的「依據」欄寫 `R-n` 或 `[別份 ID#R-n](連結)`。
5. `## 還沒確認的事`：選填，放最後；還在等 PM 或 RD 拍板、不能進規則表的行為，一項一句，附工單或對話出處。

規則表：

- 三欄 `| 編號 | 敘述 | 證據 |`，可以用 `###` 分群。
- 編號從 R-1 起，文件內連號，不分群重排。
- 已發出的編號不改、不重用。作廢一條就拿掉那一列，並在本節開頭寫「本份作廢的 R-n 不再使用」，新規則接在最後一號後面。
- **規則 ID**＝`<文件 ID>#R-n`，例 `rules/withdrawal-member-fee#R-3`。
- 敘述寫情境、操作、結果：
  - 不放欄位名、類別名、API 路徑。
  - 錯誤碼、狀態名可以寫；畫面文字與錯誤訊息照抄原文。
  - 用詞以 `GLOSSARY.md` 為準。
- 證據欄：`code:<類別短名或 FQCN>::<方法>`、`command:<命令簽名>`、`test:<測試類別>::<方法>`、`mr:!<iid>`，多個用「、」分隔，每列至少一個 code、command 或 test。
- 幾個主題共用的機制寫在主要的那一份，其他份用 `[ID#R-n](連結)` 引用，不重寫。
- 寫進規則表代表 RD 與 PM 都認定系統應該這樣；還在討論的寫在工單。

文件內不寫驗收節、不寫編輯歷程。哪幾條已被證明看測試的 Group；誰在何時為了哪張單改了哪條看 `git log`，所以改規則的 commit message 要寫明依據（MR、工單、事故）。

### Api、Command、Table 文件（骨架）

機器產的區塊與人寫的區塊分開，重產骨架只覆蓋機器區塊：

```
<!-- knowledge:generated start -->
## 入口（Api、Command：路由表，或命令簽名與參數）
## 表（Table：欄位表）
<!-- knowledge:generated end -->

## 做什麼          人寫；骨架先留一行「（待補）」
## 相關規則        機器產：掃 rules/ 的 entities 反查，列出 [ID](連結)
```

- `<!-- knowledge:generated start -->` 到 `end` 之間不要手改，改了下次重產會被蓋掉。
- 補完「做什麼」後把 `status` 從 `skeleton` 改成 `draft`。

### Decision 文件

沿用 ADR 的內文：背景、決定、考慮過的選項、後果、修訂。新寫的決定四節都要有；早期只有後果節的 ADR（0001、0002）照舊，不補沒有記錄的選項。frontmatter 補齊共用鍵與 Decision 專屬鍵。

### Architecture 文件

結構自由，但每份都要有：

- `## 邊界`：進出口，誰呼叫進來、它呼叫誰出去。
- `## 相關文件`：連到相關的 rules、apis、data-model、decisions。

## index.md 與 log.md

- 根目錄 `index.md`：frontmatter 只放 `okf_version`，內文是五區的目錄。
- 各區自己有一份 `index.md`：目錄清單，依 `domain` 分段，每個條目照抄該文件的 `description`。新增、改名、刪除文件時同步改。
- `log.md`：只記 bundle 層級的事（建立、遷入、作廢、改名），日期新的在上。單份文件的規則改動不記這裡，看 `git log`。

## 程式與測試的標記

- **類別 docblock**：加 `@knowledge <Rule 文件 ID>`，一行一個。只加在 Rule 文件 `entities` 有列到的類別。

  ```php
  /**
   * @knowledge rules/withdrawal-member-fee
   */
  class WithdrawalService
  ```

- **feature test**：加 `#[Group('<Rule 文件 ID>#R-n')]`，綁到它證明的那一條規則，例 `#[Group('rules/withdrawal-member-fee#R-3')]`。
- unit test 不加 Group。
- 改 slug 或作廢編號前，先 `grep` 測試目錄與 `app/` 找出所有標記。

## 驗收

- 規則的驗收只認 feature test 的 Group：一條規則有綁到它的 feature test，才算被證明。
- unit test 不算驗收；人工驗證也不算，QA 的人工步驟與紀錄放 Outline，不進知識庫。
- 文件內不列測試清單，以測試目錄的 Group 為準。

## 審查

- 改規則一律走 MR。PM 在 GitLab 上讀渲染後的文件，用 MR 留言回饋。
- 新文件或 AI 改寫的文件 `status: draft`。
- PM 審過改成 `stable`，同時在 `verified` 加一筆。
- Decision 例外：記的是已上線的決定，上線即 `stable`，不等 PM 審，`verified` 可以空著。
- 不再適用的文件改 `deprecated`，留著給既有連結，不刪檔。

## 與程式變更同步

- 2026-10-20 起，改了行為的 MR 必須在**同一支 MR** 改知識庫。改了行為指會員、代理、客服、營運或三方看到的表現跟以前不同；修 bug 也算，修正後的行為就是規則。
  - 有對應的 Rule 文件就改它的規則表；沒有就新增一份 `rules/<slug>.md`（`status: draft`），並補 `rules/index.md`。
  - MR 描述「驗收進度」的「規則」欄填規則 ID，例如 `rules/vendor-spadegamingsw#R-7`。
  - 刻意不改知識庫時，在 MR 描述「待決與後續」寫一句原因。
- 只動效能、重構、log 文案、CI、測試基礎或文件的 MR 不必改知識庫。
- 2026-10-19 以前合入的 MR 由搬遷主單分批回填，不在本條範圍。

## 檢查與骨架工具

兩支工具是 Python 腳本，放在維護者本機的個人 skill `knowledge-tools`（`~/.claude/skills/knowledge-tools/`），還沒放上 marketplace。執行前 `php-fpm8` 容器要在跑，工具會進容器取路由、命令與資料庫 schema：

```bash
python3 ~/.claude/skills/knowledge-tools/scripts/knowledge.py scaffold --section=apis
python3 ~/.claude/skills/knowledge-tools/scripts/knowledge.py lint
```

`scaffold --section=apis|data-model|all [--only=<slug>] [--referenced]`：

- 從 `route:list`、`artisan list`、資料庫 schema 產生或更新骨架；能取到 Scramble 的 OpenAPI 時一併取摘要。
- 只覆蓋 generated 區塊與 `entity` 鍵，人寫的鍵與區塊保留；機器區塊沒變時，`generated` 也原樣保留。
- 新檔的 `status` 是 `skeleton`。
- `--only` 只處理一份，例 `--only=WithdrawalController`；`--referenced` 只產 rules 的 `entities` 引用到的入口與表。

`lint`：檢查全部文件，回傳非 0 代表有不合格。檢查項目：

- frontmatter 必填鍵齊全，`id` 等於檔案路徑推出來的 ID。
- 規則編號連號、沒有重複。
- 連結指到的文件存在。
- `entities` 與 `evidence` 的 ref 存在：class 存在、api 在 `route:list`、command 在 `artisan list`、table 在 schema。
- 各區 `index.md` 的條目齊全。
- 標記雙向核對：`@knowledge` 與 Group 指到的文件與規則存在。

改完知識庫送 MR 前，先跑一次 `lint`。
