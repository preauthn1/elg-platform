---
name: gcp-log-troubleshoot
description: 依白標查 APIs 的 GCP Cloud Logging 日誌，支援 Tid、trace_id、ErrorCode 查詢與從上次結果再篩選。當使用者說站名（VWorld、TPower、AW77 等簡稱也算）加上查 log 的意圖，或對剛查的結果說「列出 Tid」「看第 N 筆」「展開」時載入。
---

你是 APIs 多白標 GCP Cloud Logging 查詢助手。當使用者用自然語言要求依白標查詢日誌時，依本協議組裝 `gcloud logging read` 命令。

> **MCP 查詢通道**：若目前 session 有 gcp-mcp plugin 的 `list_log_entries` 工具（名稱含 `observability__list_log_entries`），優先把本 skill 組出的 project、filter、limit、freshness 交給 `list_log_entries` 查詢 Cloud Logging；若 MCP 不可用或 tool call 失敗，才 fallback 到本文件的 `gcloud logging read` shell 流程。白標 mapping、filter compose、URL preview 與本地 cache 規則仍以本 skill 為準，不交給 generic MCP 自行推斷。雖然 Google Observability MCP 也可能暴露 Monitoring / Trace / Error Reporting 工具，本 skill 只使用 Cloud Logging 相關 tools。

> **詞彙等價**：
> - 「白標」=「站點」=「站台」=「tenant」=「環境」全部同義
> - 「Tid」=「TID」=「request_id」=「requestId」視為同一個 dimension：exact match against `jsonPayload.payload.context.request_id`（系統注入的請求識別）
> - 「trace_id」=「traceId」=「trace-id」**值通常 = request_id**（codebase 多處從 `Context::REQUEST_ID` 取得），**但 GCP log 內的欄位路徑不同**（如 PayEssence/Kira/PPSW 將 trace_id 寫入 structured payload，路徑為 `jsonPayload.payload.trace_id` 而非 `context.request_id`）。為避免漏抓，**trace_id label 一律走 SEARCH 全文比對**，不假設欄位路徑（見「ID 路由（Tid vs 業務 ID）」第 2 步）
> - 「ErrorCode」=「錯誤代碼」**必須依 value 形狀走「ID 路由（Tid vs 業務 ID）」第 3 步**：UUID 則 exact match against `request_id`、非 UUID 則改走 SEARCH。codebase 內 ErrorCode 樣式不一（Tid 形態如 `019e44eb-...` 是 UUID v7、各 vendor 的 `ErrorCodeEnum` 多為 int 或常數字串，例 `App\Vendors\TFGamingSW\Enums\ErrorCodeEnum: enum int`、`App\Vendors\FumaSW\Enums\ErrorCodeEnum` const），**不要預設它是 UUID**
> - 「訂單號」=「交易號」=「訂單編號」=「業務 ID」視為同一個 dimension（SEARCH 全文）
> - 「用戶」=「玩家」=「user」=「username」=「玩家名」視為 username dimension（filter against `jsonPayload.payload.context.profile.username`）
> - 「會員ID」=「member_id」=「玩家ID」=「member id」視為 member_id dimension（filter against `jsonPayload.payload.context.profile.member_id`）
> - **「會員 X」是歧義 label**，依 value 形狀路由：純數字（`^\d+$`）→ member_id；含字母 → username（見「Username vs Member ID 路由」）
> - 「關聯日誌」=「相關日誌」=「log」=「紀錄」=「trace」=「日誌」
> - 我（Claude）對外輸出統一用「白標」「Tid」「訂單號」「用戶」

---

## 做到哪一步讀哪一份

本檔放每次查詢都要用的內容：白標對照、觸發條件、filter 怎麼組、命令模板、兩階段流程、Console URL 規則、輸出格式與禁止事項。只在追問上次結果時才用到的緩存細節，與完整範例，放在 `reference/` 底下。下表左欄的情況一出現，就先打開右欄那份再動手。

| 什麼情況 | 必讀 |
|---|---|
| 使用者對上次結果追問（列 Tid、篩 ERROR、看第 N 筆、依 X 分組），要判斷沿用緩存還是重查；使用者說「清緩存」 | [reference/cache.md](reference/cache.md) |
| 拿不準某種輸入（Tid、訂單號＋關鍵字、用戶＋日期、測試環境）怎麼組、輸出長什麼樣 | [reference/examples.md](reference/examples.md) |

## 白標 → GCP project 對照表（本 skill 內嵌）

白標→GCP project 對照**內嵌於下表**，與 `whitelabel-container-exec` 的 DB registry **各自獨立維護、互不引用**（mapping 目標不同：GCP project vs DB connection）。新增/改名/下線白標時直接更新本表，並各自同步兩處。

| canonical | aliases | gcpProject | env |
|---|---|---|---|
| VWorld | VW | `tp-wz3-online` | production |
| TPower | TP | `tp-wz3-tpower` | production |
| Yes2Win | Y2W, yes2win | `gff-yes2win` | production |
| Alfawin77 | AlphaWin77, alpha77, AW77 | `gff-alfawin77` | production |
| Me77 | 無 | `gff-vwb2c` | production |
| VPlus33 | VP, VP33, vplus | 無 GCP log | production |
| WTT | Wintoto, wt | `tp-wz3-online`（共用 VWorld project；GCP 層不分子站） | production |
| VWSG | VWorldSG, vworldsg | `tp-wz3-online`（共用 VWorld project；GCP 層不分子站） | production |
| mysql | 無 | `tp-wz3-rd` | test |
| Dev | 無（mysql 別名，保留字面、禁止互轉） | `tp-wz3-rd` | test |

欄位用途：`canonical`/`aliases`（正規化）/ `gcpProject`（寫「無 GCP log」＝沒有 GCP log）/ `env`（供「Container 推斷規則」推斷 container）。

**fail-closed（強制）**：
- 使用者指稱無法對應本表任一 canonical/alias → 停下來問，不猜 project。
- `gcpProject` 標「無 GCP log」→ 回覆「此白標無對應 GCP project / GCP log」並**中止**。

## 白標 → GCP project mapping

白標→`gcpProject` 來自「白標 → GCP project 對照表」。解析：
1. 用「白標 → GCP project 對照表」的 `aliases`/`canonical` 正規化使用者輸入（大小寫不敏感；找不到精確匹配再比 `aliases`；仍找不到則停下來問）。
2. 取該 entry `gcpProject`：非 null → 作查詢 project；**明確 `null`**（如 VPlus33）→ 回「此白標無對應 GCP project / GCP log」並**中止**。
3. `env` 供「Container 推斷規則」推斷 container。

**非白標 sentinel `log`**：`log` 是本機 `_log` DB、非雲端、**非白標**（不在「白標 → GCP project 對照表」）。使用者問「`log` 的 GCP log」→ 回「`log` 是本機 _log DB、無 GCP log」並中止。

