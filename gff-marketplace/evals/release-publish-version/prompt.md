---
name: release-publish-version
description: 「發 release」是發版，應該叫起 gitlab-release，管開發到發布流程的 release-workflow 不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp"]
tags: [routing, release-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
最近合進 master 的那幾張 MR，幫我發個 release。
