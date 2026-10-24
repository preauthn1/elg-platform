---
name: diagnose-generic-bug
description: 通用 bug 應叫起 bug-diagnosis，查 log 與查資料的 skill 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/apis-toolkit", "../../plugins/gcp-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
VWorld 會員改綁手機號碼的 API 一天有兩三次回 500，log 只看到 Call to a member function on null，拿同一組參數重跑又正常，幫我找原因
