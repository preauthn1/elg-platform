# 常見故障 / 排除 + 維護方式

> 從 [SKILL.md](../SKILL.md) §7 / §8 拆出。

## 常見故障 / 排除

| 症狀 | 可能原因 | 處理 |
|---|---|---|
| `gcloud compute ssh` permission denied | ssh-agent 沒載 key | `ssh-add ~/.ssh/google_compute_engine` |
| 部署後 dev 沒看到新 commit | 分支沒 merge 進 `develop` | merge 後重跑 update 腳本 |
| 部署後 prod 沒看到新 commit | 沒 merge 進 `master` | merge 後重跑 |
| dev 改 develop 後 prod 看不到 | develop 修了沒回流 master | merge develop → master |
| `composer install` 失敗 | 私有套件 token 過期 / 網路 | SSH 進去看完整錯誤 |
| 部署完 artisan `Class not found` | autoload 沒更新 | `composer dump-autoload -o` |
| 部署完 config 異常 | 舊 cache 殘留 | `php artisan config:clear && php artisan cache:clear` |
| 改 .env 後沒生效 | config cache 殘留 / `.env.<env>` 沒 mv 到 `.env` | 同上；確認 mv 成功 |
| `git log /var/www/html` 失敗 | 該目錄不是 git repo | 改查 `~vwsource/RD-Studio-01/_release`（需 sudo） |
| tpower-hk / tpower-new 怕誤動 | `update_live.sh` 內兩段依序跑，無法只跑一半 | **接受兩個一起上的事實**。**不要修改 `/vwsource/update_live.sh`**：該腳本由 vwsource user 管理、無備份/版控/回滾流程，任意修改會破壞下次標準部署且難以追查。若真的需要拆分上版時序，需事先協調 vwsource owner 走變更控管。 |

## Rollback（prod 部署壞掉怎麼退）

部署是 `\cp -R *` 覆蓋式、**無備份 / 無快照 / 無版控回滾**；且 update 腳本開頭 `\rm -R RD-Studio-01` 會重新 clone，**機器上的版本永遠跟著 source repo 的 default branch（master）走**。因此退版本質是「本機讓 master 指回 good SHA、再重跑腳本」——全程在本機操作。

1. **在本機 RD-Studio clone**（`$RD_STUDIO_REPO_DIR`，origin = gcloud source repo；取得見 SKILL.md §3 📍）找上一個 good SHA：`git log origin/master`
2. **本機 `git revert <bad_sha>...` → `git push origin master`**（forward-only 反向 commit，不改寫已推歷史）
3. **重跑對應環境 update 腳本**（clone 會拉到修正後的 master）→ 走 [verification.md](verification.md) Step 1–3，但有兩處填法和一般部署不同：
   - Step 3 的 `<TARGET_SHA>` 填第 2 步 revert 產生的 commit（revert 了多個 commit 時，填最後產生的那個，也就是 push 後本機 `git rev-parse HEAD` 的值），確認 `✅ contains`。不要填 `<good_sha>`：revert 是疊在壞版之上的新 commit，HEAD 一定同時包含 good_sha 與 bad_sha，填 good_sha 永遠顯示 contains，驗不出退版有沒有上去。
   - Step 1 的 `<CHANGE_PATTERN>` 改用壞版才有的字串，**查不到（無輸出）才算退版生效**，判讀和一般部署相反。
   - 限制：update 腳本用 `\cp -R *` 覆蓋到 `<PATH>`，不會刪除檔案。壞版若新增了一個檔案，revert 後那個檔仍留在機器上，Step 1 對它 grep 一定查得到壞版字串，不能拿它判斷退版。這種情況改用壞版修改過、而且壞版之前就存在的檔案裡的字串來驗；要清掉殘留的新增檔，得登入該機器手動刪除，這是 prod 上的寫入，先經使用者確認。
4. 全程遵守 SKILL.md §6「一次一台 / 遇錯立停」

> ⚠️ **code 退版不含 DB**：若壞掉的部署跑過 migration，退 code 不會回滾資料庫，需另行處理。

## 維護方式

- **腳本內容過期**：用 [verification.md](verification.md)「常用指令模板 #1」（`sudo cat <SCRIPT_PATH>`）分別抓各站機器的 update 腳本，覆蓋 [update-scripts.md](update-scripts.md) 對應段落
- **新增環境 / 新 VM**：在 SKILL.md §1 對照表加列 + [verification.md](verification.md) SSH 參數對照表加列 + [update-scripts.md](update-scripts.md) 加腳本
- **分支策略變動**：更新 SKILL.md §2
- **Claude 代跑能力擴充**：更新 SKILL.md §6 矩陣