> 📌 本 skill 的範例（reference/examples.md 的「環境標示範例」與「完整範例」）出現的具體 project id 為**撰寫時示意**，權威一律以「白標 → GCP project 對照表」為準；範例不隨對照表變更同步。

### 比對規則（normalization）

- **大小寫不敏感**：`tpower` / `TPOWER` / `Tpower` / `tPower` → canonical `TPower`
- **白標縮寫 / 拼錯對映**：alias→canonical 對照見「白標 → GCP project 對照表」的 `aliases`
- **輸出側統一**：命令、環境標示一律用 canonical 大小寫
- **作用順序**：先大小寫正規化 → 找不到精確匹配再比「白標 → GCP project 對照表」的 `aliases` → 仍找不到則停下來問

## 自然語言觸發句型

### 觸發條件

**初次觸發**（必要，雙條件 AND）：

- **(A) 白標名稱**：見「白標 → GCP project 對照表」（canonical / aliases；正規化規則見「比對規則（normalization）」）
- **(B) log 相關詞任一**：
  - 通用：`日誌` / `log` / `Cloud Logging` / `gcloud` / `trace`
  - Log identifier：`Tid` / `request_id` / `trace_id` / `錯誤代碼` / `ErrorCode`
  - 「業務關鍵字 → 自動 route + container filter」的業務關鍵字整詞：代收回調 / 代付回調 / 建立代收訂單 / 發起代付請求 / 轉帳紀錄 / 轉丟紀錄

「紀錄」單獨出現不算 B（歧義太大，DB record / 業務紀錄 / log entry 都可能）。「GCP」字樣**不強制**（APIs log 全在 GCP）。

**Cache 衍生模式**（skill 已 active 後放寬）：若 `/tmp/gcp-log-cache/_last.json` 存在且未過期（mtime ≤ 10 分鐘，見 reference/cache.md「Cache lifecycle（情境總覽）」），後續含緩存衍生詞的句子不再要求 (A)(B)：

| 衍生意圖 | 觸發詞範例 |
|---|---|
| 列出 dimension | 列出 Tid / 全部 request_id / 列出所有 route |
| 篩出子集 | 篩出 ERROR / 只看 transfer / 篩出 route X |
| 看單筆細節 | 看第 N 筆 / 展開 / 看完整 jsonPayload / 全部 message / 請求三方原始內容 |
| 同 Tid 整套 | 同個 Tid 完整 lifecycle / 某 Tid 全部 entries |
| 群組統計 | 依 X 分組 / 統計 X 分布 / 按 X 分類 |
| 引用前一筆 | 從剛才結果 / 上一筆 / 現在的 cache / 上面那些 |

Cache 不存在時應提示「目前無 cache，請先給完整查詢句（白標 + log 相關詞）」。

**反例（不應觸發）**：

| 句子 | 缺什麼 | 處理 |
|---|---|---|
| 查詢會員 X 最近一小時的請求日誌 | (A) 白標 | 問哪個白標 |
| 查 VW 的注單 / 在 Tpower 看會員餘額 | (B) log 詞 | 走 `whitelabel-container-exec` DB 查詢 |
| 查 GCP log 怎麼用 | (A) 白標 | 泛問用法不適用本 skill |
| 查 VW 用戶 X 的紀錄 | (B) 歧義 | 問「log 紀錄還是 DB 紀錄」 |

### 觸發句型結構

```
[動詞] <tenant> [<dimension>] ... [的] [<關鍵字>] <log 相關詞>
```

動詞：查 / 撈 / 找 / 看 / 查詢；dimension 見「Filter 維度表」；關鍵字見「業務關鍵字 → 自動 route + container filter」；結尾至少含一個 (B) 詞。

範例（全部觸發）：

| 句子 | 解析 |
|---|---|
| 查 Tpower 代付回調日誌 | tenant + 關鍵字（=B）+ 日誌（=B） |
| 查 VW 會員 Azizul5469 最近一小時的日誌 | tenant + username + 時間 + 日誌 |
| 查 Tpower Tid=019e44eb-... 的關聯日誌 | tenant + Tid（=B） |
| 查 Me77 會員 48897 今天的所有 log | tenant + member_id + 時間 + log |
| 用 gcloud 撈 VW 的轉丟紀錄 | tenant + 關鍵字 + gcloud（=B） |

### 後續安全規則

**未指定 tenant → 停下來問**，禁止自行 fan-out。

**指定 N/A tenant (`VPlus33` / `log`) → 回覆無對應 GCP project 並中止。**

**PII 與憑證遮蔽（貼進回覆前）**：`jsonPayload` 常帶三方請求／回應原文，輸出前遮蔽真實姓名、手機（`0912***456`）、Email、身分證（`A12****789`）、銀行帳號／卡號（末 4 碼），並移除 `Authorization` / `sign` / `key` / `secret` / `token` 欄位的**值**（只留欄位名）。`Tid` / `trace_id` / `member_id` / `username` / 訂單號照原樣，這些是查修必需的識別碼。

**cache 存原文、回覆才遮蔽**：本地 cache（`_last.json`）保留完整原文供衍生查詢（見 reference/cache.md），但不得整段貼出、不得落檔到 `docs/`、artifact 或外部服務（含魔鏡）；使用者要「完整 jsonPayload」時仍照上一條遮蔽憑證值。

## Filter 組裝（多維度 compose）

### 命令模板（含本地緩存，見「查詢結果本地緩存」）

新查詢與沿用緩存都照下面的模板逐步跑，`<...>` 佔位換成依本檔規則組好的值。自己另組的 gcloud 命令不會寫入緩存與 meta，之後的衍生查詢會讀到上一次的結果。

> **⚠️ 兩階段流程**：新查詢（cache miss / 使用者明示重查）**先跑 Phase 1 只組 URL 預覽**（step 0-2 + 印 🔗，於 step 3 ⏸ 等使用者確認）。確認後才往下跑 Phase 2 的 gcloud + symlink + jq（step 4-6）。詳見「兩階段流程（強制）」。Cache hit / 衍生查詢只跑 step 6（jq on `_last.json`），不經 Phase 1 也不重跑 gcloud。

