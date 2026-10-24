---
description: 從 origin/master 建立 APIs worktree（含分支）：vendor 用實體副本（非 symlink），node_modules / .env / .env.testing 用相對 symlink，並重建 autoload。
argument-hint: "<topic>（slug，不含前綴；前綴預設 feature/，可在對話中指定）"
---

主題：$ARGUMENTS

---

> 依 `release-workflow` worktree 模式 SOP 建立 APIs worktree。
> `$ARGUMENTS` 為 topic slug（例 `payment-refactor`），目錄為主 checkout 同層的 `APIs-$ARGUMENTS`，分支為 `feature/$ARGUMENTS`。

## 前置

- `$ARGUMENTS` 為空 → 停下來問 topic slug 與前綴（feature / fix / refactor / docs / skill）。
- 若使用者指定前綴（如 `fix/xxx`），以指定值取代 `feature/`；分支名取 `/` 後的部分作目錄名。
- 主 checkout 的路徑依開發者本機而異，不要寫死：在 APIs 任一工作區內跑 `git worktree list --porcelain | awk '/^worktree /{print $2; exit}'` 取得（第一列固定是主 checkout）。以下命令的 `<主 checkout>` 代入這個值，`<worktree>` 代入它同層的 `APIs-$ARGUMENTS`。

> 🔴 **worktree 的 git（add / commit / status / switch / push）一律在 host 跑**（`git -C <worktree> ...`），只有 `composer` / `artisan` / 測試走容器。
> worktree 的 `.git` 指標寫的是 host 路徑（例如 `gitdir: /Users/<你>/Workspace/APIs/.git/worktrees/...`），容器內該路徑不存在，任何 `docker exec ... git` 會 `fatal: not a git repository`。這是 worktree 對「本地 git 進容器」通則的**例外**。

## 執行序

### Step 1（Host）：取最新 master、建 worktree

> `worktree add` 必須在 host 執行、不進容器（見 `release-workflow`「2. 迭代」的「git 走容器的 repo，worktree 命令一律 host 執行」）；否則 gitdir 寫成容器路徑，host 進不去。

```bash
cd <主 checkout> && git fetch origin master
git worktree add ../APIs-$ARGUMENTS -b feature/$ARGUMENTS --no-track origin/master
```

### Step 2（Host）：vendor 實體副本（不可 symlink）

> ⚠️ **vendor 必須是 worktree 內的實體目錄，不能 symlink。** Composer autoload 的 `$baseDir` 是執行期由 `vendor/composer/` 的 **realpath** 往上推算：vendor 是 symlink 時 PHP 解析 symlink → `$baseDir` 變成主 checkout 根 `/var/www/APIs`，於是 `App\` 映到 master 版的 `/var/www/APIs/app`，worktree 自己 `app/` 新增 / 修改的 class 全被無視（`Class not found` 或跑到 master 舊碼）。實體副本讓 `$baseDir` 執行期相對 real vendor 位置 → 正確指向 worktree `app/`。
> 用 `cp -a`（本機複製、免下載）比 `composer install` 快；**禁用 hardlink（`cp -al`）**：後續 `composer dump-autoload` 會 in-place 改寫 `vendor/composer/autoload_*.php`，hardlink 共用 inode 會回污主 checkout。

```bash
# host：實體複製 vendor（原生檔案系統，比在容器掛載點複製快）
cp -a <主 checkout>/vendor <worktree>/vendor
```

### Step 3（Container）：補 node_modules / .env / .env.testing symlink（須先於 Step 4）

> 於容器內建立 symlink（VirtioFS 限制：host 端建的 symlink 容器無法解析，`stat: Invalid argument`）；target 用**相對路徑** `../APIs/<name>`，使 host 與容器兩側皆可解析（worktree 與主 checkout 在兩邊都是 sibling）。
> ⚠️ 勿用容器絕對路徑 `/var/www/APIs/...`：容器能解、但 host 端會懸空（PhpStorm 開 `.env` 會看到空檔）。
> 三者皆無 `$baseDir` 解析問題，維持 symlink 即可，只有 vendor 非改實體副本不可。

> ⚠️ **`.env.testing` 不可省**：它與 `.env` 同被 `.gitignore` 排除（`.gitignore:18`），新 worktree 必定沒有；而 `tests/TestCase.php::setUp()` 對它做 `file_exists` 檢查，缺檔即 `exit("Error: .env.testing file is missing. Please create one before running tests.")`。這會**中止整個測試進程**，不是單一測試失敗。該 guard 只在測試進程執行，故 **Step 5 (a)(b) 全綠也驗不出缺檔**（實測：artisan boot 與 `$baseDir` 都通過，跑測試才爆），必須靠 (c) 把關。

```bash
docker exec php-fpm8 bash -c "
  cd /var/www/APIs-$ARGUMENTS &&
  ln -s ../APIs/node_modules node_modules &&
  ln -s ../APIs/.env .env &&
  ln -s ../APIs/.env.testing .env.testing
