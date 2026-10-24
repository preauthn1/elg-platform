# Gitlab CI 機制 & branch 限制

這裡僅記錄 Gitlab CI 跟相關 branch 限制，開發流程在別的地方

## 限制
1. `master` & `develop` branch 不能直接 push
2. `master` 僅允許 `develop` 的 Merge Request
3. `develop` 僅允許其他 branch 的 Merge Request

## CI
### 簡介
* `.gitlab-ci.yml` : 啟動檔，

### 流程
整個 CI/CD 分成 `prepare` -> `pre-build` -> `build` -> `deploy` 等階段，CI 主要聚焦在前兩項

#### `prepare`
預建立資料並 cache 起來，主要建立：
    1. vendors/ ： 會執行 `composer install`，**cache key 為 `composer.lock` file content**
    2. init.sql：避免每次 CI 都要完全執行 migration，會執行 `mysqldump` 並 cache 起來，**cache key 為 branch name**

預設所有 pipeline 都會執行 job `prepare_dependencies`，此 job 受 cache 影響會跳過部分行為

`prepare_dependencies` 會根據上一次此 branch 執行 CI 時的 git commit hash 和本次的做比較，檢查 `database/migration` 路徑下是否有更動，照以下優先順序執行：
    1. **develop & master branch** : 僅跑 migrate，並更新 init.sql
    2. 有**任何更新** : Refresh Migration, 刷新 init.sql
    3. 有**任何新增** : 僅跑 migrate，並更新 init.sql
    4. 無事發生

#### `pre-build`
執行測試和語法檢查工具
1. pint： `vendor/bin/pint --test`
2. psr4: `composer dump-autoload` 後出現 `does not comply with psr-4 autoloading standard` 等字代表失敗
3. test: `php artisan test`

分成 `push` 和 `merge_request` 兩種
##### `push`
任何 branch 在收到 push 後都會執行
* pint
* check_psr4_autoloading_standard
* phpunit

##### `merge_request`
發起 merge request 時會改啟動這個，**在 CI 中做 local merge，模擬 merge 後狀態進行測試**
* merge_result_pint
* merge_result_check_psr4_autoloading_standard
* merge_result_test

