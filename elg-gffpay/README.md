# elg-gffpay

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`elg-gffpay`　|　文件 **175**　|　主语言：PHP 154, JSON 5, YAML 2

## 目录构成

```
app/Filament/  — 27
config/  — 22
app/Http/  — 18
app/Enums/  — 15
app/Console/  — 14
database/migrations/  — 7
app/Models/  — 7
app/Helpers/  — 6
resources/lang/  — 6
docs/  — 4
tests/Feature/  — 4
.devcontainer/  — 4
```

## 关键文件

- CI/构建：`Makefile`、`.gitlab-ci.yml`、`.devcontainer/Dockerfile`
- 前端入口：`resources/js/app.js`

---

## 原始说明

# GFFPay - TON 支付系統

基於 Laravel 12 建構的 TON (The Open Network) 支付閘道系統，支援代收、代付和歸集功能。

## 技術棧

- **框架**: Laravel 12
- **PHP**: 8.3
- **管理後台**: Filament 4.0
- **區塊鏈**: TON (The Open Network)

## 主要功能

- **代收 (DEPOSIT)**: 用戶轉帳到子錢包
- **代付 (WITHDRAW)**: 主錢包轉帳到用戶
- **歸集 (SWEEP)**: 將子錢包資金歸集到主錢包
- **多幣種支援**: TON/TON、TON/USDT
- **錢包管理**: 支援主錢包和子錢包的建立與管理
- **訂單管理**: 完整的訂單生命週期管理
- **API 介面**: 提供 Frontend、Agent、External 三套 API

## 環境要求

- PHP 8.3
- Composer
- MySQL
- Redis

## 安裝步驟

### 1. 複製專案

```bash
git clone <repository-url>
cd gffpay
```

### 2. 安裝依賴

```bash
# 安裝 PHP 依賴
composer install
```

### 3. 環境設定

```bash
# 複製環境設定檔
cp .env.example .env

# 產生應用程式金鑰
php artisan key:generate
```

編輯 `.env` 檔案，設定資料庫、Redis 和 TON 相關設定。

### 4. 資料庫遷移

```bash
php artisan migrate
```

### 5. 初始化資料

按照以下順序執行初始化命令：

```bash
# 建立管理員帳戶
php artisan tool:create_admin

# 建立錢包
php artisan tool:create_wallet

# 建立主錢包
php artisan tool:create_main_wallet

# 初始化貨幣資料
php artisan db:seed --class=CurrencySeeder
```

## 開發命令

### 程式碼品質檢查

```bash
# 程式碼格式化檢查
composer pint:test

# 程式碼格式化
composer pint

# 靜態分析
composer stan

# 程式碼檢查和格式化
composer lint
```

### 測試

```bash
php artisan test
```

## 文件

- [API 文件](docs/API.md) - 完整的 API 介面說明文件

## 專案結構

```
gffpay/
├── app/
│   ├── Console/Commands/    # Artisan 命令
│   │   └── Tool/            # 工具命令（建立管理員、錢包等）
│   ├── Enums/               # 列舉類別
│   ├── Http/                # HTTP 層（Controllers, Middleware）
│   ├── Models/              # Eloquent 模型
│   ├── Services/            # 業務邏輯服務
│   └── Providers/           # 服務提供者
├── config/                  # 設定檔
├── database/
│   ├── migrations/          # 資料庫遷移
│   └── seeders/             # 資料填充
├── routes/
│   ├── api.php             # API 路由
│   ├── web.php             # Web 路由
│   └── console.php         # 控制台路由
├── resources/              # 視圖和前端資源
├── public/                 # 公共檔案
└── tests/                  # 測試檔案
```

## 主要服務

- **TonService**: TON 支付核心業務邏輯
- **TonGateway**: TON 區塊鏈互動閘道
- **BotNotifyService**: 機器人通知服務

## 訂單類型

- `DEPOSIT` (1): 代收訂單，用戶轉帳到子錢包
- `WITHDRAW` (2): 代付訂單，主錢包轉帳到用戶
- `SWEEP` (3): 歸集訂單，子錢包轉帳到主錢包

## 支援的貨幣

- TON/TON
- TON/USDT

## 預設管理員帳戶

執行 `php artisan tool:create_admin` 後會建立預設管理員：

- **使用者名稱**: `admin`
- **密碼**: `123456`

⚠️ **注意**: 生產環境請務必修改預設密碼！

## CI/CD

專案使用 GitLab CI 進行持續整合，詳細說明請參考 `docs/Gitlab CI 機制.md`。

### 分支限制

- `master` 不能直接 push
- `master` 僅允許來自 `develop` 的 Merge Request
- 開發功能請從 `master` 分支開出 `feature` 分支

