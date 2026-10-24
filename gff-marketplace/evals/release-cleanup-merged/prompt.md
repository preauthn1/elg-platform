---
name: release-cleanup-merged
description: 這句話應該叫起 release-workflow，管 commit 的 git-commit 與管同步分支的 branch-sync 都不該被叫起
plugins: ["../../plugins/dev-toolkit", "../../plugins/gitlab-mcp"]
tags: [routing, release-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
!268 已經合併了，分支跟 worktree 幫我清掉。
