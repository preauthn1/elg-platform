---
name: diagnose-log-not-generic
description: 點名站台、時段與 ErrorCode 查 log 應叫起 gcp-log-troubleshoot，通用排查的 bug-diagnosis 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp", "../../plugins/apis-toolkit", "../../plugins/gcp-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
查 TPower 今天 14:00 到 15:00 ErrorCode 5003 的 log
