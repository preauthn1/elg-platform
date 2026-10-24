---
name: repair-fill-gap
description: 這句話應該叫起 repair-bet-records，同組另外兩支對帳 skill 都不該被叫起
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, reconcile-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
KissH5 10/5 的差額幫我補進 SBR。
