# TFGamingSW（雷火電競，vendor_id=114）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，TFGaming 回調扣加款 |
| 雙向 | 出站（進遊戲：logout + login）走 `Authorization: PRIVATE_TOKEN`；入站回調走 IP 白名單，**無簽章** |
| 成功判定 | `wallet` 回 `code=0`；`transfer`/`rollback` 回 `code=SUCCESS, msg=success`，失敗 HTTP 400 |
| 匯率 | 進場 `numDiv(amount, rate)`、回傳 `numMul(balance, rate, 4)` |
| game_code | 固定 `'TP01'` |
| 對帳 | GET Bet Transaction（Postman 文件）拉單；保留期待確認 |

## 陷阱（高風險）

### 1. `transfer`/`rollback` 成功 `code` 型別不一致

Controller 回 `'code' => ErrorCodeEnum::SUCCESS`（**enum case，非 `->value`**），而 `wallet` 回 `ErrorCodeEnum::SUCCESS->value`（int `0`）。JSON 序列化結果可能不同，是否符合 TFGaming 期望待確認。

### 2. 重新結算（Unsettle）不允許負餘額，與 PDF 可能相反

PDF 提示「重新結算請允許餘額扣除低於 X（負）」，但 `Unsettle::exec()` 在 `walletBalanceAfter < 0` 時發 TG 警示並拋 `INSUFFICIENT_BALANCE`（**拒絕**負餘額）。語意可能相反，待確認是否應放行。

### 3. `rollback` 端點未套 FormRequest 驗證

`transfer` 走 `TransferRequest` 驗證且以 `TransferInfoData::from(['data' => $request->all()])` 建構；`rollback` **直接吃 `Request`** 並 `new TransferInfoData($request->all())`。兩者建構子 `$data` 結構需一致才能正確讀 `loginName`，待確認 Spatie `::from(['data'=>...])` 解析是否如預期。

## BetRecord 狀態機（`TfgamingswBetRecord.status`）

```
下注 Bet         → 建 BR status=NO_RESULT(0)，win_lose=-amount，先扣泥碼再扣主錢包
結算 Settle      → 建結算 BR status=IS_SETTLEMENT(9)；原下注 BR → SETTLED(1)；寫 TBR（key 'tfgaming'）
重新結算 Unsettle → 建沖銷 BR status=IS_UNSETTLEMENT(10)（win_lose 負）；原 BR reset 回 NO_RESULT(0)
回滾 Rollback     → 原 BR status=CANCEL(-1)，退款（主錢包 + 泥碼），payout_time 寫入
```

| 值 | case | 出現於 |
|---|---|---|
| 0 | `NO_RESULT` | 下注建立 / Unsettle reset |
| -1 | `CANCEL` | Rollback |
| 1 | `SETTLED` | Settle 後原下注 BR |
| 8 | `IS_ROLLBACK` | enum 定義，現行 Libs 未使用 |
| 9 | `IS_SETTLEMENT` | Settle 建立的結算 BR |
| 10 | `IS_UNSETTLEMENT` | Unsettle 建立的沖銷 BR |

## 冪等性

- **下注**：`bet_id = ticketNum` 為 unique key，重複插入觸發 `QueryException 1062` → 上層轉「Bet record duplicated」**冪等忽略**。
- **回滾**：以 `ticketId` `lockForUpdate` 找 BR，`status !== NO_RESULT(0)`（已結算）→ 拋 `UNDEFINED`（"already has result"），**僅允許回滾未結算下注**。重複回滾因此拋 `UNDEFINED`，其回應碼是否符合 TFGaming「移出重試排程」期望待確認（見 gaps）。

## 實作對照重點

- **下注扣款順序**：先扣泥碼錢包（rolling chip），不足再扣主錢包。
- **結算定位**：以 `ticketDetail.id`（`reference_bet_id`）`lockForUpdate` 找下注 BR，找不到 → `WITHDRAWAL_NOT_ALLOWED`。
- **會員檢核**：`wallet`/`bet` 皆過 `account.status` + `BetHelper::isMemberFlowLimitAllowed` / `isMemberAbleToBet(...,'TP01')`。

## 整合落地建議

| 概念 | APIs 專案對應 |
|---|---|
| Vendor 註冊 | `config/vendor.php` → `VendorIdEnum::TFGAMINGSW->value (114) => Vendors\TFGamingSW\Driver::class` |
| Driver（出站 / 進遊戲） | `app/Vendors/TFGamingSW/Driver.php`（`getGameUrl` → logout + login） |
| 回調分派 Service | `app/Vendors/TFGamingSW/TFGamingSWService.php`（`getBalance`/`bet`/`settle`/`unsettle`/`rollback`） |
| 行為實作 | `app/Vendors/TFGamingSW/Libs/{Bet,Settle,Unsettle,Rollback}.php` |
| 回調 Controller / FormRequest | `External/SingleWallets/TFGamingSWController.php` / `Requests/External/TFGamingSW/TransferRequest.php` |
| DTO | `Datas/TransferInfoData.php`、`VendorInfoDetailData.php` |
| Account / BetRecord Model | `App\Vendors\Models\TfgamingswAccount` / `TfgamingswBetRecord` |
| 路由 | `routes/external/sw.php` → `prefix('tfgaming')`：`GET wallet`、`POST transfer`、`POST rollback` |
| `vendor_infos.info` | `API_URL` / `GAME_URL` / `OPERATOR_ID` / `PRIVATE_TOKEN`（出站 `Authorization`）/ `PUBLIC_TOKEN`(保留) / `DEFAULT_LIMIT_AMOUNT`(保留) |

## gaps / 待 TFGaming / RD 確認（非確認 bug）

1. **PDF 字型缺失**：超時秒數、重試分鐘/小時、HTTP 狀態碼、回應碼數值均呈方框 `▯`，本文件以實作重建。建議向客服索取可正常顯示的 PDF / 線上文件重新校驗。
2. **`description` 值對照 / rollback 端點**：需確認 TFGaming 實際打 `/rollback` 端點，或在 `/transfer` 帶 `Place bet rollback`（實作 rollback 走獨立端點）。
3. **`PUBLIC_TOKEN` 未使用**：保留於 `VendorInfoDetailData` 但出站/入站皆未引用，用途待確認。
4. **正式 Base URL / 對帳保留期 / IP 白名單來源 IP**：PDF 未提供，皆需向客服索取。
