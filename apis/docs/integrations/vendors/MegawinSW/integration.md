# MegawinSW（vendor_id=112）

> 對接心得。通用接入規範見 target-apis plugin 的 `game-integration` skill；廠商原始規格見同目錄 `api.md`。本檔聚焦本平台實作差異、陷阱與落地對應。

## 速查表

| 項目 | 值 |
|---|---|
| 錢包模式 | 單一錢包（Seamless）；玩家餘額由本平台保管，MW 回調扣加款 |
| 回調方向 | **MW → 本平台**（本平台為 server，被呼叫方） |
| 驗證 | RSA+AES-128-ECB 雙向加密；回調 body 含加密 AESKEY + 加密 data |
| 成功判定 | 解密後 `status=1`，HTTP 一律 200 |
| 時區 | UTC+8（GMT+8） |
| 回調入口 | `POST /external/api/sw/megawin/{action}`（`action` 為操作類型） |
| 幣值換算 | 回調 `amount ÷ rate`；回傳餘額 `balance × rate` |
| 身份識別 | `MW_CODE`（= `vendor_infos.info.MW_CODE`）區分商戶 |
| Domain 取得 | 需先呼叫 MW Domain API 動態取得，不可寫死 Base URL |

## 高風險陷阱

### 1. 加密方向容易搞混

雙向都做 RSA+AES，但使用的公鑰不同：

- **EC → MW（主動呼叫）**：用 MW 的 RSA 公鑰加密 AESKEY，再用 AESKEY（AES-128-ECB）加密 data
- **MW → EC（回調）**：用 EC 的 RSA 公鑰加密 AESKEY，EC 用自己的私鑰解密 AESKEY，再解密 data

`CipherService.php` 封裝了 `encrypt()` / `decrypt()` 兩個方向，直接呼叫，**切勿在外部自行組 RSA 操作**。

### 2. unsettleBet 設計開放問題

當 MW 呼叫 `unsettleBet`（結算撤銷）時，實作是否需要建立 TBR 補正條目，目前無明確規範，需與 MW 確認並由 RD 決定。

### 3. 動態 Domain——禁止寫死

MW 的 API Base URL 須透過 Domain API 動態取得，不可在 `vendor_infos.info` 寫死。若寫死，API 升級時全部失效。

### 4. 批次注單（batch wagerData[]）

`placeBet` 與 `settleBet` 的 `wagerData` 為陣列，**一次請求可能帶多筆**。實作必須逐筆處理，不可假設只有一筆。

## BetRecord 狀態機

```
BET(初始) ──→ SETTLE(結算)
           ↓
        UNSETTLE(結算撤銷) ──→ SETTLE(再結算)
```

- **AuthenticateToken**：取得玩家 token，回傳 `playerInfo`（不建立 BR）
- **PlaceBet**：建立 BR（`status=BET`），扣款 `betAmount÷rate`
- **SettleBet**：更新 BR（`status=SETTLE`），`win = payoutAmount÷rate`，計算 `result`（L/W/D/N），寫 TBR
- **UnsettleBet**：撤銷結算，將 BR 回退（目前實作對 TBR 寫法待 RD 確認）
- **GetBalance**：只查餘額，不修改 BR

`wagerData[].result` 欄位：`L`=輸、`W`=贏、`D`=和、`N`=未結算。

## 冪等性

| 場景 | 處理 |
|---|---|
| 重複 `placeBet`（相同 wagerId） | 重複 wagerId 回 duplicate 錯誤 |
| `settleBet` 找不到對應 BR | 回錯誤，MW 可重試 |
| `unsettleBet` 已是 BET 狀態 | 無需操作，回成功 |

冪等 key：`wagerData[].wagerId`（每筆注單的唯一識別）。

## 整合落地建議

| MW 概念 | APIs 專案對應 |
|---|---|
| Driver | `app/Vendors/MegawinSW/Driver.php`（`AbstractSingleWallet`） |
| 回調 Controller | `app/Http/Controllers/External/SingleWallets/MegawinController.php` |
| 回調路由 | `routes/external/sw.php`，prefix `megawin` |
| 加解密工具 | `app/Vendors/MegawinSW/CipherService.php`（`encrypt()` / `decrypt()`） |
| `vendor_infos.info` | `MW_CODE`（商戶識別）/ `EC_PRIVATE_KEY`（EC 私鑰，解密回調用）/ `MW_PUBLIC_KEY`（MW 公鑰，加密主動呼叫用） |
| `vendor_infos.rate` | 幣值換算係數 |
| Domain 設定 | 動態取得，存於 `vendor_infos.info.API_URL`（每次需確認有效） |
| 帳號 Model | `MegawinSwAccount`（`app/Vendors/Models/MegawinSwAccount.php`） |
| BetRecord Model | `MegawinSwBetRecord`（`app/Vendors/Models/MegawinSwBetRecord.php`） |
| 對帳保留期 | 文件未明載，須向 MW 確認 |

## Gaps / 待 MW 確認

- **unsettleBet TBR 寫法**：結算撤銷時是否需要補 TBR 沖銷條目，由 RD 決定
- **Domain API 有效期限**：動態 Domain 需要多久重取一次
- **注單保留期限**：getGameLog / 查單 API 可回溯多久
- **批次失敗部分成功**：wagerData[] 多筆中某筆失敗時整批回滾或部分處理
