# DB vendors 異動方式

## 文件
不再寫 migration 去新增，改為異動 `database/data/vendors.php`，可從 `SyncVendorSeeder` 取得預設模板

## 更新
```sh
php artisan db:seed --class=SyncVendorSeeder
```
此指令會執行 `SyncVendorSeeder` 同步到 DB

## 重建 Data
從 DB 產出 `database/data/vendors.php`，將直接覆蓋原本資料
```sh
php artisan generator:vendors
```

-------------

# DB agent_setting_defines 異動方式

## 文件
不再寫 migration 去新增，改為異動 `database/data/agent_setting_defines.php`，可從 `SyncAgentSettingDefinesSeeder` 取得預設模板

## 更新
```sh
php artisan db:seed --class=SyncAgentSettingDefinesSeeder
```
此指令會執行 `SyncAgentSettingDefinesSeeder` 同步到 DB

## 重建 Data
 從 DB 產出 `database/data/agent_setting_defines.php`，將直接覆蓋原本資料
```sh
php artisan generator:agent_setting_defines
```

-------------

# DB agent_menus 異動方式

## 文件
不再寫 migration 去新增，改為異動 `database/data/agent_menus.php`，可從 `SyncAgentMenuSeeder` 取得預設模板

## 更新
```sh
php artisan db:seed --class=SyncAgentMenuSeeder
```
此指令會執行 `SyncAgentMenuSeeder` 同步菜單到 DB 並賦予代理帳號 admin (agent_id=1) 權限

## 重建 Data
從 DB 產出 `database/data/agent_menus.php`，將直接覆蓋原本資料
```sh
php artisan generator:agent_menus
```
