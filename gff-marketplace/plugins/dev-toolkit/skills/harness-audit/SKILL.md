---
name: harness-audit
description: Use this skill when the user wants to audit or reduce Claude Code harness context while preserving behavior, including CLAUDE.md, .claude/rules, auto memory, settings/permissions, hooks, MCP servers, skills/commands, or plugins. 只能打 `/dev-toolkit:harness-audit` 叫用。
disable-model-invocation: true
---

# Claude Code 設定稽核

**一句話版本**：盤點 Claude Code 的設定（harness，指 CLAUDE.md、規則檔、記憶、settings、hooks、MCP、skill、plugin 這些會影響 Claude 行為的檔案），找出重複、衝突、放錯位置與已失效的項目，產出一份可審查的精簡計畫。

- **最終目標**：減少設定佔用的 context，但**原本的效果維持不變**。
- **邊界**：唯讀，只出計畫。改檔要等使用者批准後另外進行，不在本 skill 範圍。
- **平台邊界**：本 skill 只稽核 Claude Code 的設定。Codex、Agents、IDE 擴充套件的語意不要套進 Claude Code 的結論。
- **不在範圍**：指令措辭是否為舊模型而寫（過度強調、逐步腳本、只踩過一次的坑被寫成永久規則等），改用 `/claude-api prompt-audit`。它的標準是「適合目標模型」，不是省 context，也不保證行為不變。

## 名詞速查

| 名詞 | 意思 |
|---|---|
| 常駐（launch-loaded） | session 一啟動就整份放進 context，每輪都佔用 |
| 按需（on-demand） | 被叫用或被讀到時才進 context |
| 有效態（effective） | 各層設定依官方規則合併之後，實際生效的結果 |
| 行為等價 | 精簡前後，Claude 在同樣情境下會做同樣的事 |
| 設定異味（smell） | 常見的設定問題型態，用來幫候選項分類，見「設定異味」節 |
| 證據（evidence） | `file:line` 加上原文摘要，能讓人回頭找到原文 |

## 範圍

範圍決定這次讀哪些層，用括號裡的 id 指定：

| 範圍 | 讀什麼 |
|---|---|
| 目錄鏈（`cwd-tree`，預設） | 從目前工作目錄往上走到根目錄，沿途所有啟動時會載入的 `CLAUDE.md`／`CLAUDE.local.md`，加上目前 repo 內的 `.claude/` 設定 |
| 使用者層（`user`） | `~/.claude/` 與 `~/.claude.json` |
| 組織層（`managed`） | 組織或作業系統派發的設定，例如 managed settings、managed MCP、managed `CLAUDE.md` |
| 額外目錄（`additional-dir`） | 用 `--add-dir` 加入、且開啟額外目錄記憶時，那些目錄裡的 Claude 設定檔 |
| 有效態（`effective`） | 所有讀得到的來源合併後的實際生效結果。不是固定兩層，而是依各類設定的官方載入與優先順序規則判斷 |

使用者沒指定範圍時，直接用目錄鏈，並在輸出中寫明這個假設。只有使用者明說要「全域」「跨層」「實際誰生效」「行為等價」時，才升級到有效態。

## 護欄

1. **唯讀**：全程不改檔，只產出計畫。
2. **不猜缺口**：掃不到的來源標「未驗證（`unverified`）」，不用「應該」補完缺少的證據。
3. **衝突不自動裁決**：互相矛盾的指令只標出來，附上「實際誰生效」，交給使用者決定。
4. **只建議能回復的變更**：每個刪除、搬移、合併建議，都要能從證據回推原文與來源。
5. **單層稽核不等於行為等價**：只讀目錄鏈、使用者層或組織層其中一層時，只能說「這一層內等價」。只有在有效態範圍、依各類設定的官方載入、串接、覆蓋、合併、優先順序規則解析過，才能下「行為等價」的結論。
6. **先查官方文件**：搬移與刪除的建議要查 Claude Code 官方文件並附出處，不能只靠既有記憶或 skill 裡的舊資訊。
7. **預設在主 context 完成**：列來源、讀檔、查官方文件都只是少量工具呼叫，直接做。只有來源多到一次讀不完、而且各批互相獨立時，才分批派讀取用的 agent。使用者的全域規則對 subagent 另有限制時，照它的規定。
8. **權限結論要看所有層的 ask 與 deny**：要下「某個命令不必確認就會執行」這類結論前，先找過所有讀得到的層（使用者層、專案層、組織層）有沒有對應的 ask 或 deny 規則，也要看「有效態判定」節權限列的例外（組織層限定、hook、mod）。範圍沒涵蓋全部層時（例如只有目錄鏈，或只有使用者層），沒讀到的層可能有 ask／deny 蓋住，這類結論一律標未驗證，並寫明沒查哪幾層。理由見「有效態判定」節的權限那一列。

