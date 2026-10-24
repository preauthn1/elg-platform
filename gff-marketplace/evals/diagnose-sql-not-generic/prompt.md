---
name: diagnose-sql-not-generic
description: 直接要查某站資料來看原因，應叫起 whitelabel-db-query，通用排查的 bug-diagnosis 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/apis-toolkit", "../../plugins/gcp-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
幫我查 VWorld 代付單 WD20261018003412 在 withdrawal_orders 跟 withdrawal_logs 的資料，看它為什麼卡在處理中
