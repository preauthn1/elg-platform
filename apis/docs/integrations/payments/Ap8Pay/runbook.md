# Ap8Pay 維運 runbook（代收回調防線與排查）

> 寫給：接手 Ap8Pay 的開發與維運。廠商規格看 [`api.md`](api.md)，這份只放「出事時怎麼辦」。
> 來源：工單 target/tickets#4446 的實作與 2026-10-05 在 Dev 測試商戶的實測。

## 名詞

| 名詞 | 意思 |
|---|---|
| taskCode | 三方的代收任務編號，建單回應的 `data.code`，落庫在 `orders.third_party_order_no` |
| 查單 | `GET {APIUrl}/merchants/deposits/{taskCode}`，向 AP8 問一筆代收的真實狀態與金額 |
| 三道防線 | 回調的來源 IP／訂單歸屬／以查單為準，實作在 `Ap8Pay\DepositCallback` |

## 站點對照

| 站點 | ACF | cash_flow | log 在哪 | 備註 |
|---|---|---|---|---|
| VPlus33（正式） | 41、42 | 69 | **Grafana / Loki**，保留 30 天（見 §2.1） | 唯一有真實交易的站；**沒有 GCP project**，`gcp-log-troubleshoot` 那套查不到 |
| Dev（測試商戶） | 183 | 69 | GCP `tp-wz3-rd` | 商戶金鑰不同，但 **APIUrl 與系統金鑰與正式相同**，不是沙箱 |

---

## 0. 一分鐘速查

| 症狀 | 先看哪裡 | 處理 |
|---|---|---|
| TG 告警「代收回調遭拒：callback source IP not in whitelist」 | 告警內文的來源 IP | §1 確認真偽後把 IP 加進白名單 |
| 玩家說付了錢沒上分 | 查單（§2） | 三方是 SUCCESS 就是回調沒進來，照 §2 補；是 PENDING／FAILED 就是沒付成功 |
| 代收整批都不上分 | `cash_flows.whitelist` 是不是空的 | 空＝fail-closed 全擋，見 §4 部署 |
| TG 告警「查單回報 SUCCESS 但沒有金額」 | §3 已知行為 | 停下來找廠商，不要自行放寬 |

---

## 1. 回調來源 IP 白名單

**存在哪**：`cash_flows.whitelist`（逗號分隔），Olympus 後台「金流商 → 金流商管理」可改，每次修改留操作紀錄。

**🔴 fail-closed**：白名單為空＝**所有回調一律拒絕**，不是「關掉檢查」。Ap8Pay 回調沒有簽名，這是刻意的。

### 廠商給的清單不等於實際來源

工單 #2989 廠商提供 5 組：`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`、`0.0.0.0`。

2026-10-05 在 Dev 實測，**同一筆回調的 7 次送達用了兩個來源 IP，兩個都不在清單內**：

| 時間（UTC） | 來源 IP | 說明 |
|---|---|---|
| 04:52:18 | `0.0.0.0` | 首次送達 |
| 04:52:57 ～ 05:32:26 | `0.0.0.0` | 後續 6 次重試 |

兩個都是 AWS 新加坡網段，跟廠商清單裡的 `13.250.*` / `13.228.*` 同區但不同主機 —— 看起來是一池機器輪流出，不是固定幾台。Dev 的白名單已另外加上這兩組（共 7 組）。

**正式站沒有加**，因為正式站的實際來源 IP 查不到：

- `orders.ip` 存的是**建單時會員的 IP**（`OrderService::createOrder` 寫的），回調不會更新它，所以歷史訂單裡沒有任何回調來源 IP
- VPlus33 的 log 查得到（§2.1），但 **Loki 只保留 30 天**（2026-10-05 實測最舊到 2026-09-05），而 AP8 最後一筆訂單是 2026-06-07，早已過期

⚠️ **所以正式站上線後的第一筆真回調就是驗證時刻，而驗證失敗的方式是「全部擋掉」。** 好在誤擋會立刻發 TG 告警（見 §2），不用等玩家客訴。

---

## 2. 回調被擋 / 回調沒來

### 2.1 確認回調到底有沒有進來

Dev 查 GCP log（project `tp-wz3-rd`）：