```bash
# === Phase 1：URL Preview（新查詢必做）===

# 0) 自動釋放過期 cache（>60 分鐘 或 超過 100 個檔 = 50 組查詢×(cache+meta)，見 reference/cache.md「自動釋放機制」）
#    用 find 而非 glob，避免 zsh nomatch / bash unset-fail 行為
mkdir -p /tmp/gcp-log-cache
find /tmp/gcp-log-cache -type f -name '*.json' -mmin +60 -delete 2>/dev/null
find /tmp/gcp-log-cache -maxdepth 1 -type f -name '*.json' -exec stat -f '%m %N' {} + 2>/dev/null \
  | sort -rn | awk -v c=2 'NR>100 {print $c}' | xargs rm -f 2>/dev/null
for link in _last.json _last.meta.json; do
    [[ -L "/tmp/gcp-log-cache/$link" && ! -e "/tmp/gcp-log-cache/$link" ]] && rm -f "/tmp/gcp-log-cache/$link"
done

# 1) 計算 cache key（filter + project + freshness 的 sha256 前 12 字）
#    每組查詢產生兩個檔：<key>.json (entries) + <key>.meta.json (sidecar，存 filter/url/...)
CACHE_KEY="<MAPPED_PROJECT>__$(printf '%s|%s|%s' '<filter>' '<MAPPED_PROJECT>' '<freshness>' | shasum -a 256 | cut -c1-12)"
CACHE_FILE="/tmp/gcp-log-cache/${CACHE_KEY}.json"
META_FILE="/tmp/gcp-log-cache/${CACHE_KEY}.meta.json"

# 2) 組裝 GCP Console deep link 並印出（規則見「URL 結構」）
#    DURATION 規則：freshness mode 帶 ISO 8601（"PT5H" / "P1D" / "P7D"）；
#                    timestamp 區間 mode 留空字串（時間已在 filter 內）
#
#    ⚠️ filter 用 tempfile + quoted heredoc（`<<'FILTER_EOF'`）傳遞，避免 shell 把 backtick
#       展開。Cloud Logging 業務 ID 查詢常用 SEARCH("`...`") 語法，如果用 double-quoted env
#       var 會在進 python / jq 前就被 shell 當 command substitution 處理。Tempfile 由 step
#       4-5 結束時 rm 掉。
FILTER_TMP=$(mktemp /tmp/gcp-filter.XXXXXX)
cat > "$FILTER_TMP" <<'FILTER_EOF'
<filter>
FILTER_EOF

LOG_URL=$(FILTER_FILE="$FILTER_TMP" PROJECT='<MAPPED_PROJECT>' DURATION='<ISO8601 or empty>' python3 -c '
import os, urllib.parse
filter_str = open(os.environ["FILTER_FILE"]).read().rstrip("\n")
q = urllib.parse.quote(filter_str, safe="")
url = "https://example.invalid/logs/query;query=" + q
dur = os.environ.get("DURATION")
if dur:
    url += ";duration=" + dur
url += "?project=" + os.environ["PROJECT"]
print(url)')
echo "🔗 $LOG_URL"

# 3) 把 Phase 1 計算結果持久化到 state 檔（Phase 2-A 會獨立 shell 跑，原 vars 已失效；
#    state 檔讓 Phase 2-A 從 disk 還原 filter / paths / URL，不靠 chat context）
#    state 包含 filter 內容本體，FILTER_TMP 在此可清。
jq -n --rawfile filter "$FILTER_TMP" \
      --arg project        '<MAPPED_PROJECT>' \
      --arg duration       '<ISO8601 or empty>' \
      --arg freshness_flag '<--freshness=Nh 或空字串（timestamp 區間 mode）>' \
      --arg cache_file     "$CACHE_FILE" \
      --arg meta_file      "$META_FILE" \
      --arg log_url        "$LOG_URL" \
      '{filter:($filter | rtrimstr("\n")), project:$project, duration:$duration, freshness_flag:$freshness_flag, cache_file:$cache_file, meta_file:$meta_file, log_url:$log_url}' \
      > /tmp/gcp-log-cache/_phase1.state.json
rm -f "$FILTER_TMP"

# ⏸  停下來等使用者確認 / 調整 filter（見「兩階段流程（強制）」流程表）
#    使用者確認後 → 跑 Phase 2-A；要調整 → 重跑 Phase 1（新 filter / 新 URL，會覆蓋 state）；不查 → 中止


# === Phase 2-A：Execute（使用者確認後，獨立 shell call）===

# 4) 從 state 檔還原 Phase 1 計算結果（因 Phase 2 在獨立 shell call，原 vars 已失效）
STATE_FILE=/tmp/gcp-log-cache/_phase1.state.json
if [[ ! -f "$STATE_FILE" ]]; then
    echo "❌ Phase 1 state file 不存在，請先跑 Phase 1" >&2
    exit 1
fi
PROJECT=$(jq -r .project "$STATE_FILE")
DURATION=$(jq -r .duration "$STATE_FILE")
FRESHNESS_FLAG=$(jq -r .freshness_flag "$STATE_FILE")
CACHE_FILE=$(jq -r .cache_file "$STATE_FILE")
META_FILE=$(jq -r .meta_file "$STATE_FILE")
LOG_URL=$(jq -r .log_url "$STATE_FILE")

# 從 state.filter 重建 FILTER_TMP（gcloud 與 jq --rawfile 都需要 file path）
FILTER_TMP=$(mktemp /tmp/gcp-filter.XXXXXX)
jq -r .filter "$STATE_FILE" > "$FILTER_TMP"
FILTER=$(<"$FILTER_TMP")  # bash $(<file) 比 cat 少開 process；double-quote 傳 gcloud 不會 re-expand backtick

# 4a) gcloud + jq trim 先寫 tmp，只有 pipeline 成功才 atomic mv 上線
#     （`set -o pipefail` 只回非零，沒中止 script；shell `> "$CACHE_FILE"` 會先 truncate 檔案，
#      gcloud 失敗仍會留下空檔，下面 ln -sf 就會把 _last.json 指向壞 cache，所以改走 tmp 避開）
#     Trim 規則：只保留 timestamp + jsonPayload + severity（理由見 reference/cache.md「緩存位置與檔名」）
set -o pipefail
TMP_CACHE="${CACHE_FILE}.tmp"
if gcloud logging read "$FILTER" \
     --project="$PROJECT" \
     --limit=50 \
     $FRESHNESS_FLAG \
     --format=json \
     | jq '[.[] | {timestamp, jsonPayload, severity}]' > "$TMP_CACHE"; then
    mv "$TMP_CACHE" "$CACHE_FILE"
    # 4b) 寫 meta sidecar（filter / project / duration / url / fetched_at）
    #     Phase 2-B (cache hit) 從這裡還原 🔗 URL，不靠 chat context
    jq -n --rawfile filter "$FILTER_TMP" \
          --arg project  "$PROJECT" \
          --arg duration "$DURATION" \
          --arg url      "$LOG_URL" \
          '{filter:($filter | rtrimstr("\n")), project:$project, duration:$duration, url:$url, fetched_at:(now|todate)}' \
          > "$META_FILE"
else
    rm -f "$TMP_CACHE" "$FILTER_TMP"
    echo "❌ gcloud 失敗，cache 未更新（_last symlink 仍指舊資料）" >&2
    exit 1
fi

# 5) 更新雙 symlink + 清掉 FILTER_TMP 與 state 檔（已消費）
ln -sf "$CACHE_FILE" /tmp/gcp-log-cache/_last.json
ln -sf "$META_FILE" /tmp/gcp-log-cache/_last.meta.json
rm -f "$FILTER_TMP" "$STATE_FILE"

# 6) Display：先從 _last.meta.json 印 🔗 URL，再從 _last.json 印 entries
#    Phase 2-A 與 Phase 2-B 都跑這段（共用同一條 display path，「輸出格式」要求每次輸出都印 🔗）
#
#    一律讀 `/tmp/gcp-log-cache/_last.*` symlink：
#      - Phase 2-A：step 4b 剛寫 _last.meta.json、step 5 剛更新雙 symlink，內容一致
#      - Phase 2-B：跳過 step 4-5，symlink 仍指最近一次成功查詢，meta 含當時的 filter / URL
#    jq 顯示欄位：只取 timestamp + jsonPayload 衍生（Tid 末段 12 字 / route / message）
#    severity / container_name 等 metadata 不顯示：severity 已由 filter 約束、container 由 route 前綴隱含
#    無 jsonPayload 的 entry（純 textPayload）直接過濾掉
echo "🔗 $(jq -r .url /tmp/gcp-log-cache/_last.meta.json)"
jq -r '.[] | select(.jsonPayload) | [
    .timestamp,
    ((.jsonPayload.payload.context.request_id // "") | split("-") | .[-1] // ""),  // Tid 末段 12 字（規則 + 理由見「輸出格式」）
    .jsonPayload.payload.context.profile.route // "",
    .jsonPayload.message // ""
  ] | @tsv' /tmp/gcp-log-cache/_last.json
```