## 稽核對象

| 類別 | 常見位置 | 判斷重點 |
|---|---|---|
| 指令與記憶檔 | 上層目錄的 `CLAUDE.md`／`CLAUDE.local.md`、repo 的 `CLAUDE.md`、`.claude/CLAUDE.md`、`.claude/rules/*.md`、`~/.claude/CLAUDE.md`、`~/.claude/rules/*.md`、managed `CLAUDE.md` | 是否重複、衝突、過長，能不能改成只在特定路徑生效的規則檔；標明常駐還是按需 |
| 自動記憶（auto memory） | Claude 的自動記憶目錄、`/memory` 看得到的內容 | 內容：是否把短期或已過期的觀察當成永久規則。結構：索引檔離載入上限還有多少餘裕、條目數、連結數、連結語法的佔比、同領域的小檔群。數字照「自動記憶的量法」節取得 |
| settings 與權限 | `.claude/settings.json`、`.claude/settings.local.json`、`~/.claude/settings.json`、managed settings | 覆蓋、合併、deny／ask／allow 的語意有沒有判錯 |
| hooks | settings 裡的 `hooks`、plugin 的 hooks、managed hooks | 是否重複觸發、卡住流程、已失效、權限過寬 |
| MCP | `.mcp.json`、`~/.claude.json`、plugin 的 MCP、claude.ai 連接器 | 同名或同一個端點，是否被優先順序較高的設定蓋掉 |
| skill 與命令 | `.claude/skills`、`.claude/commands`、plugin 的 skill | description 是否太寬，長篇 CLAUDE.md 程序是否該改成 skill |
| plugin | `enabledPlugins`、市集、plugin manifest | 是否團隊共用、是否受組織政策限制、是否重複提供同樣能力 |
| 額外目錄 | `--add-dir` 指向的目錄與其中的 Claude 設定檔 | 是否被載入、是否需要環境變數開啟、是否與目錄鏈的指令衝突 |

## 自動記憶的量法

自動記憶的字元數、載入上限餘裕、索引契約、記憶檔在 recall（被自動調出來讀）時會不會被截斷，一律用 `memory-tidy` skill 附的驗證器量，不另外手算。在該專案的 memory 目錄下執行：

```bash
python3 <memory-tidy 目錄>/check.py
```

`<memory-tidy 目錄>` 是 dev-toolkit plugin 裡 `skills/memory-tidy/` 的位置。量法與判讀（上限用字元數算、不用 `wc -c`）以 `memory-tidy` 為準。官方文件只寫「25KB」、沒寫計量單位，「以字元（code point）計」是本機實測，未經官方文件確認，引用時標成實測，套用前自行複驗。稽核只引用它的數字，要整理記憶時改走 `memory-tidy`。

## 設定異味

下表幫候選項分類。不要把它當成自動刪除的理由：每一列仍要有證據與官方語意支撐。

