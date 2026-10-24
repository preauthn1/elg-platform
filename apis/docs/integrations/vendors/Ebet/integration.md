# Ebet（vendor_id=13）
> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

---

## 速查表

| 項目 | 值 |
|---|---|
| Vendor ID | `13`（`VendorIdEnum::EBET`） |
| 錢包模式 | 轉帳錢包（Transfer Wallet，多錢包 m 版） |
| 平台性質 | 真人 / 電子遊戲（百家樂、龍虎、輪盤、骰寶、Slot、Niuniu 等） |
| 簽名算法 | RSA MD5withRSA PKCS1（私鑰簽名 → Base64） |
| 成功判定 | `status = 200`（但 recharge 的 200 僅代表「已收到」，非成功） |
| 轉帳確認機制 | 必須輪詢 `rechargestatus`；Driver 預設 3 次 × 5 秒間隔 |
| 多錢包 typeId | `0`=General / `1`=Live / `2`=No Dealer Slot（Driver 固定用 0） |
| 資料保留期 | 60 天（超過需聯絡 eBet 客服） |
| Base URL 設定 | `vendor_infos.info.API_URL` |

---

## 高風險陷阱

### 1. `recharge` 回 `status:200` ≠ 轉帳成功

這是最容易踩的坑。`recharge` 的 `200` 只代表 **eBet 已收到請求**，不代表轉帳完成。**必須用同一 `rechargeReqId` 呼叫 `rechargestatus` 確認最終結果**，才能決定交易是否完成。直接信任 recharge 的 200 會造成帳務漏記或雙記。

### 2. RSA 私鑰 `\n` 必須還原為真正換行

設定中 `PRIVATE_KEY` 以字面 `\n` 儲存（JSON 轉義），傳入 `openssl_get_privatekey()` 前必須執行 `str_replace('\\n', "\n", $privateKey)` 還原為 PEM 格式換行。未還原時 `openssl_sign` 會靜默失敗（回傳 false），導致 `signature` 欄位為空字串，eBet 回 `4026 Signature error`。

### 3. 各 API 的待簽字串組成不同（4 種模式）

| API | 待簽字串 |
|---|---|
| `syncuser`、`getusermoney` | `username` |
| `userinfo`、`recharge`、`usertransaction` | `username + timestamp` |
| `rechargestatus` | `rechargeReqId` |
| `getbetlimit` | `username + channelId + timestamp` |
| `slotgamelist` | `channelId + timestamp` |

用錯待簽字串 = 驗簽失敗 (`4026`)，且錯誤原因不會明示是哪個欄位組合有誤。

### 4. Multi-wallet typeId：存款與提款必須使用同一 typeId

對同一玩家進行存款時若用 `typeId=1`，提款也必須用 `typeId=1`；混用 typeId 會導致餘額不匹配（從無餘額的錢包提款 → `5002 Not enough cash`）。Driver 目前固定 `typeId=0`（General Wallet），若日後需支援多錢包策略須整體規劃。

### 5. rechargestatus `status=0` 的輪詢分支與文件有出入

文件定義 `status=0` = Recharging（處理中，建議 3 秒後再查），但 Driver `checkTransferStatus` 只對 `status=-1` 繼續輪詢，對 `status=0` 視為「其他狀態」直接 break → TIMEOUT。安全側不會誤判成功，但與文件語意有出入。若遇轉帳常卡 TIMEOUT 可確認是否為 eBet 頻繁回 `0`。

### 6. launchUrl / loginslot 端點不在 m 版 Chapter 4 文件中

Driver 進遊戲呼叫 `API_URL.'launchUrl'`（真人）與 `API_URL.'loginslot'`（Slot），但 m 版文件未描述這兩個 POST 端點。為非正式文件接口，遇問題需向 eBet 客服索取正式規格。

---

## 轉帳流程

```
進遊戲：
  首次：POST /api/syncuser 建立 eBet 玩家
  → POST /api/launchUrl 或 HTML5_URL 或 /api/loginslot 取得遊戲網址

存款 transferToVendor：
  recharge(money = +amount, typeId=0, rechargeReqId=transactionNo)
  → 不論回應為何，一律輪詢 rechargestatus 確認最終結果

提款 transferToLocal：
  recharge(money = -amount, typeId=0, rechargeReqId=transactionNo)
  → 同樣輪詢 rechargestatus 確認

對帳：
  userbethistory / usertransaction（60 天內資料）
```

### rechargestatus 三態映射

| rechargestatus `status` | 語意 | Driver 映射 |
|---|---|---|
| `200` | Successful | `SUCCESS` |
| `5001` | Recharge failed | `FAILED` |
| `-1` | Recharge record no exist（尚未入帳 / 不存在）| 繼續輪詢，耗盡 → `TIMEOUT` |
| `0` | Recharging（處理中） | Driver 現行：`break` → `TIMEOUT`（與文件有出入，見陷阱 §5） |
| 其他 | 各類錯誤 | `break` → `TIMEOUT` |

### Driver 輪詢設定

| 設定 | info 欄位 | 預設值 | 作用 |
|---|---|---|---|
| 輪詢次數 | `API_LOOP_TIME` | `3` | 最多呼叫 rechargestatus 幾次 |
| 輪詢間隔 | `SLEEP_SECOND` | `5` 秒 | 每次呼叫之間等待秒數 |
| 最長等待 | — | `15 秒`（3×5） | 單筆轉帳確認最大耗時 |

---

## 冪等性

