---
name: score-ticket-missing-info
description: 貼 OP 單網址但只問寫得夠不夠清楚，應該叫起 sure-score，讀單排查的 op-ticket-read 不該被叫起
plugins: ["../../plugins/op-toolkit", "../../plugins/gitlab-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
客服剛轉來 http://example.invalid:1688/target/op_tickets/-/work_items/3062 ，你幫我看看這張還缺哪些資訊，RD 拿到能不能直接開始查？
