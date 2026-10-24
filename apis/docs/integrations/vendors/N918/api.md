# N918（yes918 / KISS）— API 規格

> 來源：`yes918_v10_chi_20250730.pdf`（正式環境，`https://example.invalid/ashx`，timestamp 10 位）、`agent_aaaa0001.pdf`（測試 sample，`http://0.0.0.0/ashx`，timestamp 13 位）
> 整合日期：2026-06-03
> 錢包模式：Transfer Wallet（轉帳錢包）
> Vendor ID：33

---

## 通用約定

### 請求格式

- **HTTP Method**：`GET`（文件明載「方式：HTTPGET」）
- 所有參數以 **query string** 傳遞
- **共用 endpoint**：`{API_URL}/ashx`，以 `action` 參數區分不同 API
- **驗證**：IP 白名單（N918 後台「Enter IP White List」設定）＋ `sign` 簽章

### 時間戳

| 環境 | 位數 | 說明 |
|---|---|---|
| 正式（yes918） | **10 位**（秒級） | `time()`，以此為準 |
| Sample / Test | 13 位（毫秒級） | `System.currentTimeMillis()` |

本專案實作 `Carbon::now()->getTimestamp()` = 10 位秒級，與正式一致。

### App-based（非 Web 瀏覽器）

N918 為 App 遊戲，`getGameUrl` 回傳 iOS / Android scheme URL，不提供瀏覽器遊戲入口：

```
iOS:     lobbykissgame://account={username}&password=[REDACTED]
Android: lobbykiss://lobbykiss?account={username}&password=[REDACTED]
```

### Base URL

| 環境 | URL |
|---|---|
| Production | `https://example.invalid/ashx` |
| Sample / Test | `http://0.0.0.0/ashx` |

> 實際 `API_URL` 由 N918 客服/後台提供，存入 `vendor_infos.info.API_URL`。

---

## 簽名

### 計算公式

```php
$sign = strtoupper(md5(strtolower($authcode . $userName . $time . $secretKey)));
```

步驟：
1. 字串拼接：`authcode + userName + time + secretKey`
2. `strtolower`（全部轉小寫）
3. `md5`
4. `strtoupper`（全部轉大寫）

> 注意：`getOrder` / `kick` 兩支 API 的文件簽名說明寫成 `md5((authcode + time + secretKey).tolower)`（**少了 userName**），疑為文件筆誤——須向客服確認這兩支是否真的不含 userName。

---

## 錢包流程（setServerScore ± scoreNum）

```
玩家進遊戲
  │
  ▼
transferToVendor → action=setServerScore, scoreNum > 0（上分/充值）
  │
玩家在 N918 遊玩（餘額由 N918 端持有）
  │
  ▼
transferToLocal  → action=setServerScore, scoreNum < 0（下分/提現）
```

- 上下分共用同一個 action，以 **scoreNum 正負符號**區分方向
- `type=1`（上分）/ `type=2`（下分）同步帶入；HTTP header 另加 `API: deposit`（上分）/ `API: withdraw`（下分）
- `orderid`：50 字以內、系統唯一；**重複傳回 `-1`**（天然冪等保護）

---

## API

### 1. RandomUserName — 取隨機帳號

```
GET {API_URL}/ashx?action=RandomUserName&authcode={}&time={}&sign={}
```

回應：`randUserName`（N918 建議的隨機帳號名稱）

### 2. addUser — 新增玩家

```
GET {API_URL}/ashx?action=addUser&userName={}&password=[REDACTED]
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `userName` | ✅ | 玩家帳號 |
| `password` | ✅ | 玩家密碼 |
| `authcode` | ✅ | 代理 authcode |
| `time` | ✅ | timestamp |
| `sign` | ✅ | 簽章 |

回應：`code: 1` = 成功

### 3. editUser — 修改玩家資料

```
GET {API_URL}/ashx?action=editUser&userName={}&...&authcode={}&time={}&sign={}
```

回應：`code: 1` = 成功

### 4. setServerScore — 上下分（transferToVendor / transferToLocal）

```
GET {API_URL}/ashx?action=setServerScore&userName={}&scoreNum={}&note={}&orderNum={}&type={}&authcode={}&time={}&sign={}
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `userName` | ✅ | 玩家帳號 |
| `scoreNum` | ✅ | 正值=上分（充值）；負值=下分（提現） |
| `note` | 選填 | 備註 |
| `orderNum` | ✅ | 系統唯一訂單號（50 字以內） |
| `type` | ✅ | `1`=上分，`2`=下分 |

回應：**`code: 0` = 成功**（⚠️ 與多數 API 的 `code:1` 不同）

完整回應範例：`{"acc":"test001","code":1,"money":"4930.12","msg":"success","success":true}`
（文件範例 code=1，但實作以 `error == '0'` 判斷，見落差說明）

setServerScore 錯誤碼：

| Code | 說明 |
|---|---|
| `0` | 成功 |
| `-1` | 訂單存在（重複 orderNum） |
| `-2` | 上分失敗（N918 端餘額問題） |
| `-3` | 下分失敗（玩家餘額不足） |
| `-4` | 玩家不存在 |
| `-5` | 帳號被凍結 |
| `-6` | 參數錯誤 |
| `-7` | 簽名錯誤 |
| `-8` | 代理不存在 |
| `-9` | 代理被凍結 |
| `-99` | 未知錯誤 |