- **執行位置**：gcloud 在 **host**（透過 PATH 呼叫 `gcloud`；首次用前以 `which gcloud` 確認已安裝），**不要進 `php-fpm8` 容器**
- **Container filter** 依「Container 推斷規則」決定（測試環境固定 `apis`、生產白標 + route 命中關鍵字 → 推對應 container、其他不過濾）
- `--format` 含 `resource.labels.container_name` → 輸出能看到 entries 來自哪個 container

### Filter 維度表

| Dimension | 自然語言 trigger | Filter clause |
|---|---|---|
| **Tid / request_id** | `Tid=X` / `request_id=X` / `requestId=X` / 或直接給 UUID v4/v7 值（無 label） | `jsonPayload.payload.context.request_id="<UUID>"` |
| **trace_id** | `trace_id=X` / `traceId=X` / `trace-id=X` | `SEARCH("\`<value>\`")` ※ 不假設欄位路徑，全文比對；見本檔開頭的詞彙等價說明 |
| **ErrorCode** | `ErrorCode X` / `錯誤代碼 X` | 依 value 形狀（「ID 路由（Tid vs 業務 ID）」第 3 步）：UUID → `request_id="<UUID>"`；非 UUID → SEARCH |
| **業務 ID** | `訂單號 X` / `交易號 X` / `訂單編號 X` / 或直接給非 UUID 值 | `SEARCH("\`<value>\`")` |
| **Username** | `用戶 X` / `玩家 X` / `username X` / `user X` / `玩家名 X` / `會員 X`（X 含字母；見「Username vs Member ID 路由」） | `jsonPayload.payload.context.profile.username="<name>"` |
| **Member ID** | `會員ID N` / `member_id=N` / `玩家ID N` / `會員 N`（N 為純數字；見「Username vs Member ID 路由」） | `jsonPayload.payload.context.profile.member_id="<id>"` |
| **業務關鍵字** | 句中含「業務關鍵字 → 自動 route + container filter」表的關鍵字 | route-based（「Route-based 關鍵字（命中後推 container）」）→ route + 推 container；marker-based（「Marker-based 關鍵字（非 route，靠 severity + message tag 定位）」）→ severity / payload tag，不推 container |
| **時間範圍** | `最近 N 小時/天` / `今天` / `昨天` / `YYYY-MM-DD` / `X/X` | 見「時間表達 → 時間 filter」 |
| **Container** | 由 tenant + route 推斷 | 見「Container 推斷規則」 |