"
```

### Step 4（Container）：重建 autoload

> ⚠️ **必須排在 Step 3 之後**：`composer dump-autoload` 會觸發 Laravel 的 `post-autoload-dump` script（`artisan package:discover`），那會 boot 框架、需要 `.env` 已就位。symlink 還沒建就跑，會在 `package:discover` 階段失敗。
> 非 authoritative，目的是確保 worktree 新增 class 走 PSR-4 動態解析。

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs-$ARGUMENTS && composer dump-autoload"
```

### Step 5：驗證

框架起得來 + autoload 的 `$baseDir` 確實落在 worktree（非主 checkout）：

```bash
# (a) 框架 boot
docker exec php-fpm8 bash -c "cd /var/www/APIs-$ARGUMENTS && php artisan --version"

# (b) $baseDir 指向 worktree：輸出須落在 /var/www/APIs-$ARGUMENTS/app/...，非 /var/www/APIs/app/...
docker exec php-fpm8 bash -c "cd /var/www/APIs-$ARGUMENTS && php -r 'require \"vendor/autoload.php\"; \$r=new ReflectionClass(App\\Helpers\\Common::class); echo realpath(\$r->getFileName());'"

# (c) 三個 symlink 在容器內都解析得到（test -f/-d 會 follow symlink，等價 TestCase 的 file_exists 判定）
docker exec php-fpm8 bash -c "cd /var/www/APIs-$ARGUMENTS && test -f .env && test -f .env.testing && test -d node_modules && echo 'symlink OK'"
```

- (a) 看到 `Laravel Framework 13.x.x`。
- (b) 路徑須以 `/var/www/APIs-$ARGUMENTS/app/` 開頭；若印出 `/var/www/APIs/app/...` 代表 vendor 設定錯（回頭檢查 Step 2 是否誤用 symlink）。
  - ⚠️ 這行有兩層引號，反斜線的數量會影響結果。外層是 host 的雙引號，會把 `\\` 縮成一個 `\`；內層是容器 bash 的單引號，會原樣傳給 PHP。所以命名空間在這行要寫成 `App\\Helpers\\Common`，PHP 才會收到 `App\Helpers\Common`。寫成四個反斜線時，PHP 會收到兩個反斜線，結果是 `Parse error: syntax error, unexpected token "\"`。要改寫這行時，改用暫存檔寫法避開引號問題：`docker exec php-fpm8 bash -c 'cat > /tmp/chk.php <<"EOF" ... EOF` 再 `cd /var/www/APIs-$ARGUMENTS && php /tmp/chk.php'`。
- (c) 印出 `symlink OK`。沒印出代表某條 symlink 建錯或 target 懸空。**只看 `ls -l` 有那條連結不算過**，host 端建的 symlink 在容器內解析不了（見 Step 3 的 VirtioFS 限制），必須在容器內實測解得開。

## 完成回報

回報：worktree host 路徑、分支名稱、Step 5 (a) 版本、(b) 解析路徑與 (c) symlink 檢查結果。

> 若 `git worktree list`（host）出現多個 `prunable`（metadata 過期），完成後提醒收尾：`git worktree remove <path>` 或 `git worktree prune`（皆 host 執行）。

## 不需要做的事

| 動作 | 原因 |
|---|---|
| `composer install`（完整重裝） | Step 2 已用 `cp -a` 複製既有 vendor、Step 4 再 `dump-autoload` 重建 autoload，免下載 |
| `npm install` | node_modules 已 symlink |
| 複製 `.env` / `.env.testing` | symlink 即可，同機同環境設定相同 |
| 補 `.claude/settings.local.json` | 該檔僅存在於主 checkout，worktree 內找不到屬正常、設定仍生效（見 APIs `CLAUDE.md`） |
