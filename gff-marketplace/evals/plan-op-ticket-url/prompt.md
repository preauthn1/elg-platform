---
name: plan-op-ticket-url
description: 貼 OP 單網址並說要規劃，應該叫起 design-grilling，只讀工單的 op-ticket-read 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/op-toolkit"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
http://example.invalid:1688/target/op_tickets/-/work_items/3051 這張要改 VWorld 的提款審核流程，動手前我想先規劃一下怎麼改，有不清楚的地方你直接問我。
