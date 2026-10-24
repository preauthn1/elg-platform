---
description: 建立透過 gcloud CLI 操作 GCP 的 Artisan 命令，繼承 GcpCommand 抽象基礎類別。
argument-hint: "<命令簽名> <功能描述>"
disable-model-invocation: true
---

命令簽名與功能描述：$ARGUMENTS

---

先讀取 `app/Console/Commands/GoogleCloud/GcpCommand.php`（抽象基礎類別），再讀取 [`gcp-log-troubleshoot` skill](../skills/gcp-log-troubleshoot/SKILL.md)「白標 → GCP project 對照表」節的白標 → GCP project 對照。該對照是 project mapping 的唯一來源；本 command 不內嵌或猜測 project ID。

## 目錄結構

```
app/Console/Commands/GoogleCloud/
├── GcpCommand.php             ← 抽象基礎類別（禁止修改）
└── {Vendor}/
    └── {CommandName}.php
```

新命令放在 `app/Console/Commands/GoogleCloud/{Vendor}/` 下，namespace 為 `App\Console\Commands\GoogleCloud\{Vendor}`。

## 架構規則

- 所有命令**繼承 `GcpCommand`**，不直接繼承 `Illuminate\Console\Command`
- 使用 `$this->checkGcloud()` 於 `handle()` 最前段檢查 gcloud 是否可用
- 使用 `$this->readGcpLogs($filter, $project, $limit)` 取代直接建構 `Process`
- 若需呼叫其他 gcloud 子命令，在**子命令內**自行建構 `Process`，不應修改 `GcpCommand`
- 禁止在命令層直接查詢 DB；若需資料庫互動，透過 Service 層處理

## 強制要求

- 所有檔案首行：`declare(strict_types=1);`
- 選項解析集中在 `handle()` 頂部，不散落在各 private method
- 進度訊息使用 `$this->line()`，錯誤使用 `$this->error()`，警告使用 `$this->warn()`
- 輸出至 JSON 檔案時加上 `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`
- 時間範圍選項命名為 `--from` / `--to`，對應變數 `$startTime` / `$endTime`
- GCP project 選項命名為 `--project`，**不得設定跨白標通用預設值**
- 使用者指定白標時，依 `gcp-log-troubleshoot` 的「白標 → GCP project 對照表」一節解析 project；無法對應或該白標無 GCP log 時 fail-closed，停下回報
- 命令若明確只服務單一白標，可將該白標目前的 mapping 設為預設值，但 PHPDoc 必須註明白標名稱，且產生程式碼前仍須重新讀取 mapping
- 非單一白標命令必須要求呼叫者明確傳入 `--project`，缺少時回傳錯誤，不得自行退回任何預設環境

## Cloud Logging filter 語法

```
jsonPayload.message="精確訊息"
timestamp>="2026-01-01T00:00:00Z"
timestamp<="2026-01-01T23:59:59Z"
jsonPayload.payload.context.request_id=("id1" OR "id2")
# 各條件以空格分隔（AND 關係）
```

## PHPDoc 規範

類別上方須包含：
1. 一行功能摘要
2. `## 操作流程` — 編號說明各步驟
3. `## 使用範例` — bash code block（gcloud 下載指令 + php artisan 執行指令）
4. `## 注意事項` — gcloud 權限說明及特殊商業邏輯

## 輸出檔案

`app/Console/Commands/GoogleCloud/{Vendor}/{CommandName}.php`

> `GcpCommand.php` 僅在有共用邏輯需要上移時才修改。
