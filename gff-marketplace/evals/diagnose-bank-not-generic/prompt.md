---
name: diagnose-bank-not-generic
description: 前台銀行清單缺項應叫起 bank-why-missing，通用排查的 bug-diagnosis 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/apis-toolkit", "../../plugins/gcp-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
Yes2Win 前台提款的銀行清單裡 Kasikorn 不見了，幫我看一下
