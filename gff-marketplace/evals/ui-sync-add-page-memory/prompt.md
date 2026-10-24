---
name: ui-sync-add-page-memory
description: 新代理後台多了一頁要補進共享記憶，應該叫起 agent-backend-ui-sync。不判定 memory-store：它是 agent-backend-ui-sync 規定的前置技能，跟著被叫起是正確流程
plugins: ["../../plugins/qa-toolkit", "../../plugins/memory-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
新代理後台這週在財務管理底下多了「提款設定」頁，幫我補進共享記憶。
