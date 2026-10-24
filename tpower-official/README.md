# tpower-official

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`tpower-official`　|　文件 **90**　|　主语言：JavaScript 61, CSS 13, JSON 4

## 目录构成

```
admin/js/  — 21
assets/yes2win/  — 19
server/lib/  — 11
assets/tpower/  — 8
deploy/  — 6
server/routes/  — 6
admin/  — 4
server/sites/  — 3
scripts/  — 2
seed/tpower/  — 1
seed/yes2win/  — 1
server/  — 1
```

## 关键文件

- CI/构建：`Dockerfile`、`.gitlab-ci.yml`
- 前端入口：`admin/app.js`、`admin/index.html`

---

## 原始说明

# official-sites

官方形象站，一套程式、多個站點（目前 TPOWER、YES2WIN）。`.env` 的 `SITE` 決定這個行程跑哪一站，
沒設就是 `tpower`。

Node 即時渲染公開頁 + 內容管理後台。三語系（`en` 主語系 / `zh-CN` / `ms`），每站三語共 63 頁，
文章內頁另計。後台改完內容，**存檔就是上線**，沒有發佈步驟。

## 開發

```bash
npm install
SITE=tpower npm run seed:dev    # 產生 content/content.json 與開發用管理員
npm run dev                     # http://localhost:3000　後台在 /admin
```

`SITE` 寫在 `.env` 也可以（欄位見 `.env.example`）。開發用帳號依站點：TPOWER `admin` / `admin123`、
YES2WIN `admin` / `123456`（見 `server/sites/<站>.js` 的 `devAdmin`）。

`npm run seed` 不覆蓋既有帳號與既有內容（`--force` 才覆蓋）。

**正式環境用 `npm run seed`（不帶 `:dev`）**，它不會建任何帳號；
帳號一律用 `npm run create-admin` 自己建。`seed:dev` 建的是寫死的弱密碼帳號，只給本機用。

### 切站

`content/` 與 `uploads/` 是「目前這一站」的活資料。本機要換站：改 `.env` 的 `SITE`，
並用 `CONTENT_DIR`、`UPLOADS_DIR` 指到該站自己的資料夾（例如 `content-yes2win/`），
第一次再跑 `npm run seed:dev`。兩站要同時開，就各用一個 `.env`／一份程式目錄、不同的 `PORT`。

## 目錄

| 路徑 | 是什麼 |
|---|---|
| `server/` | Express：公開頁路由、後台 API、渲染（兩站共用） |
| `server/sites/<站>.js` | **站與站的差異只寫在這裡**：介面字串覆寫、裝飾圖示／插圖對照、行為開關、後台要隱藏的欄位、後台字母 |
| `server/schema/content.js` | 13 個資料實體的 zod 定義（兩站欄位的聯集），資料規則的唯一出處 |
| `server/lib/render.js` | 每一頁的 context 組裝與渲染 |
| `server/lib/store.js` | 逐類別讀寫、快照、稽核 |
| `templates/<站>/` | Nunjucks 樣板，各站一套 |
| `assets/<站>/` | 前台 CSS / JS / 字型 / 裝飾圖，各站一套；對外一律是 `/assets/…` |
| `admin/` | 後台介面（共用）。依站點隱藏欄位，設定由 `/admin/js/site.js` 從 `server/sites/<站>.js` 產生 |
| `seed/<站>/content.json` | 該站內容的版控來源，`npm run seed` 會灌進 `content/` |
| `seed/<站>/images/` | 預設內容引用的圖，`npm run seed` 會複製到 `uploads/`（已有同名檔就不覆蓋） |
| `content/content.json` | 網頁內容 —— 後台改過的活資料。**不進版控** |
| `content/system.json` | 帳號 + 操作紀錄 + 取數設定。**不進版控**、**等同機密** |
| `content/snapshots/` | 每次存檔前自動留一份，保留 200 筆 |
| `uploads/` | 後台上傳的素材。**不進版控** |
| `deploy/` | nginx / systemd 設定 |

## 新增一站

1. `server/sites/<站>.js`：照現有兩站的格式寫（`features` 開關、`uiText`、`decor`、`advantageRequired`、`admin.hidden`…）。
2. `templates/<站>/`、`assets/<站>/`、`seed/<站>/content.json` 與 `seed/<站>/images/`。
3. 樣板用到新的 context 欄位，就在 `render.js` 補上（從 `site.decor`／`site.features` 取值，不要比對站名）；
   資料要新欄位就加進 schema（聯集），其他站不用的欄位列進它們的 `admin.hidden`。

## 要知道的幾件事

- **改預設文案改 `seed/<站>/content.json`，不要改 `content/content.json`** —— 後者不進版控，
  那是後台改過的活資料；前者才是版控來源。改完 `node scripts/seed.js --force` 灌回去。
