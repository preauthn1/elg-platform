---
name: reconcile-align-word
description: 「對齊」講的是三表金額，應該叫起 reconcile-bet-records，branch-sync 和補帳修復都不該被叫起
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, cross-plugin]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
TPower 的 KissH5 昨天那幾筆對齊一下，看三表有沒有差。
