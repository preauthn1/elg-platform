# 查詢結果本地緩存

> 這份管查詢結果存在本機哪裡、何時沿用何時重查、追問時用哪段 jq、怎麼自動與手動清理。使用者對上次結果追問，或說「清緩存」時讀。每次輸出都要用的 📦 標示留在主檔「緩存元資料標示」。

## 查詢結果本地緩存（後續命令優先讀緩存）

**目的**：避免重複跑 gcloud。當使用者基於前次結果做衍生請求（列 Tid、篩 severity、找特定 route 細節等）時，從緩存撈，不重查。

### 緩存位置與檔名

- **目錄**：`/tmp/gcp-log-cache/`（macOS `/tmp` 重開機清空，自然回收）
- **每組查詢產生 2 個檔案（cache + meta sidecar）**：
  - **`<project>__<filter-hash>.json`**：entries 陣列；`filter-hash = sha256(filter + project + freshness)` 前 12 字
  - **`<project>__<filter-hash>.meta.json`**：對應的中繼資料，含 `{filter, project, duration, url, fetched_at}`
- **最新查詢索引（雙 symlink）**：
  - `/tmp/gcp-log-cache/_last.json` → 最新 entries 檔
  - `/tmp/gcp-log-cache/_last.meta.json` → 最新 meta 檔
- **Entries 檔格式**：gcloud `--format=json` 輸出經 jq 瘦身後（陣列），**只保留 `timestamp` + `jsonPayload` + `severity` 三個欄位**。其他欄位（resource / labels / logName / receiveTimestamp / trace / spanId / httpRequest）一律砍掉，因為主檔「命令模板」step 6 與「Cache lifecycle（情境總覽）」的衍生 jq 都用不到。實際保留欄位清單見主檔「命令模板」step 4 的 `jq '[.[] | {timestamp, jsonPayload, severity}]'`
- **Meta sidecar 用途**：Phase 2-B (cache hit) 從這裡還原 `🔗` URL，因為 entries 檔不存原始 filter / duration，光從 disk 無法重組 Console URL；sidecar 把這些 metadata 留下，cache hit 流程 `jq -r .url /tmp/gcp-log-cache/_last.meta.json` 即可拿到 URL 印出
- **Phase 1 → Phase 2-A 狀態檔**：`/tmp/gcp-log-cache/_phase1.state.json`（Phase 1 step 3 寫入、Phase 2-A step 4 讀取後 step 5 刪除）。內含 `{filter, project, duration, freshness_flag, cache_file, meta_file, log_url}`。**用途**：Phase 1 等使用者確認時會停在使用者輸入層，Phase 2-A 通常在後續獨立 shell call 跑，原 Phase 1 shell vars 已失效；state 檔讓 Phase 2-A 從 disk 完整還原。state 檔是 transient（只在 Phase 1 之後、Phase 2-A 之前存在）；異常未消費的 state 由「自動釋放機制」cleanup 在 >60 min 後砍掉

### Cache lifecycle（情境總覽）

| 情境 | 行為 | 跑 gcloud | 讀 cache |
|---|---|---|---|
| 條件未變 + cache 存在且 mtime ≤ 10 分鐘 | 直接 jq on `_last.json` | ✗ | ✓ |
| 條件未變但 mtime > 10 分鐘 | 提示「cache 已過期，是否重查」，預設重跑 | ✓ | ✗ |
| 條件變更（tenant / filter / freshness 任一不同） | 算新 cache key，必然重跑（cache 並存） | ✓ | ✗ |
| 使用者明示「重查 / refresh / 最新 / 重新查詢 / 再撈一次」 | 不管 cache 狀態強制重跑 | ✓ | ✗ |
| `_last.json` 不存在（首次 / `/tmp` 被清） | 走新查詢路徑 | ✓ | ✗ |

「條件未變」= 後續命令沒改動主檔「命令模板」的 filter / project / freshness 任一輸入；存疑時先 jq 試，無資料再問是否重查。

**cache key 公式**：`sha256(filter|project|freshness)` 前 12 字。**limit 不納入 key**（主檔「命令模板」固定 `--limit=50`）；未來若支援自訂 limit，需同步把 limit 加進 hash 輸入。

**衍生操作 jq 對映**：

