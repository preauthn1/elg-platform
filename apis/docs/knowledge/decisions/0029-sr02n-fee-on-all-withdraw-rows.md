---
type: Decision
id: decisions/0029-sr02n-fee-on-all-withdraw-rows
title: SR02N 洗分列一律計入手續費
description: SR02N 開洗分明細表的洗分列一律計入手續費與實際金額，對不到取款訂單的列手續費記 0，讓實際金額合計等於總洗分減手續費合計。
domain: 報表
tags: [SR02N, 開洗分明細表, 洗分, 手續費, 實際金額, 會員手續費, withdrawal_orders.tax, 報表, 代理]
status: stable
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:54:49+08:00" }
evidence:
  - { kind: code, ref: /app/Services/Agent/Report/Sr02nReportService.php, title: "SR02N 列表與合計", at: 2026-10-21 }
  - { kind: mr, ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2326 }
related: [rules/report-sr02n]
decided_at: 2026-09-30
decision_status: accepted
---

# SR02N 洗分列一律計入手續費

## 背景

SR02N 開洗分明細表以 APIs 重寫 API_Agent SR02 的查詢，新增手續費與實際金額兩欄（MR !2326 的描述）。洗分列裡有些對得到取款訂單，有些是客服後台直接洗分、扣分、代理洗分這類對不到取款訂單的列（MR !2326 的 commit 內文）。

## 決定

洗分列一律計入：對得到取款訂單時手續費等於 `withdrawal_orders.tax × rate`，對不到記 0；實際金額等於變更點數減手續費；非洗分列為 null。這是 PM 2026-09-30 定案的 B 案（MR !2326 的描述）。

## 考慮過的選項

| 選項 | 沒選的原因 |
|---|---|
| 只算對得到取款訂單的列（A 案） | 客服後台直接洗分、扣分、代理洗分等列的實際金額會是空的，實際金額合計也對不上總洗分，PM 認為報表讀者會誤以為漏算（MR !2326 的 commit 內文） |
| 洗分列一律計入，對不到記 0（B 案，採用） | 無 |

## 後果

- 合計恆等於總洗分減手續費合計（MR !2326 的描述）。dev 實跑 frank 2026-08 全不勾，總洗分 144651.1234、手續費合計 20、實際金額合計 144631.1234（MR !2326 的 commit 內文）。
- 合計 SQL 用 TRUNCATE 而不是 ROUND，因為列表的換算是捨去，用 ROUND 時合計與逐列加總會差尾數（MR !2326 的 commit 內文）。
