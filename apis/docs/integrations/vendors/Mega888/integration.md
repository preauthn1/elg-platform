# Mega888（vendor_id=10）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| 廠商類型 | 電子遊戲（slot）＋ BG 視訊，**原生 App 下載式入口** |
| 錢包模式 | Transfer Wallet（轉帳錢包） |
| vendor_id | `10`（`VendorIdEnum::MEGA888`） |
| 協議 | JSON-RPC 2.0（`id`/`method`/`params`/`jsonrpc` envelope） |
| 簽名 | MD5 digest＝`strtoupper(md5(random + SN + <string> + API_KEY))` |
| Content-Type | `application/json`（實作），文件示例為 form-urlencoded |
| 注單保留期 | **5 天**（單次跨度 ≤ 1 天、≤ 1000 筆） |
| 連接方向 | 雙向：本平台 → Mega（主動呼叫）＋ Mega → 本平台（login callback） |
| 程式路徑（Driver） | `app/Vendors/Mega888/Driver.php`（繼承 `AbstractTransferWallet`） |
| Callback Controller | `app/Http/Controllers/External/TransferWallets/Mega888Controller.php` |
| Callback 路由 | `POST /external/tw/mega888/login` |
| 文件版本 | v1.4（2018-08-08，已舊，實作為準） |

---

## 高風險陷阱

### 1. `getGameUrl` 不回傳可開玩的 URL（App 下載模式）

Mega888 為**原生 App 下載式入口**，無 web/mobile 直開遊戲 URL。`getGameUrl` 實際呼叫 `example.invalid.url.download`，回傳結果中 `pc` 和 `mobile` 皆為**空字串**。

`VendorGameUrlResultData` 的實際內容：
- `pc` / `mobile`：空字串
- `app_infos.username`：`player_id`（非 username）
- `app_infos.password`：密碼提示文案（未設密碼用 `error_message.9009`，已設用 `9010`）
- `app_infos.ios_downloads` / `android_downloads`：來自 `vendor_infos.info.DOWNLOAD_URL` / `DOWNLOAD_URL2`（非 API 回傳）

前端需配合「App 下載」入口顯示邏輯，不可用一般「開啟遊戲 URL」流程。

### 2. 登入 callback 未驗 digest（安全風險）

Mega 呼叫本平台 `POST /external/tw/mega888/login` 時會帶 `digest`，但本專案的 `Mega888Controller@login` **未驗證此簽名**，僅核對 method 名稱、查 `player_id`、`Hash::check` 密碼。

風險：任何知道 URL 的人都可嘗試暴力破解帳密（雖受 IP 白名單保護，但縱深防禦不足）。

建議補上：`digest = strtoupper(md5(random + sn + loginId + secretCode))` 驗簽，不符即拒絕。

### 3. 5 天注單視窗極短

`open.mega.player.game.log.url.get` 注單明細**只能查最近 5 天**，且單次跨度不超過 1 天、每次不超過 1000 筆。

對帳工具若延誤超過 5 天，資料永久無法取得。請確保對帳排程每日執行。

### 4. amount 正/負號約定（存款/提款共用同一 API）

`open.mega.balance.transfer` 的 `amount` 欄位：
- **正數**＝轉入 Mega（`transferToVendor`，存款）
- **負數**＝從 Mega 轉出（`transferToLocal`，提款）

本專案 `transferToLocal` 以 `Common::numMul($vendorAmount, -1)` 轉負後呼叫同一支 `transfer()`。混淆正負號會導致存提款反向。

---

## 錢包流程（Transfer Wallet）

```
玩家點進 Mega888
      │
      ▼
（首次）建立帳號：open.mega.user.create → 取得 loginId（player_id）/ userId
      │
      ▼
存款（本地 → Mega）：transferToVendor → open.mega.balance.transfer（amount 正數）
      │
      ▼
取得 App 下載資訊：getGameUrl → example.invalid.url.download
（pc/mobile 空字串，前端顯示 App 下載入口）
      │
      ▼
玩家於 Mega App 以 loginId + 密碼登入
      │
      ▼
  Mega → 本平台  POST /external/tw/mega888/login（open.operator.user.login）
  本平台核對帳密 → 回應 success=1 / sessionId
      │
      ▼
玩家於 App 內遊玩（餘額在 Mega 保管）
      │
      ▼
離開遊戲 / 提款（Mega → 本地）：transferToLocal → open.mega.balance.transfer（amount 負數）
      │
      ▼
對帳：open.mega.player.game.log.url.get（5 天視窗，每日須執行）
```

