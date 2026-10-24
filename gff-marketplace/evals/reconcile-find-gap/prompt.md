---
name: reconcile-find-gap
description: 這句話應該叫起 reconcile-bet-records，同組另外兩支對帳 skill 都不該被叫起
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, reconcile-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
VW 的 PTSW 上週五 BR 跟 SBR 金額差了一點，差在哪裡？
