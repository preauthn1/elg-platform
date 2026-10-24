---
type: Decision
id: decisions/0055-igaming-verify-ssl-per-merchant
title: iGaming 對接程式的 TLS 憑證驗證預設開啟，由遊戲商戶配置逐筆關閉
description: Play8oy 與 SunCity 對三方的請求預設驗證 TLS 憑證，只有遊戲商戶配置明確寫 VERIFY_SSL=false 才關閉，不依環境整體關閉。
domain: 遊戲與遊戲商
tags: [Play8oy, SunCity, iGaming, TLS, SSL, 憑證驗證, VERIFY_SSL, staging, 中間人攻擊, fail-closed]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-22T15:25:00+08:00" }
evidence:
  - { kind: code, ref: /app/Vendors/Play8oy/Driver.php, title: "http() 為唯一出站入口，VERIFY_SSL 缺省 true", at: 2026-10-22 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2373 }
related: [rules/vendor-and-channel-management]
decided_at: 2026-10-07
decision_status: accepted
---

# iGaming 對接程式的 TLS 憑證驗證預設開啟，由遊戲商戶配置逐筆關閉

## 背景

iGaming staging（`*.example.invalid`）的憑證自 2025-09-29 過期，staging 遊戲商戶連不上（MR !2373 的 commit body）。

## 決定

- 遊戲商戶配置 `info.VERIFY_SSL` 缺省為 true；只有明確設 false 才關閉憑證驗證（MR !2373 的 commit body）。
- Play8oy 對接程式新增單一出站入口，所有請求都經它，SunCity 拉單也一樣（MR !2373 的 commit body）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 依環境（APP_ENV）判斷 | 憑證過期只影響 staging 遊戲商戶；正式遊戲商戶關閉驗證等於放行中間人攻擊（MR !2373 的 commit body） |
| 整個對接程式關閉驗證 | 同上（MR !2373 的 commit body） |
| 缺省開啟，遊戲商戶配置逐筆關閉（採用） | 無 |

## 後果

- 漏設時退回驗證，最差是 staging 連不上，而不是正式環境失守（MR !2373 的 commit body）。
- 正式遊戲商戶禁止設定 `VERIFY_SSL=false`（MR !2373 的描述）。
