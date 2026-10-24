# E88Sport（vendor_id=247）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill（方法論已遷市集）；本檔聚焦 E88Sport 轉帳錢包接入差異與踩坑。
> 廠商原文規格見同目錄 `api.md`（來源：工單 `target/tickets#3869` 附件 `SportService_api_ch_v3.6_转账.pdf`）。

## 速查表

| 項目 | 值 |
|---|---|
| `vendor_id` | **247** |
| `VendorIdEnum` case | `App\Enums\Vendor\VendorIdEnum::E88SPORT` |
| `tname` | `e88sport` |
| `third_party_code` | `E88SPORT` |
| 架構 | **轉帳錢包（AbstractTransferWallet）**（`is_single_wallet=0`、`open_window=1`） |
| 回應格式 | XML（`simplexml_load_string`） |
| Driver | `app/Vendors/E88Sport/Driver.php` |
| 官方規格 | 同目錄 `api.md`（SportService_api v3.6 轉帳版） |
| 工單 | `target/tickets#3869`（[GFF][TP] E88 體育轉帳錢包對接） |

## ⚠️ Transfer Key 取後 6 碼（曾與舊版文件衝突）

`api.md` v3.6 已明確寫「MD5(lowercase(APIPassword+MemberAccount+Amount)) **取後 6 位**」，但**更早版本文件**曾誤寫「前 6 位」，當時實測驗算確認應為**後 6 碼**。

驗證：

```
APIPassword   = [REDACTED]
MemberAccount = test
Amount        = 10000.0000
MD5 result    = 7491efe368da0c3781b74bca239eebc4
                                          ^^^^^^ 後 6 碼 = 9eebc4  ✓（文件範例 Key=9eebc4）
```

**正確實作**：

```php
// Amount 必須 4 位小數：number_format($amount, 4, '.', '') → 例 "10.0000"
$key = substr(md5(strtolower($apiPassword . $memberAccount . $amount)), -6);
```

HTTP 方法：GET 或 POST 均可。

## Transfer 結果務必二次確認

`api.md` 2.6 明訂：呼叫 `Transfer` 後**無論成功失敗都要呼叫 CheckTransfer**（或 `CheckTransfer2`）確認最終狀態。逾時 / 空回應不可逕判失敗。

- `CheckTransfer2` 僅保留 **7 天**資料；查無記錄回 `errcode=400001`。
- `CheckTransfer2` 的 `Amount` 正值=轉入、負值=轉出，可用於核對方向。

## 錯誤碼 → Driver 回傳對照（Transfer 相關）

| errcode | 說明 | Driver 回傳 |
|---|---|---|
| 000000 | 成功 | SUCCESS |
| 300003 | 流水號已存在（重送冪等） | SUCCESS |
| 300004 | 會員餘額不足 | FAILED |
| 300005 | Key 驗證失敗 | FAILED |
| 100001 | 幣別不可用 | FAILED |
| 400001 | 流水號不存在（CheckTransfer2） | FAILED |
| 其他 / 逾時 / 空回應 | 不確定 | TIMEOUT |

> 完整錯誤碼清單見 `api.md`「返回錯誤編號說明」。

## 遊戲入場 URL 格式

```
{MEMBER_URL}/Memberlogin.aspx?LoginCode={token}&Language={lang}&PageStyle=1&OddsStyle=1
```

- `LoginCode` 由 `Login`（api.md 2.3）取得。
- `OddsStyle`：1=my、2=eu、3=hk、4=in。

| 語系 | Language 參數 |
|---|---|
| 繁體中文 | TW |
| 簡體中文 | CH |
| 英文 | EN |
| 泰文 | TH |
| 越南文 | VN |

## vendor_infos.info 必要欄位

```json
{
  "API_URL":       "https://example.invalid",
  "MEMBER_URL":    "https://example.invalid",
  "API_PASSWORD":  "<APIPassword>",
  "AGENT_ACCOUNT": "<AgentAccount>",
  "CURRENCY":      "MYR"
}
```

> 環境別 API_URL / MEMBER_URL 與憑證見工單 `target/tickets#3869`（含 staging / production 表單）；勿寫入版本控制。

## 帳號規範

- MemberAccount 字元限制：`[a-zA-Z0-9@]`，**不分大小寫**（api.md 2.1）。
- account_prefix 建議：`e88`。
- `username` 欄位設 UNIQUE index。
- Register 回 `errcode=100002`（帳號已存在）視為成功（冪等建帳）。
