---
name: report-payment-risk
description: 要產的是金流商風險發現報告，應該叫起 report-payment-audit。不判定 report-design-conventions：report-payment-audit 產報告時規定要先載入它，跟著被叫起是正確流程
plugins: ["../../plugins/apis-toolkit", "../../plugins/dev-toolkit", "../../plugins/gcp-mcp", "../../plugins/gitlab-mcp"]
tags: [routing, collision-family]
runs: 3
max_turns: 3
allowed_tools: [Read, Glob, Grep, Skill]
---
上週稽核完 CCPayment 跟 TopPay，幫我把結果整理成風險發現報告，週會要給主管看。
