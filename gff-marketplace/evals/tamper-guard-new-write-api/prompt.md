---
name: tamper-guard-new-write-api
description: 寫新的寫入接口、而且有「下拉只能選自己線的」限制時，應叫起 parameter-tampering-guard；排查 bug 與查資料的 skill 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/apis-toolkit", "../../plugins/gcp-mcp"]
tags: [routing, tamper-guard]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
幫我在 apis 加一支代理後台的接口，讓總代可以替下線會員換所屬的會員群組，群組下拉只能列出總代自己線上建的群組
