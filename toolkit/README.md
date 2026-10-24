# toolkit

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`toolkit`　|　文件 **17**　|　主语言：Shell 8, YAML 2

## 目录构成

```
php73/svcs/  — 6
php73/  — 3
php83/  — 3
php73/scripts/  — 2
php83/scripts/  — 2
php83/svcs/  — 1
```

## 关键文件

- CI/构建：`php73/svcs/phpfpm/Dockerfile`、`php73/svcs/code-server/Dockerfile`、`php83/svcs/Dockerfile`

---

## 原始说明

# toolkit
### Toolkit 介紹
本地開發工具環境, 包含Nginx1.23 + PHP7.3 + MySQL5.7 以及 Code Server
Code Server 是 VS Code for Web, 主要可以拿這個環境來執行 composer 命令及一些PHP 命令
### 環境需求
Docker 及 Docker-compose
### 目錄結構
/data   本地資料儲存區  
/svcs   服務設定檔案區  
/docker-compose.yaml Toolkit 打包  
/data/html  放置專案目錄  
/scripts/addvhost.sh    添加新專案，添加 vhost 及 /etc/hosts 設定  
/scripts/delvhost.sh    缷除專案，刪除 vhost 及 /etc/hosts 設定  
### 快速開始
1. 於 ./data/html 目錄內新增一個目錄 test.
2. 於 ./data/html/test 新增一個檔案 phpinfo.php 內容為: <?php phpinfo(); ?>
3. 執行腳本 ./scripts/addvhost.sh, project dir 輸入 test, domain 輸入 test.myapp

分為兩個版本

