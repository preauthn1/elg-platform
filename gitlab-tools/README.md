# gitlab-tools

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`gitlab-tools`　|　文件 **9**　|　主语言：Python 7, YAML 1

## 目录构成

```
tests/  — 3
docs/  — 1
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`

---

## 原始说明

# GitLab Tools

從 GitLab Work Items 產生兩個唯讀靜態儀表板，並透過 GitLab Pages 發布。資料在 CI runner 中取得，存取 token 不會寫入產出的 HTML。

完整背景、架構與維運方式請參閱[專案介紹](docs/PROJECT_INTRODUCTION.md)。

## 頁面

- `public/index.html`：`ticket_tasks` 成員任務看板，包含負載、期限、專案與成員篩選，以及依處理人配色並顯示姓名、頭像的月／週日曆與未排程任務。
- `public/op.html`：`op_tickets` 維運戰情室，包含 SLA、待跟進項目、處理人負載與站點健康。

兩個產生器都會使用 GitLab GraphQL cursor pagination 取得全部開啟中的 Work Items，不受單頁 100 筆上限影響。任一頁讀取失敗時，產生程序會失敗，不發布部分資料。

## 必要環境變數

| 變數 | 說明 | 預設值 |
| --- | --- | --- |
| `GITLAB_READ_TOKEN` | 具有 `read_api` 權限的 token；CI 中應設為 masked、protected | 無 |
| `CI_SERVER_URL` | GitLab HTTP(S) 根網址 | `http://example.invalid:1688` |
| `BOARD_PROJECT` | 任務看板專案完整路徑 | `target/ticket_tasks` |
| `OP_PROJECT` | 維運工單專案完整路徑 | `target/op_tickets` |

## 本地執行

專案只使用 Python 標準函式庫，建議使用 Python 3.11 以上版本。

```bash
export GITLAB_READ_TOKEN=[REDACTED]'
export CI_SERVER_URL='http://example.invalid:1688'
python3 generate_board.py
python3 generate_op.py
```

執行後會原子更新 `public/index.html` 和 `public/op.html`。日誌會列出每頁筆數及最終總數。

## 測試

```bash
python3 -m py_compile gitlab_api.py tw_holidays.py generate_board.py generate_op.py
python3 -m unittest discover -v
```

測試涵蓋單頁與多頁資料、API 錯誤、cursor 異常、重複 Work Item、重試、安全 JSON 及多人共同指派。

## 資料規則

- GitLab GraphQL 每頁讀取 100 筆，以 `pageInfo.endCursor` 繼續取得下一頁。
- 最多允許 100 頁；達到上限仍有下一頁時會失敗，而非靜默截斷。
- 共同指派的任務會計入每位處理人的負載，但全域任務總數只計一次。
- 成員以 GitLab `username` 識別，以顯示名稱呈現在頁面上。
- 所有時間以台北固定時區 UTC+8 顯示，不依賴容器的系統時區資料。
- 日曆以不同底色區分週六、週日及台灣國定假日；國定假日採用人事行政總處 2025–2027 官方辦公日曆快照。
- OP 看板的 P1 SLA 為 24 小時，P2 SLA 為 3 個工作日；12 小時未更新視為需跟進。
- 動態資料在嵌入 `<script>` 及插入 HTML 前都會安全編碼。

## CI／Pages

預設分支有新提交或排程 pipeline 啟動時，`pages` job 會：

1. 編譯檢查 Python。
2. 執行單元測試。
3. 取得兩個專案的完整資料。
4. 生成 `public/` 並作為 GitLab Pages artifact 發布。

若希望資料定時刷新，請在 GitLab 的 **Build > Pipeline schedules** 設定排程。

