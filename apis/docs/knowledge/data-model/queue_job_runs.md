---
type: Table
id: data-model/queue_job_runs
title: 'Queue job 執行紀錄'
description: 'queue_job_runs 記錄各背景 job 在每個工作單位的執行結果，供各自的排程與回看補洞判斷是否已完成。'
domain: 系統
tags:
  - queue_job_runs
  - 回看補洞
  - 排程
  - SBR
  - 更新遊戲清單
  - 補建遊戲帳號池
  - 拉取注單
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Models/QueueJobRun.php
    at: '2026-10-22'
  - kind: migration
    ref: /database/migrations/2026_08_31_000001_create_queue_job_runs_table.php
entity:
  table: queue_job_runs
  model: App\Models\QueueJobRun
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: PK流水號 }
    - { name: job_key, type: varchar(50), nullable: false, comment: 'job 識別鍵(QueueJobKeyEnum，SBR=summary_bet_record)' }
    - { name: work_key, type: varchar(50), nullable: false, comment: '工作單位鍵(語意由各 job 定義，SBR=vendor_id；必須為 canonical 字串、無前後空白)' }
    - { name: bucket_time, type: datetime, nullable: false, comment: 工作單位時間格起點(UTC) }
    - { name: run_uuid, type: char(36), nullable: false, comment: '單次 job 執行識別碼(UUIDv7)；空字串=工作單位完成標記語意，不區分執行次數' }
    - { name: status, type: 'tinyint unsigned', nullable: false, comment: '執行狀態：0=未處理(已派發) 1=成功 2=失敗（QueueJobRunStatusEnum）' }
    - { name: source, type: varchar(20), nullable: false, comment: '觸發來源 schedule:整點排程 backfill:回看補洞 manual:CLI/後台手動 preseed:部署預塞' }
    - { name: output, type: mediumtext, nullable: true, comment: '執行輸出（人讀摘要；統計細節仍以 Log 為準）' }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
  read_by:
    - App\Models\QueueJobRun
  written_by:
    - App\Models\QueueJobRun
---

<!-- knowledge:generated start -->
## 表

`queue_job_runs`：Queue job 工作單位完成標記（回看補洞/稽核判定依據，job_key 區分）

Model：`App\Models\QueueJobRun`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | PK流水號 |
| `job_key` | varchar(50) | 否 | job 識別鍵(QueueJobKeyEnum，SBR=summary_bet_record) |
| `work_key` | varchar(50) | 否 | 工作單位鍵(語意由各 job 定義，SBR=vendor_id；必須為 canonical 字串、無前後空白) |
| `bucket_time` | datetime | 否 | 工作單位時間格起點(UTC) |
| `run_uuid` | char(36) | 否 | 單次 job 執行識別碼(UUIDv7)；空字串=工作單位完成標記語意，不區分執行次數 |
| `status` | tinyint unsigned | 否 | 執行狀態：0=未處理(已派發) 1=成功 2=失敗（QueueJobRunStatusEnum） |
| `source` | varchar(20) | 否 | 觸發來源 schedule:整點排程 backfill:回看補洞 manual:CLI/後台手動 preseed:部署預塞 |
| `output` | mediumtext | 是 | 執行輸出（人讀摘要；統計細節仍以 Log 為準） |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
<!-- knowledge:generated end -->

## 做什麼

這張表的一列代表某個背景 job 在某個工作單位（例如某家遊戲商、某個小時桶）執行過一次的紀錄；`job_key` 區分是哪種 job，目前共有 SBR 整點彙總、更新遊戲清單、補建遊戲帳號池、拉取注單、後台系統操作、GGR 結算六種在用。各 job 自己的排程、回看補洞與人工補跑命令讀寫它，用來判斷哪些工作單位還沒成功完成，決定要不要重跑。規則見相關規則。

## 相關規則

* [rules/summary-bet-record](../rules/summary-bet-record.md)
