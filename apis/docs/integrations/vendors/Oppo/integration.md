# Oppo（vendor_id=169）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 轉帳錢包（Transfer Wallet）；玩家餘額由 OPPO 端保管，本平台用 `transferin`/`transferout` 主動轉帳 |
| 呼叫方向 | **本平台 → OPPO**（本平台為 client，主動呼叫）；OPPO 無 seamless callback |
| 請求格式 | `POST` + `application/json`；每筆帶 `Hash`（MD5 簽名） |
| 驗證 | `Agent`（代理名）+ `Hash` 簽名；無 IP 白名單機制於 Driver 內 |
| 轉帳成功判定 | `data.status == '1'` → `SUCCESS`；無 `data.status` → `FAILED`；無 JSON → `TIMEOUT`；有 `error` → throw |
| 金額處理 | `Common::numFloor($credit, 2)`（**截斷**至 2 位，非四捨五入） |
| 對帳 | 主動拉 `getGameLog` / `getGameSummary`（見 api.md），無回調 |

## 簽名陷阱（高風險）

`Driver::sign()`：`md5(strtolower(implode($data) . '8888' . $api_key))`。

> ⚠️ **三重隱性依賴**：
> 1. `implode($data)` **不帶 key、不帶分隔符**，簽名值完全依賴 PHP array 的**插入順序**（`Agent`→`Playerid`→`Amount`）。任何欄位順序變動都會讓簽名失效且難以察覺。
> 2. `'8888'` 為**硬編碼 magic salt**，未抽成 config／enum。
> 3. `Amount` 小數點格式曾反覆（2025-09-09 要求去尾 0、2026-02-24 又改回保留原樣，見 Driver 註解）。對接時若簽名驗不過，**第一個要查的就是 Amount 格式與欄位順序**。

## 轉帳流程與對帳

```
存款：transferToVendor → POST /api/transferin （Amount 正值，type=1）
   │
進遊戲：getGameUrl → POST /api/accountlogin → 取 data.launch_url
   │
提款：transferToLocal → POST /api/transferout （type=2）
   │
對帳：主動拉 getGameLog / getGameSummary 寫入 OppoBetRecord（非回調）
```

- 存提同一 `transfer()`，方向由 `type`（1 存 / 2 提）決定，各打不同 endpoint。
- 餘額查詢 `getBalance` → `POST /api/playerinfo` 取 `data.Playerbalance`。

## 實作對照重點

- **getGameUrl**：取 `data.launch_url`，pc / mobile 同一網址。
- **getBalance 失敗判定**：用 `$json['error'] !== null`（直接存取 key）；而 `transfer()` 用 `isset($json['error']) && $json['error'] !== null`（先 isset）。兩處不一致，見 gaps。

## 整合落地建議

| OPPO 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Oppo/Driver.php`（`extends AbstractTransferWallet`） |
| 帳號 / 注單 Model | `app/Vendors/Models/OppoAccount.php`（`player_id`）/ `OppoBetRecord.php` |
| 轉帳實作 | `transferToVendor`（存）/ `transferToLocal`（提）；共用 `transfer($username, $type, $credit, $billNo)` |
| `vendor_infos.info` | `API_URL` / `API_KEY` / `AGENT_NAME`（`setInfo()` 三鍵，缺一報錯） |
| 註冊位置 | `config/vendor.php` → `VendorIdEnum::OPPO->value (169) => Vendors\Oppo\Driver::class` |
| 回調 controller / routes | **無**（轉帳錢包，對帳採主動拉單） |

## gaps / 待 OPPO / RD 確認（非確認 bug）

1. **無補單 API**：`transfer()` 逾時回 `TIMEOUT` 後，Driver 未實作 `checkTransferStatus()`，OPPO 文件亦未提供轉帳狀態查詢 endpoint → 逾時後最終狀態如何確認/補單，待確認。
2. **`error` key 存取不一致**：`getBalance()` 直接讀 `$json['error']`，若 `playerinfo` 成功回應未帶 `error` key 會觸發 undefined index → 待確認 OPPO 成功回應是否恆帶 `error: null`。
3. **`'8888'` magic salt 硬編碼**：建議抽成 config，並確認此值是否所有白標／環境一致。
4. **`Amount` 截斷至 2 位**：`numFloor` 為無條件捨去，與部分廠商「四捨五入」約定不同，確認 OPPO 金額精度規則。
