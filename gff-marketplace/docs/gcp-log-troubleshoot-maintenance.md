# gcp-log-troubleshoot 維護注意事項

這份是給修改 `plugins/apis-toolkit/skills/gcp-log-troubleshoot/` 的人看的，執行查詢時用不到。下文用「檔名＋節名」標位置：`SKILL.md` 是主檔，`cache.md`、`examples.md` 都在該 skill 的 `reference/` 底下。

查詢結果本地緩存（`cache.md`）與其他段落有強耦合，**改動任一處必須同步檢查另外幾處**：

| 主題 | 必須同步的位置 |
|---|---|
| freshness 預設值 | `SKILL.md`「時間表達 → 時間 filter」的「沒指定」列 + `examples.md` 範例 A/B/D 處理步驟「沒指定時間 → --freshness=Nh」+ `examples.md`「環境標示範例」的 ⏱ 行 |
| cache key 公式 | `SKILL.md`「命令模板」（產 key 的 sha256 輸入）+ `cache.md`「緩存位置與檔名」的檔名/索引說明 + `cache.md`「Cache lifecycle（情境總覽）」表「條件變更」列 |
| 輸出欄位 | `SKILL.md`「命令模板」step 6 jq display 的欄位選擇 + `SKILL.md`「輸出格式」spec + `examples.md` 範例輸出格式 |
| Tid 顯示長度 | `SKILL.md`「命令模板」step 6 jq display 的 split 邏輯 + `SKILL.md`「輸出格式」的 Tid 規則文 + `examples.md` 範例輸出（目前統一 UUID 末段 12 字） |
| Console URL 格式 | `SKILL.md`「GCP Console URL 生成規則（兩階段查詢流程）」的規則文 + `SKILL.md`「命令模板」step 6 + `SKILL.md`「輸出格式」spec（🔗 行）+ `examples.md` 範例 A/B/C/D 的 🔗 行 |
| Cache 保留欄位清單 | `SKILL.md`「命令模板」step 4 的 jq trim 表達式 + `cache.md`「緩存位置與檔名」格式說明 + `cache.md`「Cache lifecycle（情境總覽）」衍生 jq 操作（新增欄位前先確認 trim 仍保留） |
| Meta sidecar 格式 | `SKILL.md`「命令模板」step 4b 的 `jq -n` 結構 + `cache.md`「緩存位置與檔名」sidecar 說明 + `SKILL.md`「兩階段流程（強制）」Phase 2-B URL 還原步驟（新增欄位前確保三處同步） |
| 容量 cap | `SKILL.md`「命令模板」step 0 cleanup 的 `NR>` 閾值 + `cache.md`「自動釋放機制」容量規則 + 同節「容量為何是 100」說明（改值要三處同步） |
| Phase 1 state 檔格式 | `SKILL.md`「命令模板」step 3 的 `jq -n` 結構（寫入）+ `SKILL.md`「命令模板」step 4 的 `jq -r` 提取（讀取）+ `cache.md`「緩存位置與檔名」state 說明 + `SKILL.md`「兩階段流程（強制）」表 Phase 1/2-A 描述（新增欄位前確保四處同步） |
| SGT → UTC 換算命令 | `SKILL.md`「時間表達 → 時間 filter」的換算命令、參數說明與範例 + `examples.md` 範例 C 第 3 步的命令參數 |

三點（或四點）review 後一致才算改完。

cache key 目前不含 limit（`SKILL.md`「命令模板」固定 `--limit=50`）；若要支援自訂 limit，除了把 limit 加進 hash 輸入，也要更新上表「cache key 公式」一列的同步位置。
