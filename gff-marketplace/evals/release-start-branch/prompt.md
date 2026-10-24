---
name: release-start-branch
description: 這句話應該叫起 release-workflow，管 commit 的 git-commit 與管同步分支的 branch-sync 都不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp"]
tags: [routing, release-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
我要開始做 #4482 的備份表白名單，先幫我開個分支。