| 機制 | 說明 |
|---|---|
| `rechargeReqId` 唯一鍵 | 本專案以 `transactionNo` 填入；重送同一 ID 時 eBet 回 `201 Repeated rechargeId` |
| recharge 本身不冪等 | `201` 代表已存在，需用 `rechargestatus` 確認最終狀態，不可視為成功 |
| rechargestatus 查詢 | 以 `rechargeReqId` 查交易最終狀態，作為唯一成敗依據 |

---

## 整合落地建議

### vendor_infos.info 欄位對應

| info 欄位 | 用途 | 對應文件概念 |
|---|---|---|
| `API_URL` | eBet API base（結尾帶 `/`，串接 action） | `api_domain:8888/api/` |
| `CHANNEL_ID` | 渠道 ID | `channelId` |
| `SUB_CHANNEL_ID` | 子渠道 ID（無則 `0`） | `subChannelId` |
| `CURRENCY` | 幣別代碼 | 如 `IDR` |
| `PRIVATE_KEY` | RSA 私鑰（含字面 `\n`，使用前須還原換行） | §5.8 RSA 私鑰 |
| `PUBLIC_KEY` | RSA 公鑰（本地簽名不需用到，eBet 驗章端使用） | §5.8 RSA 公鑰 |
| `HTML5_URL` | H5 遊戲網址（`USE_API_URL=0` 時直接拼參數） | §3.1.5 H5 login url |
| `USE_API_URL` | `1`=呼叫 launchUrl；`0`=直接用 HTML5_URL | Driver 邏輯 |
| `SLEEP_SECOND` | rechargestatus 輪詢間隔秒 | §4.3 |
| `API_LOOP_TIME` | rechargestatus 輪詢次數 | §4.3 |
| `ANDROID_DOWNLOAD_LINK` / `IOS_DOWNLOAD_LINK` | App 下載連結 | 整合表 |

### 程式落點

| 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/Ebet/Driver.php`（`extends AbstractTransferWallet`） |
| 玩家帳號 Model | `app/Vendors/Models/EbetAccount.php` |
| 注單 Model | `app/Vendors/Models/EbetBetRecord.php`（`utc_create_time`=投注、`utc_payout_time`=派彩） |
| 簽名實作 | `Driver::getSign()`：`openssl_sign($string, $ret, $privateKey, OPENSSL_ALGO_MD5)` → base64 |
| 轉帳實作 | `transferToVendor`/`transferToLocal` → `transfer()`（recharge → rechargestatus 輪詢） |
| 遊戲清單同步 | `Driver::getGameList()` 打 `slotgamelist`，標 `ShouldSyncGameList` 進 `update_game_list` 每小時排程；亦可 Olympus 系統操作手動刷（見「遊戲清單同步」） |
| Callback controller | **無**（轉帳錢包不需單一錢包回調） |
| 對帳保留期 | eBet 後台 60 天；超過需聯絡客服 |

### 遊戲清單同步

`Driver::getGameList()` 遷自 RD-Studio `get_ebet_games`，打 `slotgamelist`（待簽字串 `channelId + timestamp`）。三方只回老虎機清單，真人遊戲不在此 API，沿用舊指令不處理。Driver 標 `ShouldSyncGameList`，由 `update_game_list` 每小時排程派發；也可在 Olympus 系統操作頁手動刷。

| 三方欄位 | 落地 | 說明 |
|---|---|---|
| `gameID` | `games.game_type` | 唯一鍵；缺值的列略過 |
| `english` | `games.name`、`game_names`（EN） | |
| `chinese` | `game_names`（ZH_CN） | 空值不寫 |
| `providerId` | `games.remark` | **必填語意**：`getGameUrl()` 以 `remark` 判斷是否走 loginslot 並帶給三方，寫錯遊戲會開不起來 |
| — | `games.type_id` | 固定 SLOTS(2) |
| — | `games.image` | 三方不給圖，空字串，由後台人工補 |
| — | `games.status` | 新增列預設 `0`（沿用舊指令，人工審核後上架） |

`GameListSyncService` 為純新增：既有遊戲欄位一律不動，「DB 有、API 無」只偵測「三方未提供」記 warning 提示人工關閉，不會自動停用。三方 HTTP 200 但 `status` 非 `200` 時回空清單並記 error log；HTTP 層錯誤拋 `ApiException` 讓排程失敗可見。

### 語系映射

Driver `mapLanguageCode()`：ZH_TW→`zh_tw`、ZH_CN→`zh_cn`、MS_MY→`ms_my`、ID_ID→`in_id`、其他→`en_us`

---

## Gaps

| 項目 | 說明 |
|---|---|
| launchUrl / loginslot 端點規格 | 未在 m 版 Chapter 4 中描述；Driver 已使用但無正式文件依據，建議向 eBet 客服索取 |
| Slot providerId 清單 | Driver 硬寫 `['genesis', 'radi8', 'bunfox']`；完整清單需向客服取得，建議改為 config |
| rechargestatus `status=0` 輪詢分支 | Driver 現行 `break` → TIMEOUT；與文件「繼續查」語意不符，可視轉帳 TIMEOUT 頻率決定是否修補 |
| recharge 金額正規化 | Driver `getBalance` 直接以 `(string) $response['money']`，未走 `Common::numAdd('0', ..., 4)` 正規化；若 eBet 回傳帶精度誤差可能影響比較 |
| 資料保留 60 天確認 | 文件標 §5.9，實際超過 60 天的查詢行為未實測 |
| typeId 多錢包策略 | Driver 固定 `typeId=0`；若白標需要按遊戲類型分錢包（Live/Slot 分開），須整體規劃變更 |
