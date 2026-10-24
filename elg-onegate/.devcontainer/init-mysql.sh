#!/bin/bash
# ============================================================
# OneGate 資料庫初始化腳本
# ============================================================
# 此腳本用於初始化 MariaDB 資料庫和用戶
# ============================================================

set -e  # 遇到錯誤立即退出

echo "======================================"
echo "OneGate 資料庫初始化"
echo "======================================"
echo ""

# ============================================================
# 1. 啟動 MariaDB 服務
# ============================================================
echo "正在啟動 MariaDB 服務..."

# 檢查 MariaDB 是否已運行
if sudo service mariadb status > /dev/null 2>&1; then
    echo "MariaDB 已在運行中"
else
    sudo service mariadb start

    # 等待 MariaDB 啟動完成
    echo "等待 MariaDB 啟動..."
    for i in {1..30}; do
        if sudo mariadb -e "SELECT 1" > /dev/null 2>&1; then
            echo "MariaDB 已啟動"
            break
        fi
        if [ $i -eq 30 ]; then
            echo "錯誤：MariaDB 啟動超時"
            exit 1
        fi
        sleep 1
    done
fi

# ============================================================
# 2. 創建資料庫和用戶
# ============================================================
echo ""
echo "正在創建資料庫和用戶..."

sudo mariadb -e "
    CREATE DATABASE IF NOT EXISTS onegate
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'onegate'@'localhost'
        IDENTIFIED BY 'onegate_dev_password';
    GRANT ALL PRIVILEGES ON onegate.* TO 'onegate'@'localhost';
    FLUSH PRIVILEGES;
"

echo ""
echo "======================================"
echo "資料庫初始化完成"
echo "======================================"
echo ""
echo "連線資訊："
echo "  Host:     0.0.0.0"
echo "  Port:     3306"
echo "  Database: onegate"
echo "  Username: onegate"
echo "  Password: [REDACTED]"
echo ""
