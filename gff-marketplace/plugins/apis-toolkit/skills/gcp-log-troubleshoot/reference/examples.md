# 範例

> 這份是照本 skill 組 filter 與輸出的範例：「環境標示範例」是三行標示的寫法，「完整範例」是四種輸入從解析、filter、URL 到輸出的全程。拿不準某種輸入怎麼組、輸出長什麼樣時讀。範例裡的具體 project id 是撰寫時示意，權威一律以主檔「白標 → GCP project 對照表」為準；範例不隨對照表變更同步。

## 環境標示範例

主檔「環境標示（強制，三行）」的三行標示，實際長這樣：

```
🔌 [TPower → tp-wz3-tpower]
🔍 username=Surnii · 轉帳紀錄 · container=apis-frontend
⏱  昨天 (2026-05-19 台北時間)
```

```
🔌 [VWorld → tp-wz3-online]
🔍 訂單號=PE2026052010370163306 · 代付回調 · container=apis-external
⏱  最近 5h
```

```
🔌 [TPower → tp-wz3-tpower]
🔍 Tid=019e44eb-963e-72a9-9164-deca1cfd08af · container=不過濾
⏱  最近 5h
```

## 完整範例

### 範例 A：Tid 查詢（單一 dimension，生產白標無 route 提示）

**輸入**：

```
查詢 Tpower Tid=019e44eb-963e-72a9-9164-deca1cfd08af 的關聯日誌
```

**處理**：

1. tenant=`Tpower` → normalize `TPower` → project `tp-wz3-tpower`（生產白標）
2. ID label=Tid → exact match
3. 沒指定時間 → `--freshness=5h`（預設）
4. 無 route 提示（純 Tid）→ **不加 container filter**，廣搜

**Filter clause**（完整命令套主檔「命令模板」+ cache.md 緩存流程）：

```
jsonPayload.payload.context.request_id="019e44eb-963e-72a9-9164-deca1cfd08af"
```

`--freshness=5h`、`--project=tp-wz3-tpower`、無 container filter。

**輸出**：

```
🔌 [TPower → tp-wz3-tpower]
🔍 Tid=019e44eb-963e-72a9-9164-deca1cfd08af · container=不過濾
⏱  最近 5h
📦 已緩存到 /tmp/gcp-log-cache/tp-wz3-tpower__a1b2c3d4e5f6.json（3 筆，mtime=10:26:00）
🔗 https://example.invalid/logs/query;query=jsonPayload.payload.context.request_id%3D%22019e44eb-963e-72a9-9164-deca1cfd08af%22;duration=PT5H?project=tp-wz3-tpower

[3 筆 entries]

10:25:52 deca1cfd08af  frontend/api/game/url  JokerBaccaratSW sendRequest failed (48897)
10:25:53 deca1cfd08af  frontend/api/game/url  HTTP 403 from /merchant/game/open-lobby
10:25:55 deca1cfd08af  frontend/api/game/url  getGameUrl failed: upstream forbidden

根因推測：JokerBaccaratSW 第三方回 403，member 48897 開遊戲 lobby 失敗
建議下一步：
1. 檢查 vendor 端 IP 白名單 / token
2. 撈該 member 近 1 小時所有 vendor 操作
```

### 範例 B：業務訂單號 + 關鍵字（生產白標 → 推 container）

**輸入**：

```
查詢 VW 代付回調 訂單號 PE2026052010370163306 的相關日誌
```

**處理**：

1. tenant=`VW` → alias → `VWorld` → project `tp-wz3-online`（生產白標）
2. 訂單號 PE2026... 非 UUID → SEARCH
3. 關鍵字「代付回調」命中主檔「業務關鍵字 → 自動 route + container filter」→ 自動 AND `route:"external/api/withdrawal/notify"` + 推得 `container_name="apis-external"`
4. 沒指定時間 → `--freshness=5h`（預設）

**Filter clause**（套主檔「命令模板」）：

```
SEARCH("`PE2026052010370163306`")
AND jsonPayload.payload.context.profile.route:"external/api/withdrawal/notify"
AND resource.labels.container_name="apis-external"
```

`--project=tp-wz3-online --freshness=5h`

**Phase 1 URL**（duration=PT5H）：

```
🔗 https://example.invalid/logs/query;query=SEARCH%28%22%60PE2026052010370163306%60%22%29%0AAND%20jsonPayload.payload.context.profile.route%3A%22external%2Fapi%2Fwithdrawal%2Fnotify%22%0AAND%20resource.labels.container_name%3D%22apis-external%22;duration=PT5H?project=tp-wz3-online
```

### 範例 C：多維度（username + 時間 + 關鍵字 → 推 container）

**輸入**：

```
在 TP 查詢用戶 Surnii 昨天的轉帳紀錄
```

**處理**：

1. tenant=`TP` → alias → `TPower` → project `tp-wz3-tpower`（生產白標）
2. username=`Surnii` → `profile.username="Surnii"`
3. 時間=昨天（SGT 2026-05-19）→ UTC `[2026-05-18T16:00, 2026-05-19T16:00)`（用主檔「時間表達 → 時間 filter」的換算命令，參數 `Asia/Singapore 2026-05-19`）
4. 關鍵字「轉帳紀錄」命中主檔「業務關鍵字 → 自動 route + container filter」→ 自動 AND `route="frontend/api/member/transfer"` + 推得 `container_name="apis-frontend"`

**Filter clause**（套主檔「命令模板」）：

```
jsonPayload.payload.context.profile.username="Surnii"
AND jsonPayload.payload.context.profile.route="frontend/api/member/transfer"
AND resource.labels.container_name="apis-frontend"
AND timestamp >= "2026-05-18T16:00:00Z"
AND timestamp < "2026-05-19T16:00:00Z"
```

`--project=tp-wz3-tpower`（時間已在 filter 內，不加 `--freshness`）

**Phase 1 URL**（timestamp 區間 mode，不帶 `duration`）：

```
🔗 https://example.invalid/logs/query;query=jsonPayload.payload.context.profile.username%3D%22Surnii%22%0AAND%20jsonPayload.payload.context.profile.route%3D%22frontend%2Fapi%2Fmember%2Ftransfer%22%0AAND%20resource.labels.container_name%3D%22apis-frontend%22%0AAND%20timestamp%20%3E%3D%20%222026-05-18T16%3A00%3A00Z%22%0AAND%20timestamp%20%3C%20%222026-05-19T16%3A00%3A00Z%22?project=tp-wz3-tpower
```

### 範例 D：測試環境查詢（強制 container=`apis`）

**輸入**：

```
查詢 mysql Tid=019e44eb-... 的關聯日誌
```

**處理**：

1. tenant=`mysql` → project `tp-wz3-rd`（**測試環境**）
2. ID label=Tid → exact match
3. 沒指定時間 → `--freshness=5h`（預設）
4. 測試環境 → **強制** `container_name="apis"`（all-in-one，沒有別的 container）

**Filter clause**（套主檔「命令模板」）：

```
jsonPayload.payload.context.request_id="019e44eb-963e-72a9-9164-deca1cfd08af"
AND resource.labels.container_name="apis"
```

`--project=tp-wz3-rd --freshness=5h`

**Phase 1 URL**（duration=PT5H）：

```
🔗 https://example.invalid/logs/query;query=jsonPayload.payload.context.request_id%3D%22019e44eb-963e-72a9-9164-deca1cfd08af%22%0AAND%20resource.labels.container_name%3D%22apis%22;duration=PT5H?project=tp-wz3-rd
```