```
resource.labels.container_name="apis"
AND jsonPayload.payload.context.profile.route:"external/api/deposit/notify"
```

關鍵字：

- `Deposit callback received` — 進站了（後面接的 context 有來源 IP 與回調 body）
- `callback source IP not in whitelist` — 被防線 1 擋掉
- `callback order belongs to another payment channel` — 被防線 2 擋掉
- `deposit callback query failed` / `ignored: provider status is` — 防線 3 沒放行

**正式站（VPlus33）沒有 GCP project**，log 在自架的 Grafana / Loki，`gcp-log-troubleshoot` 那套查不到。container 名稱與 structured payload 格式與 GCP 完全相同，只是查詢語法換成 LogQL：

```bash
set -a; . ~/.gff/secrets.env; set +a      # VPLUS33_GRAFANA_TOKEN
P="http://0.0.0.0:3000/api/datasources/proxy/uid/P8E80F9AEF21F6940/loki/api/v1"

curl -s -G -H "Authorization: Bearer $VPLUS33_GRAFANA_TOKEN" \
  --data-urlencode 'query={container="apis-external"} |= "Ap8Pay"' \
  --data-urlencode "start=$(date -u -d '2 hours ago' +%s)000000000" \
  --data-urlencode "end=$(date -u +%s)000000000" \
  --data-urlencode 'limit=50' "$P/query_range"
```

⚠️ `query=` 前綴不可省：少了它 curl 會把整串當成沒有 key 的值，Loki 回
`HTTP 400 parse error: syntax error: unexpected $end`，看起來像 LogQL 寫錯，其實是參數少了 key。

⚠️ **Loki 只保留 30 天**，超過就什麼都查不到（2026-10-05 實測，2026-10-07 複驗：09-06 查得到、09-01 查不到）。超出保留期的往返要靠
`order_request_logs`（工單 #4446 另建，目前只有 Ap8Pay 寫入）。

### 2.2 判斷被擋的回調是真是假

**不要靠回調內容判斷** —— 它沒有簽名，內容可以偽造。拿訂單的 taskCode 去查單，三方自己說了算：

```bash
DB_CONNECTION=<站點> php artisan cash-flow:third-party-query <acf id> --order=<我方訂單號>
```

查回來的 `uniqueCode` 必須等於我方訂單號、`status` 與 `amount` 與回調一致，才算佐證。2026-10-05 就是用這招確認被擋的那筆是真回調（三方同樣回 `FAILED` / `11.00`）。

### 2.3 補 IP

確認是真回調之後，把來源 IP 加進 Olympus 的金流商管理頁。**不要看到什麼 IP 就加** —— 一定要先有 §2.2 的查單佐證，否則等於讓攻擊者自己把 IP 加進白名單。

### 2.4 回調始終沒來

三方查單已是終態、我方訂單卻還停在 `status=0`、`remark` 空 ＝ 回調沒送到或被擋。查單結果是 `SUCCESS` 時要人工補上分，`FAILED` 則不用處理。

AP8 的重試排程是 **1、3、5、10、20、30 分鐘**（spec 載明，實測相符），跑完就不再送，所以超過約 70 分鐘沒處理就只能人工補。

### 2.5 被擋的訂單會停在 CREATED，這是刻意的

回調被任何一道防線擋下時，**訂單狀態、金額、點數一律不動**（工單 #4446 需求 1 的預期結果原文）。
不可以改成「被擋就標失敗」——被擋的回調是不可信的輸入，讓它動得了狀態就等於開一個攻擊面：
任何人都能從任意 IP 連發偽造回調，把所有待付訂單打成失敗，玩家付款途中訂單就沒了。

連帶後果：原本「三方送 FAILED ⇒ 訂單標失敗」的行為，在回調被擋時不會發生，訂單會一直停在
`status=0`，與三方的真實狀態脫節，而且**目前沒有任何東西會自動把它拉回來**。
2026-10-05 Dev 的 5 筆就全是這樣（其中 13986 三方已是 `FAILED`，我方仍為 0）。
處理方式同 §2.4 的人工反查。自動化（排程掃 `status=0` 的 AP8 訂單反查補處理）尚未實作。

---

## 3. 查單 API 的已知行為