- **`content/` 與 `uploads/` 不進版控**，換機器用 `npm run seed` 從 `seed/<站>/` 重建。
- **`admin` 角色限 `.env` 的 `ADMIN_IPS` 白名單**（見 `.env.example`）。沒有內建預設值 ——
  沒設就是沒人能當 `admin`，設錯也一樣，而改名單本身需要 `admin`：救援只能改 `.env` + 重啟。
  開發模式額外放行 localhost。
- **改壞了在後台「內容快照」那一頁還原**（僅 `admin`）—— 每次存檔前自動留一份，上限 200 份。
  還原本身也會先留一份，所以按錯了還救得回來。

## 首次部署（全新機器）

以下以 TPOWER 為例，所有路徑以 VM 的 `/data/official-sites` 為基準（程式碼在 `./tpower.game`）。
已經部署過的機器不用做，之後每次在 GitLab 手動跑 `deploy_production` 就好。

1. **環境檔**：建立 `/data/official-sites/env/tpower.env`，權限 `600`：
   ```
   SESSION_SECRET=[REDACTED] -e "console.log(require('crypto').randomBytes(32).toString('hex'))">
   ADMIN_IPS=<可以登入 admin 的 IP，逗號分隔，可用 CIDR>
   ```
   `SITE` 不寫就是 tpower。
2. **Compose**：把 `deploy/compose.tpower.yml` 的 service 合併進 `docker-compose.yaml`。
3. **Nginx**：照 `deploy/nginx-proxy.example.conf` 檔頭的步驟掛進既有的 nginx container。
4. **部署與備份 helper**：照下面「正式環境備份」安裝 `deploy-tpower`、`backup-tpower`，
   並讓部署帳號可以 `sudo -n` 執行這兩支。
5. **GitLab CI 變數**：`DEPLOY_HOST`、`DEPLOY_USER`、`DEPLOY_SSH_KEY`（File）、`DEPLOY_KNOWN_HOSTS`（File）。
6. **第一次部署**：在 GitLab 手動跑 `deploy_production`。部署會自動跑 `npm run seed` 灌入預設內容。
7. **建管理員帳號**（seed 不會建帳號）：
   ```bash
   cd /data/official-sites
   sudo docker compose exec tpower-official node scripts/create-admin.js --user <帳號> --pass '<密碼>' --role admin
   ```
8. **檢查網址設定**：預設內容的 `settings.baseUrl` 是 `seed/tpower/content.json` 裡的值，
   canonical、sitemap 都用它；正式網域不同時，改 `data/tpower/content/content.json` 的 `baseUrl` 後重啟 container。

## 正式環境備份

每次部署會在 `seed` 與 container replacement 前執行 `/usr/local/sbin/backup-tpower`。
備份失敗時部署會直接停止。備份包含正式內容、帳號與系統資料、uploads、環境檔、
Compose 與 Nginx 設定，以及目前版本資訊；不包含 Git 可重建的程式碼與 Docker image。
為取得一致資料，建立未壓縮 archive 時會短暫 pause 應用程式；服務恢復後才進行 gzip。

先把兩個 root-owned helper 安裝到 VM：

```bash
sudo install -o root -g root -m 0755 \
  deploy/backup-tpower-host.sh /usr/local/sbin/backup-tpower
sudo install -o root -g root -m 0755 \
  deploy/deploy-tpower-host.sh /usr/local/sbin/deploy-tpower
```

預設備份位置是 `/data/backups/tpower`，保留規則如下：

- 當天部署備份保留最後 7 份。
- 每天最後一份保留 7 天。
- 每週最後一份保留 31 天。
- 每月最後一份保留 93 天。

各層使用 hard link，不會為同一份 archive 重複占用空間。預設依
`Asia/Kuala_Lumpur` 分日；可在 helper 的執行環境設定 `TPOWER_BACKUP_TIMEZONE`
與 `TPOWER_BACKUP_ROOT` 覆寫。備份含有 `SESSION_SECRET` 與帳號資料，目錄及檔案
分別限制為 root-only 的 `0700` 與 `0600`。

手動測試備份及 checksum：

```bash
sudo /usr/local/sbin/backup-tpower manual
sudo find /data/backups/tpower -maxdepth 2 -type f -printf '%M %u:%g %p\n'
sudo sh -c 'cd /data/backups/tpower/daily && sha256sum -c ./*.sha256'
```

還原前先停止服務，驗證 checksum，再將 archive 解到 `/`；archive 保留完整的
`data/official-sites/...` 路徑。還原後重新建立指定版本的 container 並檢查 health：

```bash
cd /data/official-sites
sudo docker compose stop tpower-official
cd /data/backups/tpower/daily
sudo sha256sum -c YYYY-MM-DD.tar.gz.sha256
sudo tar -xzf /data/backups/tpower/daily/YYYY-MM-DD.tar.gz -C /
cd /data/official-sites
sudo env TPOWER_TAG="$(sudo cat .tpower-current)" \
  docker compose up -d --no-deps --wait tpower-official
```

本機備份無法涵蓋 VM 或磁碟整體損壞；正式使用時仍應再同步一份到不同專案或
不同故障域的 GCS bucket，並定期做實際還原演練。