| 異味 | 判斷訊號 | 優先建議 |
|---|---|---|
| 常駐膨脹（Context Bloat） | `CLAUDE.md`／規則檔過長、低優先的細節太多、同一條規則在多處重述 | 合併、搬到只在特定路徑生效的規則檔，或改成按需的 skill。記憶索引膨脹不適用本列，見下一列 |
| 索引碎片化（Index Fragmentation） | 同一領域散成 5 個以上的小記憶檔；或索引檔裡 markdown 連結標記（`[Title](file.md)`／`[[name]]`）的字元數 ÷ 索引檔總字元數 > 40%（字元數以 Unicode code point 計，即 `wc -m` 而不是 `wc -c`；內容有中文時兩者的差距足以翻轉判定） | 同領域併成一個記憶檔，壓低索引條目數。recall 時載入的檔變大屬於按需成本，不增加常駐佔用 |
| 任務流程外洩（Skill Leakage） | 特定任務、低頻流程、長模板放在每次啟動都會載入的 context | 搬到 skill、命令或 reference，只留一句短的觸發說明 |
| 重述檢查工具（Lint Leakage） | 格式、命名、import 順序等已經由 linter、formatter 或 hook 強制的規則又寫一遍 | 改成引用驗證命令，不重述細節 |
| 只丟路徑（Blind Reference） | 只寫 `@file` 或路徑，沒說何時讀、讀來做什麼、優先順序 | 補一句用途與觸發時機，或移出常駐 context |
| 初始化產物未整理（Init Fossilization） | `/init` 產生的內容很久沒整理，含過期的架構、命令或流程 | 標成待審候選，不直接刪 |
| 指令互相矛盾（Conflicting Instructions） | 不同層或同一檔互相矛盾，或同一個決策有多條不相容的規則 | 標出雙方證據與實際有效態，交使用者裁決 |

## 官方來源

下結論前，依設定類別讀對應的官方 Claude Code 文件頁，並在輸出的「出處」欄附網址：

| 類別 | 文件 |
|---|---|
| settings、範圍、plugin 設定 | `https://example.invalid/docs/en/settings` |
| 權限規則（評估順序、跨層合併） | `https://example.invalid/docs/en/permissions` |
| CLAUDE.md、規則檔、自動記憶 | `https://example.invalid/docs/en/memory` |
| hooks 生命週期、格式、除錯 | `https://example.invalid/docs/en/hooks` |
| MCP 範圍、核准、優先順序 | `https://example.invalid/docs/en/mcp` |
| skill、命令、叫用方式、附屬檔案 | `https://example.invalid/docs/en/skills` |
| plugin、市集、plugin 打包 | `https://example.invalid/docs/en/plugins` |

官方文件與本機觀察衝突時，輸出分成「官方語意」與「本機實測」兩部分，不要把實測推廣成通則。

## 有效態判定

各類設定的合併方式不同，不要用同一套推全部：

| 類別 | 判定方式 | 常見陷阱 |
|---|---|---|
| CLAUDE.md／規則檔 | 都會進 context，可能同時存在；越具體越可靠，但不是硬性強制 | 以為後出現的文字一定會覆蓋前面的 |
| 自動記憶 | 索引檔（`MEMORY.md`）常駐，但只載入前 200 行或前 25KB（先到者為準），超出的部分不載入；個別記憶檔要被 recall 才載入 | 把記憶檔總量當成常駐成本；或忽略索引超限時尾段在載入時被靜默丟掉 |
| settings 一般欄位 | 依範圍優先順序取最高層的值 | 以為陣列和物件都是整個覆蓋 |
| 權限 | 所有層的規則一起評估，順序固定是 deny → ask → allow，照這個順序第一個符合的規則決定結果，規則寫得多具體不改變順序。所以任何一層的 ask 都會讓另一層更具體的 allow 照樣先問；任何一層的 deny 都擋得住另一層的 allow；組織層的規則，其他設定層與命令列參數都蓋不掉。例外：組織層開了 `allowManagedPermissionRulesOnly` 時只有組織層的權限規則有效；PreToolUse hook 以 exit 2 結束會在規則評估前就擋下；使用者裝的 mod 處理 `tool.check` 時，以下四項只在 plan mode 以外、或唯讀呼叫時成立：可以核准 ask 規則會問的呼叫；可以推翻非組織層 hook 的擋下；在 auto mode 跳過分類器；deny 規則在有組織層設定或登入 Team／Enterprise 方案時預設仍擋得住 mod（組織可改），其他環境 mod 可以推翻 deny。`AskUserQuestion` 與標了 `requiresUserInteraction` 的 MCP 工具，mod 核准後仍會詢問 | 只看到某一層有寬的 allow（例如 `Bash(git push *)`），就判定 force push 不必確認，沒查其他層有沒有 ask 或 deny 蓋住 |
| MCP server | 同名或同一個端點時，取優先順序最高的那一整條 server 設定 | 以為欄位會逐項合併 |
| hooks | 依 settings、plugin、組織層的設定載入；事件類型與 matcher 決定會不會觸發 | 只看檔案存在，沒看事件與 matcher |
| skill 與命令 | description 會進 skill 清單；全文只在被叫用後才進 context | 把長篇 CLAUDE.md 程序硬搬成會自動觸發的 skill |
| plugin | 是否啟用、市集政策、plugin 提供的 skill／hooks／MCP 共同決定 | 只看 plugin 目錄，沒看有沒有啟用 |