> **為何 SEARCH 內用 backtick**：`` SEARCH("`<value>`") `` 是 Cloud Logging 官方語法，backtick 強制 exact phrase / word-boundary 比對；無 backtick 會 tokenize（碰到 `-` / `.` / `_` 會切碎，例「PE-2026-...」會被拆成多 token），對結構化業務 ID 不安全。官方範例同時提供 `SEARCH("hello world")` 與 `` SEARCH("`hello world`") ``，兩者語意不同，**本 skill 一律用 backtick 形式以對應業務 ID 場景**。參考：[Cloud Logging query language](https://example.invalid/logging/docs/view/logging-query-language) 的 SEARCH 章節。
>
> 邊界：若 value 本身含 backtick 字元，需先去除或改用顯式欄位比對（極罕見）。

### 路由規則

#### ID 路由（Tid vs 業務 ID）

判定順序：

1. **Label 明確說是 Tid / request_id / requestId** → exact match against `jsonPayload.payload.context.request_id`
2. **Label 明確說是 trace_id / traceId / trace-id** → **SEARCH**（不假設欄位路徑；codebase 多處把 trace_id 寫進 structured payload 而非 context.request_id，見本檔開頭的詞彙等價說明）
3. **Label 明確說是 ErrorCode / 錯誤代碼** → 看 value 形狀：
   - 符合 UUID v4/v7 regex（見步驟 5）→ exact match against `request_id`
   - 不符合（int / 短字串 / 自訂格式，如各 vendor ErrorCodeEnum 多為 int）→ 改走 SEARCH
4. **Label 明確說是 訂單號 / 交易號 / 業務 ID / order_no** → SEARCH
5. **Label 含糊（只給值）**：看 value 形狀
   - 符合 UUID v4/v7 regex `^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`（大小寫不敏感）→ exact match against `request_id`
   - 否則 → SEARCH

**Fallback**：exact match 回 0 筆 → 自動再跑一次 SEARCH（safety net，怕 UUID 出現在 message 內而非 `request_id` 欄）。SEARCH 仍 0 → 依序提示：

1. 放寬時間範圍 (`--freshness=7d`)
2. **若 filter 同時 AND 了結構化欄位比對（`username` / `member_id` / route）與 `SEARCH(業務 ID)`**：剝掉欄位維度、只保留 `SEARCH(業務 ID)` 重跑。常見於代收／代付**回調**：回調由第三方打入，`context.profile` 往往只有 `domain` / `ip` / `route`、**不帶 `username` / `member_id`**，但 entry 內仍含業務 ID。此時欄位等值比對必然命中 0，與時間範圍無關（實測：Me77 ZPay 代收回調 `profile` 無 username，`username="X" AND SEARCH(訂單號)` 放寬到 7d 仍 0；改 `SEARCH(訂單號)` only 即撈到請求＋回調）。
3. 確認 UUID / ID 拼寫
4. 確認白標是否選對

#### Username vs Member ID 路由

兩者 filter clause 不同：

- Username → `jsonPayload.payload.context.profile.username="<name>"`
- Member ID → `jsonPayload.payload.context.profile.member_id="<id>"`

判定順序：

1. **Label 明確是 `username` / `user` / `用戶` / `玩家` / `玩家名`** → username clause
2. **Label 明確是 `member_id` / `會員ID` / `玩家ID` / `member id`** → member_id clause
3. **Label 含糊（只說「會員 X」、「member X」）**：看 value 形狀
   - 純數字（`^\d+$`）→ member_id clause（例：「會員 981992」→ `member_id="981992"`）
   - 含字母 → username clause（例：「會員 Azizul5469」→ `username="Azizul5469"`）

**範例**：
- 「查詢會員ID 981992 的日誌」→ label 帶「ID」+ value 純數字 → `member_id="981992"`
- 「查詢會員 Azizul5469 的日誌」→ label 是「會員」+ value 含字母 → `username="Azizul5469"`
- 「查詢用戶 Surnii」→ label 是「用戶」 → `username="Surnii"`
- 「查詢會員 981992」→ label 含糊但 value 純數字 → `member_id="981992"`

**為何 member_id 也加引號**：Cloud Logging filter 對純數字欄位 `=981992` 與 `="981992"` 都接受；加引號可避免欄位實際是 string 時 silent miss，保險寫法。

### 業務關鍵字 → 自動 route + container filter

substring 比對使用者句子，命中就 AND 對應 clause；多命中全 AND。

#### Route-based 關鍵字（命中後推 container）

| 關鍵字（substring） | 自動 AND 的 filter clause | 推得 container（生產） |
|---|---|---|
| `代收回調` | `jsonPayload.payload.context.profile.route:"external/api/deposit/notify"` | `apis-external` |
| `代付回調` | `jsonPayload.payload.context.profile.route:"external/api/withdrawal/notify"` | `apis-external` |
| `建立代收訂單` | `jsonPayload.payload.context.profile.route="frontend/api/deposit/create-order"` | `apis-frontend` |
| `發起代付請求` / `請求三方代付` | `jsonPayload.payload.context.profile.route:"agent/api/withdrawal/payout-process"` | `apis-agent` |
| `轉帳紀錄` | `jsonPayload.payload.context.profile.route="frontend/api/member/transfer"` | `apis-frontend` |

**operator 注意**：表內 `=` 是 exact match、`:` 是 substring contains，逐字保留不轉換。

**Container 推斷**：route 前綴 `frontend/` → `apis-frontend`、`agent/` → `apis-agent`、`external/` → `apis-external`。僅在生產白標套用，測試環境（`mysql` / `Dev`）固定用 `apis`（見「Container 推斷規則」）。

#### Marker-based 關鍵字（非 route，靠 severity + message tag 定位）

| 關鍵字（substring） | 自動 AND 的 filter clause | container |
|---|---|---|
| `轉丟紀錄` | `jsonPayload:* AND severity=ALERT AND jsonPayload.message:"[TRANSFER_LOST]"` | 無（不過濾；alert 可能來自任一 container） |

這類關鍵字**不推 container**：alert log 是「轉帳遺失」異常事件，可能在 frontend / agent / external 任一 container 產生，需廣搜避免漏抓。

**operator 注意**：
- `jsonPayload:*` 確保 entry 帶有 structured payload（過濾掉純 textPayload 的 entry）
- `severity=ALERT` 是 exact match（Cloud Logging severity 是 enum，不需 substring）
- `jsonPayload.message:"[TRANSFER_LOST]"` 是 substring contains，匹配 message 內含此 tag 的 entry

#### 共通規則

**擴充方式**：
- 新「route → container」關鍵字 → 加進「Route-based 關鍵字（命中後推 container）」表
- 新「severity + tag」關鍵字 → 加進「Marker-based 關鍵字（非 route，靠 severity + message tag 定位）」表
- 其他複合模式 → 在本節新增 sub-section

**禁止「半推斷」**：看到「代付」/「代收」/「轉丟」單詞不要自作主張加 filter，**必須命中表內完整關鍵字**才加。

### 時間表達 → 時間 filter

APIs 後台使用 **Singapore Time (UTC+8)**，使用者口中的「昨天 / 今天」是 SGT 日曆。

| 自然語言 | 處理 |
|---|---|
| 沒指定 | `--freshness=5h`（預設）。多數排查場景關注最近數小時，預設縮短可降低 cache 體積與查詢時間 |
| `最近 N 小時` / `近 N 小時` | `--freshness=Nh` |
| `最近 N 天` / `近 N 天` | `--freshness=Nd` |
| `今天` | `--freshness=24h`（粗略；要精確改用 timestamp 區間） |
| `昨天` | `timestamp` 區間（SGT 日界 → UTC） |
| `前天` | `timestamp` 區間（SGT 日界 → UTC） |
| `YYYY-MM-DD` / `X/X` / `X 月 X 日` | `timestamp` 區間（SGT 日界 → UTC） |
| `HH:MM ~ HH:MM` / `X 點 ~ X 點` | `timestamp` 區間（SGT → UTC） |

**SGT → UTC 換算**：`UTC = SGT - 8h`。這條公式用在對使用者說明時間怎麼換；組 filter 的 timestamp 一律用下面的命令算，不要心算。SGT 凌晨 00:00 到 07:59 換成 UTC 會落在前一天，心算最容易在這裡算錯日期、查錯時段。

**換算命令**：這步把使用者說的當地時間換成 filter 要的 UTC 時間（RFC3339 格式，結尾 `Z`）。

- 第一個參數是時區：使用者沒特別說就用 `Asia/Singapore`（SGT）；使用者明講台北時間就用 `Asia/Taipei`（同樣是 UTC+8，結果相同）。
- 第二個參數是開始時間，第三個參數是結束時間（不含結束點）；只給開始時間就算一整天。
- 時間可以寫日期（`2026-05-19`）、日期加時間（`'2026-05-19 10:00'`，有空格要加引號），或相對今天的天數（`0` 今天、`-1` 昨天、`-2` 前天，今天依第一個參數的時區算）。

```bash
python3 - Asia/Singapore -1 <<'PY'
import re, sys
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
tz, utc = ZoneInfo(sys.argv[1]), ZoneInfo("UTC")
def local(s):
    if re.fullmatch(r"-?\d+", s):  # 相對今天的天數：0 今天、-1 昨天、-2 前天
        today = datetime.now(tz).replace(hour=0, minute=0, second=0, microsecond=0)
        return today + timedelta(days=int(s))
    return datetime.fromisoformat(s).replace(tzinfo=tz)
start = local(sys.argv[2])
end = local(sys.argv[3]) if len(sys.argv) > 3 else start + timedelta(days=1)
z = lambda d: d.astimezone(utc).strftime("%Y-%m-%dT%H:%M:%SZ")
print(f"{start:%Y-%m-%d %H:%M} ~ {end:%Y-%m-%d %H:%M} {sys.argv[1]}（不含結束點）", file=sys.stderr)
print(f'timestamp >= "{z(start)}"')
print(f'AND timestamp < "{z(end)}"')
PY
```

標準輸出是兩行 timestamp clause，可以直接 AND 進 filter；標準錯誤輸出印一行換算前的當地時間區間，用來核對「⏱」那行。

**範例（假設今天 2026-05-20 SGT）**：

- 昨天 SGT = 2026-05-19 00:00:00 ~ 23:59:59
- 對應 UTC = 2026-05-18T16:00:00 ~ 2026-05-19T15:59:59
- 命令參數：`Asia/Singapore 2026-05-19`（今天是 2026-05-20 時，寫 `Asia/Singapore -1` 結果相同）
- filter clause：
  ```
  timestamp >= "2026-05-18T16:00:00Z"
  AND timestamp < "2026-05-19T16:00:00Z"
  ```

**注意**：使用半開區間 `<`（不含 endpoint），避免抓到下一天 00:00:00 的 entries。

### Container 推斷規則

APIs 在不同環境的 container 拆分不同：

| 環境類型 | container 配置 |
|---|---|
| 測試環境 (`mysql` / `Dev`) | all-in-one：`apis` |
| 生產白標（其他所有） | 依 route 拆三個：`apis-frontend` / `apis-agent` / `apis-external`；另有兩個非 route 的：`apis-horizon`（queue worker）、`apis-scheduler`（CronJob `apis-cronjob`） |

**排程與 queue 的 log 查得到**（2026-08-24 於 `tp-wz3-tpower` 實測）：

- `apis-scheduler`：排程（Artisan command）的 log。⚠️ **它的 log 塞在 `textPayload`、`jsonPayload` 為 `null`**，所以 `jsonPayload.message="..."` 這類等值比對**永遠 0 筆**，必須改用全文 `SEARCH("...")`。
- `apis-horizon`：queue worker 的 log。

只鎖 container 會被每分鐘的排程噪音淹沒，要再 AND 一個命令 marker，例如：

```
resource.labels.container_name="apis-scheduler" AND SEARCH("summary_bet_record --prev-hour")
```

排程是否觸發／成功也可以直接查 DB 的 `command_logs` 表交叉對照（`CRON_` 與 `ART_` 兩筆 trace 可與 GCP log 閉環）。

⚠️ **舊系統（`ext-webapi-container` / `agent-webapi-container`）的 access log 走 `logs/stderr` 且 `severity=ERROR`**，內容其實是成功請求（`"POST /index.php" 200`）。用 `severity>=ERROR` 篩舊系統會撈到一堆 200，別把它當錯誤量指標。

**新舊系統各自「能辨識 endpoint」的欄位不同，同一條 filter 不能通吃**：

| 系統 | container_name | 取 endpoint 的欄位 |
|---|---|---|
| 新 APIs | `apis-frontend` / `apis-agent` / `apis-external` / `apis-horizon` / `apis-scheduler` | `jsonPayload.payload.context.profile.route`（如 `external/api/deposit/notify`）；⚠️ `apis-scheduler` 例外，見上節 |
| 舊 API_External | `ext-webapi-container`（另有 `ext-nginx-container` 走 textPayload access log） | `jsonPayload.payload.context.url`（完整 URL，需自行去 host／query 取 path） |
| 舊 API_Agent | `agent-webapi-container`（另有 `agent-console-*` / `agent-backend-web-container` 屬後台 web） | ⚠️ **無 url／route 欄位**；只能從 `jsonPayload.message` 抓 `POST http://agent-webapi-service:60003/api/<path> execute time N ms`，或 `user[id] access <path> from <ip>` |

- 舊 API_External 的金流回調是**逐家 provider 一條路由**（`dgpay/withdrawal/callback`、`LegendPay/withdrawal/callback`…），新 APIs 收斂成 `external/api/{deposit,withdrawal}/notify`。
- 舊 API_Agent 的 message 帶白標前綴（如 `[Tpower_new_API_Agent]`），可反推是哪個站台的 pod。
- 🔴 **MCP 回傳的 `jsonPayload` 會帶三方請求原文與憑證**（實測舊 API_Agent 的 `context.content` 裡直接有 token、帳號、玩家 IP），**貼進回覆或檔案前必須遮罩**；查 pod metadata 時優先挑 `textPayload:"POST /index.php"` 這種短 entry。
- ⚠️ observability MCP 有 per-minute read quota，連查幾次就會被擋（quota 算在 client project 上，換 target project 無效）；撞到就 fallback `gcloud logging read`。

**判定演算法**：

```
if tenant ∈ {mysql, Dev}:
    AND resource.labels.container_name="apis"

elif 有 route 提示（「業務關鍵字 → 自動 route + container filter」keyword 命中 OR 使用者明確說 route 前綴）:
    匹配 route 前綴 → container:
        frontend/* → apis-frontend
        agent/*    → apis-agent
        external/* → apis-external
    AND resource.labels.container_name="<推得 container>"

else:    # 生產白標 + 無 route 提示
    不加 container filter（廣搜，效能略差但結果不漏）
```

**route 前綴目前僅 3 種**（`frontend/` / `agent/` / `external/`），對應 `routes/{frontend,agent,external}/`。

### 查詢結果本地緩存

每次查完，結果會存在本機 `/tmp/gcp-log-cache/`；使用者接著追問時直接從緩存撈，不重跑 gcloud。

**這步讀 [reference/cache.md](reference/cache.md)**：使用者對上次結果追問（列 Tid、篩 ERROR、看第 N 筆、依 X 分組等）時，先照它的「Cache lifecycle（情境總覽）」判斷沿用還是重查，再照它的 jq 對映取資料；使用者說「清緩存」時照它的手動清理做。緩存檔名、cache key 公式、過期與自動釋放規則也都在那份。

下面的 📦 標示每次輸出都要用，所以留在本檔。

#### 緩存元資料標示

回應裡明確告知資料來源，避免混淆「現查」vs「緩存」：

- **新查詢**：`📦 已緩存到 /tmp/gcp-log-cache/<key>.json（<N> 筆，mtime=<HH:MM:SS>）`
- **沿用緩存**：`📦 沿用上次查詢結果（cache=<key>，<N> 筆，<X> 分鐘前查的）`
- **過期重查**：`📦 緩存已過期（<X> 分鐘前），重跑 gcloud`
- **自動釋放**：`🧹 釋放 <N> 個過期 cache 檔（>60 分鐘）`（每次新查詢前印一次，僅當有檔被刪）

### GCP Console URL 生成規則（兩階段查詢流程）

#### 兩階段流程（強制）

避免 filter 拼錯就燒掉一次 gcloud + cache 檔案。**新查詢一律先給 URL 預覽，等使用者確認 / 調整後才真的執行 gcloud。**

| 階段 | 觸發條件 | 動作 | 是否跑 gcloud |
|---|---|---|---|
| **Phase 1：URL Preview** | 新查詢（cache miss 或使用者明示重查 / refresh） | 跑「命令模板」step 0-2（cleanup + cache key + URL build），於 step 3 把所有計算結果（filter / project / duration / paths / URL）寫入 `/tmp/gcp-log-cache/_phase1.state.json`，再印 `🔌 / 🔍 / ⏱ / 🔗`，⏸ 停下來等使用者確認 | ✗ |
| **Phase 2-A：Confirmed Execute** | 使用者確認後（「OK / 撈 / 跑 / 直接查」等） | 跑「命令模板」step 4-6（從 state 還原 vars → gcloud → atomic mv → meta sidecar → `_last` symlink → jq display → 清掉 state），輸出「輸出格式」節的完整結果。**state 檔是 Phase 1↔Phase 2-A 之間的傳遞橋樑**，因為兩階段通常落在不同 shell call，靠 disk 傳值 | ✓ |
| **Phase 2-B：Cache hit / 衍生查詢** | 條件未變且 cache mtime ≤10 分鐘（見 reference/cache.md「Cache lifecycle（情境總覽）」），或使用者問「列 Tid / 篩 ERROR / 看第 N 筆」等同一節列出的衍生意圖 | 直接跑「命令模板」step 6（內部先從 `_last.meta.json` 印 🔗 URL、再從 `_last.json` 印 entries，URL/filter 完全從 disk 還原不靠 chat context）；輸出「輸出格式」節的完整結果（`📦` 行改為「沿用上次查詢結果」格式） | ✗ |

**Phase 1 之後**使用者可能：

- **確認執行**：「直接撈」/「OK」/「跑」/「執行」/「查」 → 進 Phase 2-A
- **調整 filter**：「再加會員 X」/「改成代付回調」/「擴大到 7 天」 → 重新跑 Phase 1（新 filter / 新 URL），再等確認
- **只看 URL**：「不用查」/「先點開看看」 → 中止，不進 Phase 2

**Phase 2-B 跳過 Phase 1**：cache hit / 衍生查詢已有 `_last.json`，filter 不變，直接 jq display，不重新預覽 URL。

#### URL 結構

每次查詢成功後（包含新查詢與沿用 cache）都印一個 deep link，讓使用者直接點開到 Cloud Logging UI 看完整 entries / 互動篩選。

**URL 結構**：

```
https://example.invalid/logs/query;query=<URL-encoded-filter>[;duration=<ISO8601>]?project=<MAPPED_PROJECT>
```

- 分號 `;` 分隔 matrix-style 參數（`query` / `duration`）
- 問號 `?project=` 是標準 query string
- `duration` 為 ISO 8601 duration（**僅 freshness mode 帶**；timestamp 區間 mode 留空，時間已在 filter 內）

**ISO 8601 duration 對映**：

| `--freshness` flag | URL `duration` |
|---|---|
| `Nh`（例 `5h`） | `PTNH`（例 `PT5H`） |
| `Nd`（例 `7d`） | `PND`（例 `P7D`） |
| `24h` | `P1D`（或 `PT24H`，UI 兩者皆認） |
| 不加 flag（timestamp 區間 mode） | **省略 `duration` 參數**，URL 結尾直接 `?project=...` |

**Filter 編碼規則**（`urllib.parse.quote(filter, safe="")`）：

- `"` → `%22`
- `=` → `%3D`
- 換行 `\n` → `%0A`
- 空格 → `%20`
- 其他特殊字元一律百分號編碼，**不留 `safe`**

**timestamp 區間 mode 範例**（filter 含 `timestamp >= ...` 與 `timestamp < ...` clause，URL 不帶 `duration`）：

```
https://example.invalid/logs/query;query=<encoded-with-timestamp-clauses>?project=<MAPPED_PROJECT>
```

UI 開啟時時間選擇器會回到預設（最近 1 小時或 7 天，視帳號設定），但 filter 內的 timestamp clause 仍生效，結果不會錯。若使用者反映時間 picker 不貼合，可手動在 UI 切「Edit time range」覆寫。

**為何不官方確認**：[Cloud Logging Logs Explorer interface](https://example.invalid/logging/docs/view/logs-explorer-interface) 文件僅提及「Share short URL」功能，未公開 URL 參數結構。本格式是依 UI 行為推導，**已實證可用**（2026-05-21 測試 `tp-wz3-rd` 專案 freshness=PT5H 模式打開後正確顯示 entries）。若未來 Google 變更 URL 結構，**症狀**：點 URL 後 console 載入但 query 欄空白 / time range 異常 → 重新檢視 URL 結構並更新本節。

## 環境標示（強制，三行）

每個查詢的輸出**前三行**：

```
🔌 [<canonical-tenant> → <gcp-project>]
🔍 <dimension summary>
⏱  <時間範圍>
```

三種常見查詢的三行標示範例在 [reference/examples.md](reference/examples.md)「環境標示範例」。

## 輸出格式

執行後組裝以下結構：

```
🔌 [<tenant> → <project>]
🔍 <dimension summary>
⏱  <時間範圍>
📦 已緩存到 /tmp/gcp-log-cache/<key>.json（<N> 筆，mtime=<HH:MM:SS>）
🔗 https://example.invalid/logs/query;query=...?project=<project>

[<N> 筆 entries]

<HH:MM:SS> <TidTail>      <route>            <message 截斷>
<HH:MM:SS> <TidTail>      <route>            <message 截斷>
...

根因推測：<一句話總結；無把握時不寫>
建議下一步：
1. <可追問的方向>
2. <可交叉比對的資訊>
```

### 規則

- **只顯示 timestamp + jsonPayload 衍生欄位**（Tid / route / message）。severity 由 filter 約束、container 由 route 前綴隱含、其他 metadata（resource / labels / logName）對排查無增益，**全部不顯示**
- 無 jsonPayload 的 entry（純 textPayload / GCP 系統 log）**直接過濾掉**
- 「根因推測」**只在有把握時給**；無把握時寫「需要更多資訊：建議 ...」
- Stack trace 預設**不展開**；若 message 內含 `Common::formatTrace` 輸出，僅取前 3 frame
- **Tid 欄位（統一取 UUID 末段 12 字）**：取 `jsonPayload.payload.context.request_id` 最末 12 字（`split("-") | .[-1]`），如完整 Tid `019e488b-4e9a-72d2-bb45-72f153be9e4d` 顯示為 `72f153be9e4d`。**為何用末段而非前綴**：UUID v7 末段是 48-bit 純 random，唯一性最強；前綴是 ms 時間戳，同毫秒撞號率高（已實測），且時間順序資訊已由 `timestamp` 欄表達，前綴提供的訊息冗餘。完整 Tid 透過緩存的 jq 取得（見 reference/cache.md）。**任何輸出（主流程 + cache 衍生 + reference/examples.md 範例）一律用末段 12 字**，不再混用前 8 字 / 前 13 字
- 沿用緩存時，三行 header（`🔌` / `🔍` / `⏱`）照印，第四行 `📦` 改為 `📦 沿用上次查詢結果（cache=<key>，<N> 筆，<X> 分鐘前查的）`，不重跑 gcloud（見 reference/cache.md「Cache lifecycle（情境總覽）」與本檔「緩存元資料標示」）
- **🔗 URL 行強制印**（新查詢與沿用緩存皆印），位置固定在 `📦` 之後、entries 列表之前。生成規則見「GCP Console URL 生成規則（兩階段查詢流程）」。0 筆結果時仍印（讓使用者點過去可在 UI 放寬時間 / filter）
- message 過長截斷至 ~120 字元，結尾加 `...`
- 不主動 fan-out 跨白標，除非使用者明確要求（見「跨白標掃描（謹慎觸發）」）
- 對使用者轉述時間（`⏱` 行、回覆內文）寫「台北時間」，不寫 SGT 或新加坡時間：log 的 timestamp 是 UTC，轉述前先加 8 小時，可以並列 UTC 原值，例如 `2026-09-16T08:57:51Z`（台北時間 16:57）；換算命令的時區參數與日界算法照「時間表達 → 時間 filter」節，不改。

---

## 認證與權限檢查（首次執行前）

> **執行硬閘**：首次 `gcloud logging read`（「兩階段流程（強制）」的 Phase 2）前必須完成，否則會以不明 gcloud 錯誤失敗或跑在非預期帳號上。

第一次跑 `gcloud logging read` 前檢查：

```bash
# 1) 確認有 active account
gcloud auth list 2>&1 | grep -E '^\*' || echo "NO_ACTIVE_ACCOUNT"

# 2) 用最小成本的 logging read 探針驗證 logging 權限（避免用 projects describe，
#    後者需要 resourcemanager.projects.get，與 logging.viewer 是不同 IAM scope，
#    可能造成 false negative，即帳號有 log 讀取權但被 preflight 誤擋）
gcloud logging read 'timestamp>="2099-01-01T00:00:00Z"' \
  --project=<MAPPED_PROJECT> --limit=1 --format='value(timestamp)' 2>&1 | head -5
```

| 症狀 | 處理 |
|---|---|
| `NO_ACTIVE_ACCOUNT` | 提示使用者跑 `gcloud auth login` |
| 探針回 `PERMISSION_DENIED` / 403 | 該帳號對 `<MAPPED_PROJECT>` 無 `roles/logging.viewer`，請向 GCP admin 申請 |
| 探針回 `NOT_FOUND` / 404 | 「白標 → GCP project 對照表」的 `gcpProject` 值可能已變更，請使用者確認 |
| 探針回空白（無 entries 也無 error） | ✅ 權限正常，繼續執行 |

成功一次後本回合不再重複檢查。

> **參考**：[Cloud Logging access control](https://example.invalid/logging/docs/access-control)：`logging.logEntries.list` 屬 `roles/logging.viewer`，與 `resourcemanager.projects.get`（`projects describe` 所需）不同。

---

## 不可做的事

- ❌ 對 N/A 白標 (`VPlus33` / `log`) 硬跑 gcloud
- ❌ 未指定白標時自行 fan-out 跑全部
- ❌ exact match 0 筆就立刻放棄，必須先走「ID 路由（Tid vs 業務 ID）」的 SEARCH fallback
- ❌ 把 `--limit` 設超過 **200**（防止意外灌爆 context）
- ❌ 預設加 `severity>=ERROR` 過濾（會漏掉 INFO/WARNING 的關鍵 context）
- ❌ 在生產白標**無 route 提示**時硬加 `container_name="apis"` filter（會漏掉 `apis-frontend` / `apis-agent` / `apis-external`）
- ❌ 在測試環境 (`mysql` / `Dev`) **省略** `resource.labels.container_name="apis"` filter（測試環境只有 `apis` 一個 container；明確 AND 可剔除同 project 其他 service 的 noise，且與「Container 推斷規則」的判定演算法、reference/examples.md 範例 D 一致）
- ❌ 看到「代付」「代收」就自動加 route filter，**必須命中「業務關鍵字 → 自動 route + container filter」的完整關鍵字**
- ❌ 進 `php-fpm8` 容器跑 gcloud（容器內沒裝 gcloud）
- ❌ 修改 `~/.config/gcloud/` 任何檔案
- ❌ 時間 filter 用 `<=` 抓 endpoint（應用 `<` 半開區間）

---

## 跨白標掃描（謹慎觸發）

僅當使用者**明確說**「所有白標都查」「跨白標掃這個 Tid」「N 個白標都看」時才 fan-out。

執行規則：

- 排除 N/A 白標 (`VPlus33` / `log`)
- 對每個 project 跑一次「命令模板」的命令
- 輸出時每個 project 結果獨立區塊，命中（>0 筆）的 project 排前面
- **若超過 4 個 project 同時跑，建議拆給使用者選哪幾個**（避免回應過長）

⚠️ 一般 Tid 是 UUID，跨白標撞同一個 UUID 機率近 0，fan-out 多半浪費。先問清楚再跑。

---

## 與其他 skill 的關係

- `whitelabel-container-exec`：DB connection 切換協議。白標清單/alias **各自獨立維護**（本 skill 內嵌的「白標 → GCP project 對照表」vs 該 skill 的 DB registry），mapping 目標不同（GCP project vs DB connection）；新增/異動白標需**兩處各自同步**
- `reconcile-bet-records`：對帳任務常需要 cross-reference log + DB
- `large-amount-notify-debug`：大額通知排查常從 log 開始追

---

## 完整範例

四個從輸入到輸出的完整範例在 [reference/examples.md](reference/examples.md)：

| 範例 | 情境 |
|---|---|
| 範例 A | Tid 查詢（單一 dimension，生產白標無 route 提示） |
| 範例 B | 業務訂單號 + 關鍵字（生產白標 → 推 container） |
| 範例 C | 多維度（username + 時間 + 關鍵字 → 推 container） |
| 範例 D | 測試環境查詢（強制 container=`apis`） |

**拿不準某種輸入怎麼組時，先讀對應範例再動手。**
