# Ybet（vendor_id=52）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Wallet Mode | Transfer Wallet（Ybet 持有餘額） |
| Callback 方向 | 代理 → Ybet 主動呼叫；**無回調** |
| 驗證方式 | MD5 ASCII sort + `&key=` |
| 成功判定 | 多數接口 `result === 0`；注單/報表接口 `result === 1`（見下方） |
| 時區 | 未明確規範 |
| 資料保留 | 文件未載明，**待向 Ybet 客服確認** |

---

## result 成功值不一致（高風險）

| 接口範圍 | 成功值 |
|---|---|
| §2.2–2.7（login / kickout / register / update / info / balance / dw / trade / quota） | `result === 0` |
| §2.10–2.18（getbets / removebets / getgames / game/history / bets/stat / bets/detail / bets/follow / account/detail / bets/settle） | `result === 1` |

Driver 實作中對每個方法的成功判斷必須逐接口確認，不可統一用一種判斷。`getbets` 等成功時為 `1`，若誤判為 `0` 會把成功當失敗處理。

---

## 接口前綴不一致（高風險）

Driver 呼叫時存在兩種寫法並存：

| 方法 | 呼叫路徑 | 前綴 |
|---|---|---|
| `getGameUrl()` | `/login` | **無 `/api`** |
| `getBalance()` | `/user/balance` | **無 `/api`** |
| `transferToVendor()` | `/user/dw` | **無 `/api`** |
| `transferToLocal()` | `/user/dw` | **無 `/api`** |
| `checkTransferStatus()` | `/api/user/trade` | **有 `/api`** |

推測 `vendor_infos.info.API_URL` 可能已內含 `/api` 段，或 Ybet 網關同時接受兩種路徑。**需向 RD 確認 `API_URL` 實際值與 Ybet 路由規則**，否則部分接口必然 404。

---

## 參數放置位置

雖然使用 `POST`，本專案實作**把所有參數組到 URL query string**（`Driver::sendRequest()` 程式碼註解：「雖然是 post 請求, 但必須把參數組合到網址而不是 body 中」）。文件未明說，正式對接前建議向 Ybet 確認。

---

## transferToVendor vs transferToLocal 非對稱逾時處理

| 方法 | `response` 非陣列時 |
|---|---|
| `transferToVendor()`（存款） | → `FAILED` |
| `transferToLocal()`（提款） | → `TIMEOUT`（保守設計） |

提款時不確定是否成功，不直接判失敗，交由上層 `checkTransferStatus` 續查。存款失敗則直接判失敗以避免重複存款。此設計屬刻意保守，非 bug。

---

## checkTransferStatus 時間窗窄

`checkTransferStatus()` 查單時：`begintime` = 交易建立時間 -5 秒、`endtime` = +5 秒。若代理系統時鐘偏差或 Ybet 側記錄時間偏差超過 5 秒，會導致查不到對應記錄，上層持續 TIMEOUT 狀態。

---

## tradeno 必須帶

`transferToVendor()` 和 `transferToLocal()` 固定帶 `tradeno`（= `VendorTransferData::transactionNo`）。`checkTransferStatus()` 以 `tradeno` 命中記錄後依 `state` 判斷最終狀態。若不帶 `tradeno`，後續查單無從定位。

---

## getGameUrl 固定 iscreate=0

實作登入時固定 `iscreate=0`（不自動建號）。若玩家帳號在 Ybet 側不存在，會回 `-1011 未註冊`。帳號建立流程（`/api/register` 或 `iscreate=1`）需確認由上層 `AbstractTransferWallet` 的開帳流程處理，否則首次登入永遠失敗。

---

## 空值不參與簽名

組裝簽名參數前，必須確保不帶空字串欄位。若上層傳入含空值的參數，`http_build_query` 仍會輸出 `key=`，導致 Ybet 端簽名驗證失敗（`-1007 簽名錯誤`）。

---

## 對帳閉環

`getbets` 拉取未處理注單 → 入庫 → `removebets` 回報已消費（同 WBET 模式）。只有 `state=1`（結算）的注單才有最終輸贏；`state=-2/-1` 須沖銷對應注單。

---

## Driver 方法對照

| Driver 方法 | 呼叫接口 | 成功判斷 |
|---|---|---|
| `getGameUrl()` | `/login` | `result >= 0`，取 `openurl` |
| `getBalance()` | `/user/balance` | `result >= 0`，取 `balance` |
| `transferToVendor()`（存款） | `/user/dw`（`amount` 正數） | `result === '0'` → SUCCESS；其餘 → FAILED；逾時 → TIMEOUT |
| `transferToLocal()`（提款） | `/user/dw`（`amount` = `'-'` + 金額） | 同上；連線逾時 → TIMEOUT；response 非陣列 → TIMEOUT |
| `checkTransferStatus()` | `/api/user/trade` | 命中 `tradeno` 後依 `state`：`11`→SUCCESS、`-1`→FAILED、其他→TIMEOUT |

---

## 實作落差（待確認）

1. **接口前綴不一致**：`getGameUrl/getBalance/transferToVendor/Local` 無 `/api` 前綴；`checkTransferStatus` 有 `/api` 前綴。需確認 `API_URL` 實際值。
2. **參數放置位置**：文件未明說，實作組到 URL query string，需確認 Ybet 是否接受。
3. **`getGameUrl` 固定 `iscreate=0`**：帳號建立流程需由上層處理，否則首次登入失敗。
4. **`transferToLocal` 連線逾時回 TIMEOUT**：`transferToVendor` 同情況回 FAILED，兩者不對稱，屬刻意保守設計。
5. **MD5 大小寫**：實作 `md5()` 預設輸出小寫與文件示例一致，無實質落差；若 Ybet 要求大寫需調整。

---

## 整合落地建議

| Ybet 概念 | APIs 專案對應 |
|---|---|
| `appid` | `vendor_infos.info.APP_ID` |
| `Secret Key`（簽名金鑰） | `vendor_infos.info.API_KEY` |
| Base URL | `vendor_infos.info.API_URL`（確認是否含 `/api` 段） |
| `username`（玩家帳號） | `ybet_accounts.username`（`YbetAccount`） |
| Driver | `app/Vendors/Ybet/Driver.php`（`extends AbstractTransferWallet`） |
| Driver 註冊 | `config/vendor.php`：`VendorIdEnum::YBET->value (52) => Vendors\Ybet\Driver::class` |
| 轉點（存/提款） | `transferToVendor` / `transferToLocal` → `/user/dw`；`tradeno` = `transactionNo` |
| 轉點查單 | `checkTransferStatus` → `/api/user/trade`（時間窗 ±5 秒，`index=0`、`size=1`） |
| 注單同步（對帳） | `/api/data/getbets`（拉注單）→ 處理 → `/api/data/removebets`（回報） |
| BetRecord | `YbetBetRecord`：`bet_id`（= `serialnumber`）、`bet`、`win_lose`、`bet_time`、`settle_time` |
| Callback | **無**（轉帳錢包不需 callback） |
| 對帳保留期 | **文件未載明**，需向 Ybet 客服確認 |
| SBR 重建 | UTC 時間；`payoutTimeColumn` = `settle_time` |
