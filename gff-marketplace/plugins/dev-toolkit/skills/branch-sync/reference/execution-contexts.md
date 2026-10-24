# 執行位置：host vs 容器

**只有 `APIs` / `API_*` 這類「本地 git 操作須在容器內」的 repo 需要讀本檔。**
其餘 repo（`WEB_*`、`Hyperf/*`、marketplace、任何外部專案）全部命令都在 host 執行，主檔第 1 步已說明，不必再看這裡。

## 為什麼要拆成兩處

| 命令 | 位置 | 原因 |
|---|---|---|
| `git fetch origin`（網路） | **host** | 容器內沒有 git credential / SSH key，在容器裡 fetch 會失敗或卡在認證 |
| `git fetch .` / `git merge --ff-only`（純本地） | **容器** | GFFv3 規範：這些 repo 的本地 git 操作在對應容器內執行 |

兩者操作的是**同一個 `.git` 目錄**（容器把 repo 掛載進去），所以 host `fetch` 拿到的遠端追蹤 ref，容器內立刻看得到，順序上先 host 後容器即可。

## 對照表

| host 路徑（以 `git rev-parse --show-toplevel` 的 basename 判定） | 容器 | 容器內路徑 |
|---|---|---|
| `APIs` | `php-fpm8` | `/var/www/APIs` |
| `API_Agent` | `php-fpm` | `/var/www/API_Agent` |
| `API_External` | `php-fpm` | `/var/www/API_External` |
| `API_Frontend` | `php-fpm` | `/var/www/API_Frontend` |
| `API_CustomerServiceBackend` | `php-fpm` | `/var/www/API_CustomerServiceBackend` |
| 其他任何 repo | —— | 不進容器，全 host |

> 容器名與掛載路徑以實機為準。判定容器內路徑時**不要憑本表硬寫**，用下面的存在性檢查確認後再用；表格只是預設值。

> **sibling worktree 不在表內**：`APIs-<topic>` 這類目錄的 basename 不是 `APIs`，且它們**在容器內無法作為 git repo 操作**——`WD` 要指向其所屬的主 checkout，見下節。

## 命令模板

以 `CT` 代表容器名、`WD` 代表容器內路徑：

```bash
# 主檔第 3 步的盤點（[本地] 標記者）
docker exec "${CT}" bash -c "cd ${WD} && git for-each-ref --format='%(refname:short)|%(objectname:short)|%(upstream:short)|%(upstream:track)' refs/heads"

# 主檔第 5 步 A：非當前分支快轉
docker exec "${CT}" bash -c "cd ${WD} && git fetch . 'refs/remotes/${up}:refs/heads/${b}'"

# 主檔第 5 步 B：當前分支快轉
docker exec "${CT}" bash -c "cd ${WD} && git merge --ff-only ${up}"
```

`git worktree list --porcelain` **一律在 host 跑**：worktree metadata 存的是絕對路徑，host（`~/Workspace/...`）與容器掛載點（`/var/www/...`）不同，在容器內讀會得到誤導性結果。

## 執行前檢查（容器 repo 必跑）

```bash
docker ps --format '{{.Names}}' | grep -qx "${CT}" && echo container-up || echo container-down
docker exec "${CT}" test -e "${WD}/.git" && echo repo-mounted || echo repo-missing
```

`test -e` 而非 `-d`：worktree 的 `.git` 是**檔案**不是目錄（見下節），用 `-d` 會誤判成 `repo-missing`。

- `container-down` → **停下告知使用者容器未啟動**，不要自行 `docker start`，也不要為了跑得下去就降級成 host 執行（那會繞過專案規範）。
- `repo-missing` → 該 repo 未掛進這個容器，停下回報實際情況，請使用者確認容器與掛載點。

歷史提醒：舊的 `php73-fpm` 容器已不存在，遇到指向它的舊文件不要照抄。

## worktree：容器內跑不了，`WD` 指向主 checkout

APIs 有多個 sibling worktree 常駐（`APIs-<topic>`）。**在 worktree 目錄內執行本 skill 時，`WD` 不能指向該 worktree**：

```bash
$ cat ~/Workspace/APIs-multi-currency-wallet/.git
gitdir: /Users/<你>/Workspace/APIs/.git/worktrees/APIs-multi-currency-wallet

$ docker exec php-fpm8 bash -c "cd /var/www/APIs-multi-currency-wallet && git rev-parse --show-toplevel"
fatal: not a git repository: /Users/<你>/Workspace/APIs/.git/worktrees/APIs-multi-currency-wallet
```

worktree 的 `.git` 是**檔案**，內容是 host 的絕對路徑；容器沒掛載 host 的使用者家目錄（例如 `/Users/<你>/`），因此解析不到。掛載點存在（`test -e` 過得了）也一樣跑不動。

**做法**：`refs/heads` 由整個 repo 共用，所以把 `WD` 指向**主 checkout**（`/var/www/APIs`）——盤點與快轉操作的是同一批分支 ref，結果與在 worktree 內執行完全一致。主檔第 3、5 步的命令都照舊，只有 `WD` 換人。

⚠️ 隨之而來的兩點：

- **「當前分支」不可用容器內的 `git symbolic-ref` 判定**。那讀到的是主 checkout 的 HEAD（通常是 `master`），不是使用者人在的那個 worktree。
- **一律以 host 的 `git worktree list --porcelain` 為準**：凡被任一 worktree checkout 的分支（含主 checkout 的），都走主檔分流表的「被 worktree 佔用 → 跳過」那條，不要用第 5 步 A 去更新它的 ref——那會讓該工作區的 HEAD 與工作目錄脫節。實務上 APIs 的長期 worktree 有數支，這條會濾掉大部分分支，屬預期。

## 界線

本檔只回答「命令在哪裡執行」。分支怎麼分流、哪些跳過、怎麼回報，全在主檔 `SKILL.md`，此處不重述。
