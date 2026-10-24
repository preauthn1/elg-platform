# CS Workspace — 客服工作環境一鍵安裝包

給客服快速搭好一套 AI 工作環境。

主介面是 Claude Desktop app 的 **Code 分頁**。客服平常就是對它講話，工具全部來自 GFF-Marketplace 的 plugin 白名單。所有憑證都是個人的，安裝包裡不含任何金鑰。

| Plugin | 用途 |
|---|---|
| `memory-mcp` | 魔鏡知識庫，查 SOP 與共享記憶 |
| `gcp-mcp` | 查 GCP log，憑證走每人自己的 Google 帳號 |
| `gitlab-mcp` | 查詢與建立 GitLab issue |
| `op-toolkit` | 開線上問題單給 RD，用 SURE 自評工單品質 |

---

## 客服安裝三步驟

![安裝流程](docs/img/03-install.png)

**1. 安裝（需要管理員權限）。**
到本專案 GitLab 的 **Releases 頁**下載最新版的 Source code (zip)，放到桌面解壓。解壓後的資料夾就是工作目錄，請保留。進資料夾對 `Install.bat` 點右鍵「以系統管理員身分執行」，跑完後重新開機。

**2. 首次設定（用你自己的 Windows 帳號）。**
回到同一個資料夾雙擊 `FirstLogin.bat`。依提示輸入 GitLab 帳號、Personal Access Token、魔鏡 API key。接著瀏覽器會跳出 Google 登入，用公司帳號授權一次。最後腳本會自動裝好 Claude Desktop、佈署白名單 plugin、在桌面建立捷徑。

**3. 開始上工。**
開桌面的 Claude 捷徑，登入公司 Anthropic 帳號，切到「Code」分頁。試著問它：「搜知識庫有沒有這個問題的 SOP」「查過去 30 分鐘的 ERROR log」「開一張 issue 附上這段 log」。

之後工具有新版時 IT 會通知你。雙擊同一個資料夾裡的 `upgrade.bat` 就會一鍵更新，跑完重開 app 生效。資料夾不小心刪了也沒關係，重新下載最新 Release 解壓即可，憑證都留在系統裡，不用重跑首次設定。

---

## 可以怎麼跟它講話

開 Claude 捷徑、切到「Code」分頁後，直接用中文描述你要做的事就好，工具會自動選。下面按 plugin 分類，每項都是可以直接照抄的講法。

### 查知識庫（memory-mcp / 魔鏡）

- 「搜知識庫有沒有這個問題的 SOP：客戶反映無法登入。」
- 「魔鏡有沒有處理『提款卡住』的標準流程？」
- 「查一下這個錯誤代碼在知識庫裡怎麼處理。」

### 查 GCP log（gcp-mcp）

- 「查 tp-wz3-online 過去 30 分鐘的 ERROR log。」
- 「這個會員 ID 最近有沒有相關的錯誤紀錄？幫我看今天的 log。」
- 「把剛剛那段 log 裡的錯誤訊息整理成重點給我。」

### 查詢與開 GitLab issue（gitlab-mcp）

- 「幫我查有沒有已經開過『XX 頁面載入很慢』的 issue。」
- 「開一張 issue，標題是『客戶回報提款頁面出錯』，把上面那段 log 附進去。」
- 「這張 issue 目前狀態是什麼？有人在處理嗎？」

### 開線上問題單給 RD（op-toolkit）

- 「幫我開一張線上問題單：客戶下注後餘額沒更新，附上這段 log 和會員 ID。」
- 「用 SURE 幫我評一下這張單 RD 看不看得懂、還缺什麼。」
- 「這張單描述太簡略，幫我補齊重現步驟再送出。」

> 講法不用背，把「要查什麼／要開什麼單／附哪段資料」講清楚即可，它會問你缺的細節。第一次用到某個工具時，Claude 可能會請你確認授權，按同意即可。

---

> **IT／維運人員**請看 [docs/維運指南.md](docs/維運指南.md)：系統架構、佈署前必填參數、帳號與權限、更新與釋出流程、安全注意事項與關鍵設計決策。