### 雙向連接說明

| 方向 | API | 備註 |
|---|---|---|
| 本平台 → Mega | `open.mega.user.*`、`open.mega.balance.*`、`example.invalid.url.download`、報表類 | 本平台主動呼叫，帶 digest 簽名 |
| Mega → 本平台 | `POST /external/tw/mega888/login`（`open.operator.user.login`） | Mega 呼叫本平台，目前**未驗 digest**（見高風險陷阱#2） |

---

## 冪等性

| 動作 | 防重機制 |
|---|---|
| `open.mega.balance.transfer`（存/提款） | `bizId` 欄位（本專案傳 `transactionNo`），但 `checkBizId=0`（**不檢查唯一性**） |
| `checkTransferStatus` | 以 `bizId` 查 `open.mega.balance.transfer.query`，幂等 |

本專案固定傳 `checkBizId=0`，**Mega 端不驗重**。防重責任完全由本平台自行確保 `transactionNo` 唯一。

轉帳若無回應（TIMEOUT），應以 `checkTransferStatus` 查單（`open.mega.balance.transfer.query` 帶 `bizId`）確認最終狀態，再決定是否重試，避免重複打款。

---

## 整合落地建議

### 雙向路徑對應表

**本平台 → Mega**

| 本地方法 | Mega method | digest `<string>` | 備註 |
|---|---|---|---|
| `createAccount` | `open.mega.user.create` | 空字串（`random+sn+secretCode`） | 取得 `loginId`→`player_id`、`userId`→`user_id` |
| `getBalance` | `open.mega.balance.get` | `player_id` | 回 `result` 純量，經 `Common::numFloor($result, 4)` |
| `transferToVendor` | `open.mega.balance.transfer` | `player_id . vendorAmount` | amount 正數 |
| `transferToLocal` | `open.mega.balance.transfer` | `player_id . vendorAmount`（負） | amount = `Common::numMul($vendorAmount, -1)` |
| `checkTransferStatus` | `open.mega.balance.transfer.query` | 空字串 | 帶 `bizId=transactionNo`；只看 `error` 是否非 null |
| `getGameUrl` | `example.invalid.url.download` | 空字串 | 回 App 下載資訊，`pc`/`mobile` 空字串 |

**Mega → 本平台（callback）**

| Mega method | 本平台路由 | Controller | 驗證 |
|---|---|---|---|
| `open.operator.user.login` | `POST /external/tw/mega888/login` | `Mega888Controller@login` | IP 白名單 + 帳密核對（**未驗 digest**） |

### vendor_infos.info 欄位對應

| info 欄位 | 對應 Mega 概念 | 必填 |
|---|---|---|
| `API_URL` | 正式 API base URL | ✅ |
| `API_KEY` | secretCode（直接用，未做 SHA1+Base64 衍生） | ✅ |
| `SN` | 厅代码（`sn`） | ✅ |
| `ACCOUNT` | `agentLoginId`（代理登入帳號） | ✅ |
| `DOWNLOAD_URL` | App 下載備援連結 1 | ❌ |
| `DOWNLOAD_URL2` | App 下載備援連結 2 | ❌ |

---

## Gaps / 待 Mega 確認

| # | 問題 | 影響 |
|---|---|---|
| 1 | 登入 callback 未驗 digest | 安全縱深不足，詳見高風險陷阱#2 |
| 2 | `secretCode` 衍生：文件說 `sha1Base64(agentPassword)`，實作直接用 `API_KEY` | 若 `API_KEY` 不是衍生後的值，所有 API 簽名將驗失敗 |
| 3 | POST 格式落差：文件為 `x-www-form-urlencoded`（body: `json=<...>`），實作為 `application/json` | 若 Mega 端做嚴格 Content-Type 校驗可能失敗 |
| 4 | `checkBizId=0` 不驗重，防重完全靠本平台 | 若 `transactionNo` 重複仍可能雙轉 |
| 5 | 正式 Base URL 文件未載明（測試環境還出現兩個不一致的網域） | 正式環境一律以 Startup Form 為準 |
| 6 | 完整錯誤碼表未提供（文件僅有 `2206`） | 無法做完整的錯誤分類處理 |
| 7 | 報表類資料保留期（`player.total.report`、`video.order.page`）文件未載明 | 不確定對帳的安全窗口 |
| 8 | `open.mega.balance.transfer` 的 digest `<string>` 為 `loginId + amount`，amount 是字串還是數值格式需確認 | 若格式不一致（如 `-100` vs `-100.00`）簽名將不匹配 |
