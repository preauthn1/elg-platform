---
description: 解析 RD-Studio-01 / WEB_AgentBackend / API_Agent / WEB_CustomerServiceSocket 四個本機 clone 的絕對路徑，驗證 origin 後輸出「make 用的 export 行」與「skill 用的 Claude settings env 片段」。不寫任何檔（無 .env）。
disable-model-invocation: true
---

解析四個 GFFv3 repo 的本機 clone 路徑，驗證後**輸出**給使用者套用（不落任何設定檔）。

---

## 目標四個 repo（origin 皆為 `tp-wz3-rd` 的 gcloud source repo）

| 環境變數 | repo 目錄名 | origin 尾段（驗證用） | 給誰用 |
|---|---|---|---|
| `AGENT_BACKEND_REPO_DIR` | `WEB_AgentBackend` | `/r/WEB_AgentBackend` | `plugins/legend-toolkit/script/Makefile` 的 `back/` target |
| `API_AGENT_REPO_DIR` | `API_Agent` | `/r/API_Agent` | 同上的 `api/` target |
| `CS_SOCKET_REPO_DIR` | `WEB_CustomerServiceSocket` | `/r/WEB_CustomerServiceSocket` | 同上的 `cs/` target |
| `RD_STUDIO_REPO_DIR` | `RD-Studio-01` | `/r/RD-Studio-01` | deploy-rd-studio skill preflight / rollback |

## 步驟（四個 repo 各做一次）

1. **自動探測**：依序試候選路徑，取第一個 origin 尾段符合的：
   ```bash
   for d in ~/projects/www/<目錄名> ~/Workspace/<目錄名> ~/<目錄名>; do
     git -C "$d" remote get-url origin 2>/dev/null | grep -q '/r/<目錄名>$' && echo "$d" && break
   done
   ```
2. **驗證**：對解出的路徑再跑 `git -C <路徑> remote get-url origin`，尾段符合才採用（避免同名不同 repo）。
3. **找不到**：回報該 repo「本機無 clone」，附 clone 指令，該路徑略過、**不輸出錯值**：
   ```bash
   gcloud source repos clone <目錄名> --project=tp-wz3-rd
   ```

## 輸出（不寫檔，交給使用者套用）

**A. 給 `make`（back/ 與 api/ target）—— shell export 行**，使用者 `eval` 或貼上：
```bash
export AGENT_BACKEND_REPO_DIR=<解出的路徑>
export API_AGENT_REPO_DIR=<解出的路徑>
export CS_SOCKET_REPO_DIR=<解出的路徑>
```
> Makefile 不讀 `.env`，只吃環境變數與命令列參數（沒設時用 Makefile 內的 `~/projects/www/<目錄名>` 預設值）；export 一次後該 shell session 內的 `make back/… ` / `make api/…` / `make cs/…` 都生效。

**B. 給 `deploy-rd-studio` skill（Claude preflight）—— Claude settings `env` 片段**，貼進 `~/.claude/settings.json`（或 `.claude/settings.local.json`，勿進版控的 `.claude/settings.json`）：
```json
{ "env": { "RD_STUDIO_REPO_DIR": "<解出的路徑>" } }
```
> Claude settings 的 `env` 會注入每次 Bash 呼叫且跨呼叫保留，故 skill 的 `$RD_STUDIO_REPO_DIR` 才穩定可用（一般 shell `export` 不跨 Claude 的獨立 Bash 呼叫）。

## 回報

逐一列出四個 repo 的狀態（`已解出 <路徑>` / `本機無 clone（附 clone 指令）`），並附上上面 A、B 兩段可直接套用的內容。
