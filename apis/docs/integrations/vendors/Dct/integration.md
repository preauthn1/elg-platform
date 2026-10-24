# Dct（vendor_id=69）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `69`（`VendorIdEnum::DCT`） |
| 錢包模式 | 轉帳錢包（Transfer Wallet） |
| 平台性質 | DC ACE 遊戲聚合平台（單一接口串接 30+ 家 provider） |
| 成功碼 | `code = 1000` |
| 主要 Base URL | `api_url`（大多數 endpoint） |
| 投注數據 Base URL | `getBetData_url`（2.6 getBetData、2.8 getPlayerLastWager 專用） |
| 簽名算法 | `strtoupper(MD5(<拼接字串> + api_key))` |
| 拼接規則 | 各 endpoint 不同，詳見 `api.md`〈簽名〉節 |
| 時區 | getBetData 時間欄位為 UTC（`yyyy-MM-dd HH:mm:ss`） |
| 共用 Driver | `DCT`、`PNG`、`RELAX`、`HS` 四個 VendorId 共用同一 `Vendors\Dct\Driver` |

---

## 高風險陷阱

### 1. 兩個 Base URL — 混用必 404

`getBetData`（2.6）與 `getPlayerLastWager`（2.8）使用 **`getBetData_url`**，其餘所有 endpoint 一律用 `api_url`。設定中兩個值均須配置，不可共用。

### 2. 各 endpoint 的 sign 拼接順序各不相同

共 14 個 endpoint，每個拼接組成不同。目前 Driver 只實作 5 個（全走 `brand_id + brand_uid/bill_no + api_key`），若日後補實作其他 endpoint（如 tryGame、getBetData、FreeSpin 系列），**不可沿用現有 `sign($username)` 方法**，須對照 `api.md`〈簽名〉節逐一查閱。

易錯對照：

| endpoint | 拼接組成 | 與現有 `sign()` 差異 |
|---|---|---|
| `tryGame` | `brand_id + game_id + api_key` | 傳的是 game_id，不是 brand_uid |
| `getBetData` | `brand_id + start_time + end_time + api_key` | 三段拼接 |
| `getGameList` | `brand_id + api_key` | 只有兩段 |
| `getReplay` | `brand_id + round_id + api_key` | 傳 round_id |
| `createFreeSpin` | `brand_id + end_time + api_key` | 傳 end_time |
| `addFreeSpin` | `brand_id + freespin_id + api_key` | 傳 freespin_id |
| `queryFreeSpin` | `brand_id + game_id + api_key` | 傳 game_id |

### 3. `code=1001/1002` 不是明確失敗 — 必須走 checkTransferStatus

`credit`/`withdraw` 出現 timeout 或 `code=1001`（System error）/`1002`（Unknown）時，**結果未定**，不可直接判為失敗。文件要求**等 300 秒**後呼叫 `checkTransferStatus`（帶 `bill_no`）確認真正結果。誤判為失敗會導致漏帳或重複轉帳。

### 4. `checkTransferStatus` 回傳的是完整交易記錄，不是三態 status 欄位

查詢成功（`code=1000`）時，回傳 `data.bill_no` / `transfer_type` / `transfer_time` 等，**代表交易確認存在**（SUCCESS）。若回 `5007 Bill no not exist` 則交易未完成（FAILED 或待處理）。Driver 以此區分成敗。

### 5. getBetData 未在 Driver 實作（對帳功能缺失）

Driver 目前只涵蓋轉帳錢包必要流程（loginGame / getBalance / credit / withdraw / checkTransferStatus）。對帳所需的 `getBetData`（2.6）**完全未實作**，且 `VendorInfoDetailData` 也未定義 `getBetData_url` 欄位。對帳若依賴 DC 拉單，須另行補實作。

---

## 轉帳流程

```
進遊戲：
  本地扣款
  → POST {api_url}/dct/credit（bill_no = transactionNo）
  → code=1000 → SUCCESS → POST /dct/loginGame 取 game_url → 開遊戲
  → code=1001/1002 或 timeout → 等 300 秒 → checkTransferStatus 復核
  → 其他 code → FAILED

離開遊戲：
  POST {api_url}/dct/getBalance 查 DC 餘額
  → POST {api_url}/dct/withdraw（bill_no = transactionNo）
  → 同上三態判斷

復核（checkTransferStatus）：
  POST {api_url}/dct/checkTransferStatus（帶 brand_uid + bill_no）
  → code=1000 且 data.bill_no 存在 → SUCCESS
  → code=5007/5042 → FAILED（交易不存在）
  → code=1001/1002 → 仍未定，視為 TIMEOUT
```

### 轉帳三態映射

| DC `code` | 語意 | 映射 Enum | 處置 |
|---|---|---|---|
| `1000` | 成功 | `SUCCESS` | 轉帳完成 |
| `1001` System error / `1002` Unknown | 結果未定 | `TIMEOUT` | 300 秒後走 checkTransferStatus |
| 其他（非 1000/1001/1002）| 明確失敗 | `FAILED` | 視為失敗 |
| 回應非 array / 無 code | 異常 | `TIMEOUT` | 同未定，待復核 |

---

## 冪等性