## 流程

1. **確認範圍**：這步決定讀哪些層。選目錄鏈、使用者層、組織層、額外目錄或有效態，並列出讀不到的層。
2. **列出來源**：這步找出範圍內所有設定檔。用 `pwd` 決定啟動目錄；往上檢查每一層的 `CLAUDE.md`／`CLAUDE.local.md`；再用檔案搜尋找其餘設定，各標「已納入（`included`）」「已排除（`excluded`）」「讀不到（`inaccessible`）」「未驗證（`unverified`）」。
3. **讀原文並記行號**：這步讓之後每一列都有可引用的證據。Read 工具的輸出已帶行號。
4. **查官方語意**：這步確認合併與載入規則。只查這次涉及的設定類別那幾頁，不做無關的文件巡禮。
5. **建立有效態**：這步算出實際生效的結果。依「有效態判定」表分別判斷串接、覆蓋、合併、整條取用、是否啟用。
6. **分類候選**：這步把發現歸類。分成重複、衝突、位置不當、多餘、已失效、範圍過寬，適用時標上對應的設定異味。
7. **產出計畫**：這步交出結果。每一列附證據、官方出處、風險與回復方式，不直接改檔。

## 分工

套用官方語意、建立有效態、分類候選都留在主模型做，因為它們受護欄 3、5、6、8 約束。依護欄 7 真的派讀取用的 agent 時，那個 agent 只列來源與逐字擷取原文（附 `file:line`），不摘要、不判定。四張表由主模型直接用回傳的原文填。

## 輸出

先給一句總結，再給來源清單與四張表：重複、衝突、搬移、精簡候選。每列欄位：

```
動作 | 標的 | 類型 | 理由 | 行為等價 | 風險 | 回復方式 | 證據 | 出處
```

「證據」是 `file:line` 加上被刪或被搬內容的摘要；衝突列的雙方都要附證據。

最後固定列出四項：

- **預估省下的 context（`Estimated context saved`）**：token 優先用實測值。請使用者在 session 裡執行 `/context all`，引用其中 Memory files、Skills、Custom agents 的逐項 token；標為按需載入（deferred）的 MCP 工具不算常駐成本。取不到實測值才粗估行數、token、檔案數，並寫明怎麼估的。涉及自動記憶時，一定要拆成「常駐（索引檔）」與「按需（記憶檔）」兩欄分開估，不能合成一個數字，否則會把記憶檔總量誤算成常駐成本。
- **行為不變的理由（`Behavior preservation`）**：一句話說明為什麼行為不變；只能保證這一層內等價時，直接講明。
- **未驗證（`Unverified`）**：列出組織政策、命令列參數、`--add-dir`、claude.ai 連接器、讀不到的檔案等缺口；範圍沒涵蓋全部層時，權限類結論也列在這裡（見護欄 8）。
- **套用前的關卡（`Apply gate`）**：提醒「以上為唯讀計畫，批准後再另外修改」。
