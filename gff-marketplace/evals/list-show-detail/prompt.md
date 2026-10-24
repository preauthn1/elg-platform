---
name: list-show-detail
description: 這句話應該叫起 list-sbr-corrections，同組另外兩支對帳 skill 都不該被叫起
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, reconcile-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
在 TPower 顯示 PTSW 144 上週的補帳明細。