| 情境 | 回應 |
|---|---|
| 未付款 | `status: PENDING`，**沒有 `amount` 欄位** |
| 終態 | 帶 `amount`（實測 `FAILED` 有帶；`SUCCESS` 尚未驗證，見下） |
| taskCode 不存在 | HTTP 404 `{"error":{"type":"NotFound","message":"Deposit not found."}}` |
| 舊單 | 同樣 404。實測線上 14 筆（最新 2026-05-08）與 Dev 8 筆（2026-01-05）全查不到，**推定是三方的保留期限，不是接口故障** |
| api-key 錯 | HTTP 400 `APIValidationError`（所以 404 代表憑證有效、只是單不存在） |
| 商戶健檢 | `GET {APIUrl}/me` 回 200 且 `data.status = ACTIVE` 即憑證有效 |

> 🔴 **`SUCCESS` 會不會帶 `amount` 尚未驗證**（至 2026-10-05 沒有任何成功的測試單）。
> `DepositCallback` 對「查單 SUCCESS 但沒有金額」是**不上分並發 TG 告警**——沒有可信的上分依據就不上分。
> 真的踩到，先找廠商確認，**不要自行改成退回用回調金額**，那等於把防線 3 拆了。
> `FAILED` 已實測會帶 `amount`，所以這個風險不高，但沒排除。

---

## 4. 部署注意

**migration 一定要跑**：`2026_10_05_000001_add_whitelist_to_cash_flows_table`。

欄位不存在時 `cashFlow->whitelist` 讀出來是 `null` ⇒ 白名單視為空 ⇒ **fail-closed 擋掉該站所有 AP8 回調**，而且不會有任何錯誤訊息，症狀只有「代收都不上分」。

migration 會把廠商那 5 組預填進 `third_party_code = 'D_AP8'` 那一列，只在欄位還是空的時候寫，不會覆蓋營運後來的調整。新站台另由 `Data/cash_flows.php` 的同步模板帶入。

---

## 5. 金額：本渠道沒有「下單金額」

**建單刻意不送 `amount`。** spec 有這個選填欄位，但 2026-10-05 實測帶了之後三方收銀台的金額輸入框仍是空的、連預填都沒有，玩家照樣輸入任意金額（GCP log 訂單 `202610059431218273`，出站確實帶了 `"amount": "10.00"`、三方回 200）。這個參數對 AP8 沒有約束力。

連帶後果，接手前務必知道：

1. **金額完全由玩家在三方頁面決定**，我方建單時不知道，所以沒有「回調金額 vs 下單金額」可以比對。
2. 各站的 `order_types.info` 因此不帶金額輸入欄位（VPlus33 #98、Dev #540 皆為 `{"data":[]}`）。問了玩家金額卻約束不了，只會讓人誤以為對得起來。
3. ⚠️ **會員儲值限額對本渠道形同失效**：`DepositController::createOrder()` 的單筆與單日累計金額檢查包在 `if (amount > 0)` 內，amount 永遠是 0 ⇒ 兩道都跳過，只剩單日筆數限制。要防大額只能在**收到金額之後**把關（工單 #4446 §3 待 PM 決定）。

---

## 6. Dev 不是沙箱

`agent_cash_flows` #183（Dev）與 VPlus33 #41/#42 的 **`APIUrl` 與 `HeaderApiKey`（系統金鑰）完全相同**，只有 `MerchantApiKey`（商戶金鑰）不同。

所以在 Dev 做的任何事都會打進 AP8 正式平台，只是記在測試商戶底下。測試商戶資訊見工單 #2989。

---

## 7. 尚未驗證的事

接手時這幾項還沒有答案，別當成已知：

1. **查單在 `SUCCESS` 時帶不帶 `amount`** —— 影響每一筆代收能不能上分（§3）
2. **正式站回調的真實來源 IP** —— 影響正式站上線後回調會不會被全擋（§1）
3. **回調的 `code` 與建單的 `data.code` 是否永遠相同** —— 新單一律用落庫的 taskCode，不受影響；只有「本次改動之前建立、仍在等回調」的舊單會退回用回調的 `code`，而 AP8 自 2026-06-07 起無新單，在途舊單為 0

---

*建立日期：2026-10-05 / 來源：工單 target/tickets#4446*