| 使用者話術 | jq 操作 |
|---|---|
| 「列出 Tid」/「全部 Tid」 | `jq -r '.[] \| .jsonPayload.payload.context.request_id' _last.json \| sort -u` |
| 「篩出 ERROR」 | `jq '.[] \| select(.severity == "ERROR")' _last.json` |
| 「篩出 route X」/「只看 transfer」 | `jq '.[] \| select(.jsonPayload.payload.context.profile.route \| contains("X"))' _last.json` |
| 「依 Tid 分組」 | `jq 'group_by(.jsonPayload.payload.context.request_id)' _last.json` |
| 「展開第 N 筆」/「看完整 jsonPayload」 | `jq '.[N-1] \| {timestamp, jsonPayload}' _last.json` |
| 「統計 severity 分布」 | `jq -r '.[] \| .severity' _last.json \| sort \| uniq -c` |
| 「某 Tid 完整 lifecycle」 | `jq '.[] \| select(.jsonPayload.payload.context.request_id == "<Tid>")' _last.json` |
| 「列出所有 route」 | `jq -r '.[] \| .jsonPayload.payload.context.profile.route' _last.json \| sort \| uniq -c` |

**走 cache 時的輸出規則**：套用主檔「環境標示（強制，三行）」的三行 header（`🔌` / `🔍` / `⏱`），第四行印 `📦 沿用上次查詢結果（cache=<key>，<N> 筆，<X> 分鐘前查的）`。`🔌` / `🔍` / `⏱` 從 cache filename + session 上下文還原。**不執行 gcloud**，只跑 jq。

過期偵測腳本：

```bash
if [[ -f /tmp/gcp-log-cache/_last.json ]] && \
   [[ $(($(date +%s) - $(stat -f %m /tmp/gcp-log-cache/_last.json))) -gt 600 ]]; then
  echo "cache 已過期 10 分鐘，建議重跑 gcloud"
fi
```

### 自動釋放機制

**何時釋放**：

- 每次新查詢執行主檔「命令模板」**之前**，先跑一次清理
- 不在「沿用緩存」分支跑清理（避免誤刪剛要用的 `_last.json`）

**釋放規則**：

```bash
# 1) 刪除超過 60 分鐘未使用的檔案（含 entries 與 meta sidecar；macOS 預設 noatime，改用 mtime）
find /tmp/gcp-log-cache -type f -name '*.json' -mmin +60 -delete 2>/dev/null

# 2) 容量上限：保留最新 100 個檔案（= 50 組查詢 × cache + meta），多的依 mtime 由舊到新刪
#    用 find 而非 glob，避免 zsh nomatch / bash glob-fail 行為
find /tmp/gcp-log-cache -maxdepth 1 -type f -name '*.json' -exec stat -f '%m %N' {} + 2>/dev/null \
  | sort -rn | awk -v c=2 'NR>100 {print $c}' | xargs rm -f 2>/dev/null

# 3) 清理 dangling symlink（_last.json / _last.meta.json 指到已刪檔的情況）
for link in _last.json _last.meta.json; do
    if [[ -L "/tmp/gcp-log-cache/$link" ]] && [[ ! -e "/tmp/gcp-log-cache/$link" ]]; then
        rm -f "/tmp/gcp-log-cache/$link"
    fi
done
```

**容量為何是 100**：每組查詢 2 個檔，100 個檔＝最近 50 組；超過 60 分鐘的 TTL 仍是主要回收機制。容量 cap 是 fallback，避免異常 burst 灌爆 `/tmp`。

**TTL 選擇 60 分鐘的理由**：

- GCP log 對「最近 1 小時」的查詢最常見，超過 1 小時的緩存通常已失去新鮮度
- 與「Cache lifecycle（情境總覽）」表「mtime > 10 分鐘提示過期重查」是兩道防線：10 分鐘 = 警示重查、60 分鐘 = 真正刪掉
- macOS `/tmp` 重開機清空，所以最壞情況 cache 累積 24 小時左右

**手動清理**（使用者明示「清緩存 / clear cache / 砍掉 cache」時）：

```bash
rm -rf /tmp/gcp-log-cache
mkdir -p /tmp/gcp-log-cache
```

回應印 `🧹 已手動清空全部 cache`。
