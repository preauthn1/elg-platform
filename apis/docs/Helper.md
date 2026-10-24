# 常用命令

## IDE-Helper：

### 替模型寫入 phpdoc
```bash
php artisan ide-helper:models "App\Models\{ModelName}"

php artisan ide-helper:models "App\Vendors\Models\{ModelName}"
```

## 三方遊戲：

### 檢查遊戲配置
```bash
php artisan vendor:check_vendor
```

### 配置遊戲代理
```bash
php artisan vendor:add_vendor_info
```

### 新增遊戲 Driver 配置
```bash
php artisan make:vendor
```

## 三方金流：

### 新增代收代付
```bash
php artisan make:payment
```

### 新增代理金流商戶
```bash
php artisan agent:add-agent-cash-flow
```