| 機制 | 說明 |
|---|---|
| `bill_no` 唯一鍵 | credit/withdraw 請求帶商戶自訂的交易 ID，DC 以此做冪等判斷；重送同一 `bill_no` 會回 `5006 Bill no already exist` |
| checkTransferStatus | 以 `bill_no` 查詢交易是否存在；`5007 Bill no not exist` = 未成交 |

---

## 整合落地建議

| DC ACE 概念 | APIs 專案對應 |
|---|---|
| `api_url` | `vendor_infos.info.API_URL` |
| `getBetData_url` | **`VendorInfoDetailData` 尚無此欄位**，日後補 getBetData 須新增（如 `GET_BET_DATA_URL`） |
| `api_key` | `vendor_infos.info.API_KEY`（簽名用，不進 request body） |
| `brand_id` | `vendor_infos.info.BRAND_ID`（String(7)） |
| `currency` | `vendor_infos.info.CURRENCY` |
| `country_code` | `vendor_infos.info.COUNTRY_CODE` |
| `provider` | `vendor_infos.info.PROVIDER`（決定走哪家底層遊戲商） |
| `brand_uid`（玩家） | `DctAccount.username`（`app/Vendors/Models/DctAccount.php`） |
| Driver | `app/Vendors/Dct/Driver.php`（`extends AbstractTransferWallet`） |
| 注單 Model | `app/Vendors/Models/DctBetRecord.php` |
| 轉帳實作 | `transferToVendor` → `/dct/credit`；`transferToLocal` → `/dct/withdraw`；異常復核 `checkTransferStatus` |
| Callback controller | **無**（轉帳錢包，DC 不回調本地） |
| 語系映射 | Driver `mapLanguageCode()`：ZH_TW→`zh_hant`、ZH_CN→`zh_hans`、ID_ID→`id`、VI_VN→`vi`、TH_TH→`th`、其餘→`en` |
| 共用 Driver 的 VendorId | `DCT`(69) / `PNG`(97) / `RELAX`(71) / `HS`(72)（同一 Driver，由 `PROVIDER` 欄位區分遊戲商） |
| 遊戲清單同步 | `Driver::getGameList()` 打 `/dct/getGameList`（`provider` = `info.PROVIDER`），標 `ShouldSyncGameList` 進 `update_game_list` 每小時排程，四個 vendor id 一併納入；亦可 Olympus 系統操作手動刷（見下方「遊戲清單同步」） |

---

## 遊戲清單同步

`Driver::getGameList()` 遷自 RD-Studio `get_dct_games`，打 `/dct/getGameList`（簽名 `strtoupper(md5(brand_id + api_key))`，與其他端點拼接不同，Driver 另立 `signGameList()`）。`provider` 帶商戶 `info.PROVIDER`，所以同一 Driver 下的四個 vendor 各自取到自家底層遊戲商的清單。Driver 標 `ShouldSyncGameList`，由 `update_game_list` 每小時排程派發；也可在 Olympus 系統操作頁手動刷。

| 三方欄位 | 落地 | 說明 |
|---|---|---|
| `game_id` | `games.game_type` | 唯一鍵；缺值的列略過 |
| `game_name` | `games.name` | |
| `game_name_cn` | `game_names`（ZH_CN） | 空值不寫 |
| `game_icon` | `games.image` | |
| `game_type` | `games.type_id` | 自由文字關鍵字比對：含 `slot` → SLOTS(2)；含 `InstantWin`／`Scratchcard` → ARCADE(15)；其餘落 EVENT_AND_OTHERS(98) 並記 warning |
| 整列 JSON | `example.invalid` | 含 `rtp`、`content_type`、`release_date` 等供營運比對 |
| — | `games.status` | 新增列預設 `0`（沿用舊指令，人工審核後上架） |

`GameListSyncService` 為純新增：既有遊戲欄位一律不動（舊指令 `--game_update` 覆寫簡中名稱的行為不沿用），「DB 有、API 無」只偵測「三方未提供」記 warning 提示人工關閉，不會自動停用。三方 HTTP 200 但 `code` 非 `1000` 時回空清單並記 error log；HTTP 層錯誤拋 `ApiException` 讓排程失敗可見。

---

## Gaps

| 項目 | 說明 |
|---|---|
| getBetData 未實作 | Driver 無對帳拉單實作；`VendorInfoDetailData` 亦無 `GET_BET_DATA_URL` 欄位；對帳功能需補實作 |
| wager_type 完整含義 | `cancelWager`/`cancelEndWager` 的帳務處理邏輯尚未在 BetRecord 同步中驗證 |
| 時區確認 | getBetData `start_time`/`end_time` 文件標 UTC，但其他時間欄位（`create_time` 等）回傳時區未實測確認 |
| provider 清單維護 | DCT 定期新增 provider，現有 `config/vendor.php` 中 PROVIDER 值需定期向 DC 客服確認是否有新增 |
| FreeSpin 系列（2.12~2.14）| Driver 未實作；若需支援 FreeSpin 活動，須補實作且注意各端點 sign 拼接組成均不同 |
| SA Gaming Pok Deng 特殊規則 | 玩家 1~5 投注金額扣除 2 倍，計算 BetRecord 時須特殊處理 |
