---
name: sync-develop-align
description: 「對齊」講的是 git 分支，應該叫起 branch-sync，對帳的兩支 skill 都不該被叫起
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, cross-plugin]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
develop 幫我跟遠端對齊一下。