### 5. getUserInfo — 查詢餘額（getBalance）

```
GET {API_URL}/ashx?action=getUserInfo&userName={}&authcode={}&time={}&sign={}
```

回應：**`code: 0` = 成功**（同 setServerScore，與多數 API 的 `code:1` 不同）

回應欄位：`score`（餘額，Driver 讀取此欄位）

> 文件未列出 getUserInfo 的 success response 欄位詳情（gap），餘額欄位名稱 `score` vs `money` 須以實測為準。

### 6. getOrder — 查詢單筆訂單（getTransRecord）

```
GET {API_URL}/ashx?action=getOrder&userName={}&orderNum={}&authcode={}&time={}&sign={}
```

> ⚠️ 簽名公式：文件寫成省略 `userName` 的版本，疑為筆誤，待客服確認。

| 欄位 | 必填 | 說明 |
|---|---|---|
| `orderNum` | ✅ | 系統訂單號 |
| `userName` | 可能省略 | 文件不一致，見落差說明 |

回應欄位：訂單狀態（詳細欄位未在文件列出，gap）

### 7. GameLog — 遊戲記錄（注單）

```
GET {API_URL}/ashx?action=GameLog&userName={}&startTime={}&endTime={}&page={}&authcode={}&time={}&sign={}
```

| 欄位 | 必填 | 說明 |
|---|---|---|
| `userName` | ✅ | 玩家帳號 |
| `startTime` | ✅ | 查詢開始時間 |
| `endTime` | ✅ | 查詢結束時間 |
| `page` | ✅ | 頁碼 |

回應欄位：注單列表（詳細欄位結構未在文件列出，gap）

### 8. UserscoreLog — 積分日誌

```
GET {API_URL}/ashx?action=UserscoreLog&userName={}&startTime={}&endTime={}&page={}&authcode={}&time={}&sign={}
```

參數同 GameLog。回應：上下分紀錄列表。

### 9. AgentTotalReport — 代理報表

```
GET {API_URL}/ashx?action=AgentTotalReport&...&authcode={}&time={}&sign={}
```

回應欄位：代理層級匯總資料（詳細欄位文件未載，gap）

### 10. PlayerTotalReport — 玩家報表

```
GET {API_URL}/ashx?action=PlayerTotalReport&...&authcode={}&time={}&sign={}
```

回應欄位：玩家層級匯總資料（詳細欄位文件未載，gap）

### 11. kick — 踢出玩家

```
GET {API_URL}/ashx?action=kick&userName={}&authcode={}&time={}&sign={}
```

> ⚠️ 簽名公式：文件寫成省略 `userName` 的版本，疑為筆誤，待客服確認。

### 12. getGameUrl — 取得遊戲 URL（非文件 API，Driver 自行組 URL）

不走 API endpoint，Driver 直接組合 App scheme：

```
iOS:     lobbykissgame://account={username}&password=[REDACTED]
Android: lobbykiss://lobbykiss?account={username}&password=[REDACTED]
下載頁:  {DOWNLOAD_PAGE}（iOS / Android 下載連結）
```

---

## 成功判斷彙整表

| Action | 文件成功碼 | Driver 實作判斷 | 備註 |
|---|---|---|---|
| `addUser`、`editUser`、`disable` 等 | `code: 1` | `error == '0'` | 多數 API |
| `setServerScore` | `code: 0` | `error == '0'` | 與多數 API 不同！ |
| `getUserInfo` | `code: 0` | `error == '0'` | 文件範例缺 success response |
| `getOrder`、`kick` | 未明載 | `error == '0'` | 文件有 gap |

> 統一結論：**Driver 一律以 `error == '0'` 判斷**，不依賴 `code` 或 `success` 欄位。`error` 欄位不存在於任何文件範例，疑為正式 API 與文件版本不同步——以實測環境回應為準。

---

## 實作落差

1. **`error` 欄位不在文件中**：Driver 以 `error == '0'` 判斷成功，文件範例完全沒有 `error` 欄位；文件範例的成功旗標是 `success: true`。這是正式 API 與文件不同步的問題，須以實測正式環境回應格式為準。

2. **`success` 欄位 Driver 不使用**：文件範例回傳 `success: true/false`，Driver 不讀此欄位，改讀 `error` 欄位。

3. **餘額欄位名稱**：文件範例顯示餘額在 `money` 欄位；Driver `getBalance()` 讀取 `score` 欄位。文件 `getUserInfo` 的 success response 欄位未列出（gap），須以實測確認。

4. **`getOrder` / `kick` 簽名省略 `userName`**：文件對這兩支的簽名公式少了 `userName` 拼接，與其他 API 不一致，疑為文件筆誤——須向客服確認是否真的不含 userName。

5. **timestamp 位數雙版本**：正式 10 位、sample 13 位並存；Driver 採 10 位，與正式一致。接新環境前確認位數。

6. **轉帳 dead code**：`transfer()` 內有兩段 `if (!$json)` 判斷，第一段 `return TIMEOUT` 後第二段永遠不會執行——功能正確但為 dead code，建議 RD 清理。
