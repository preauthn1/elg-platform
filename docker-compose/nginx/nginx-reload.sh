#!/bin/sh
# marker-file 驅動的 vhost 生成器，只掃 AUTO_WORKSPACE_PATH（/var/www-auto），
# 不動 /var/www 底下既有 8 個手寫 nginx/sites/*.conf。
set -e

BASE_DIR="/var/www-auto"
OUTPUT="/etc/nginx/conf.d/auto-generated.conf"
WATCH_LOCK="/tmp/.nginx-reload-watch.pid"
POLL_INTERVAL=5

gen_vhost() {
    type="$1"
    service_dir="$2"
    domain="$3"

    case "$type" in
        laravel8)  upstream="php-fpm8:9000"; suffix="public" ;;
        laravel73) upstream="php-fpm:9000";  suffix="public" ;;
        php8)      upstream="php-fpm8:9000"; suffix="" ;;
        php73)     upstream="php-fpm:9000";  suffix="" ;;
    esac

    if [ -d "${service_dir}/_release" ]; then
        base_root="${service_dir}/_release"
    else
        base_root="$service_dir"
    fi
    if [ -n "$suffix" ]; then
        root_dir="${base_root}/${suffix}"
    else
        root_dir="$base_root"
    fi

    case "$type" in
        laravel8|laravel73) index_try="try_files \$uri \$uri/ /index.php?\$query_string;" ;;
        php8|php73)         index_try="try_files \$uri \$uri/ =404;" ;;
    esac

    cat <<BLOCK
server {
    listen 80;
    listen [::]:80;

    server_name ${domain};
    root ${root_dir};
    index index.html index.htm index.php;

    charset utf-8;

    location / {
        ${index_try}
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        try_files \$uri /index.php =404;
        set \$php_upstream "${upstream}";
        fastcgi_pass \$php_upstream;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_read_timeout 600;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }

    include ${service_dir}/.nginx-extra.conf*;

    error_log /var/log/nginx/${domain}_error.log;
    access_log /var/log/nginx/${domain}_access.log;
}
BLOCK
}

generate_all() {
    : > "$OUTPUT"
    for typefile in "$BASE_DIR"/*/*/.nginx-type; do
        [ -e "$typefile" ] || continue

        service_dir="$(dirname "$typefile")"
        service="$(basename "$service_dir")"
        project="$(basename "$(dirname "$service_dir")")"
        type="$(tr -d '[:space:]' < "$typefile")"

        domain_file="${service_dir}/.nginx-domain"
        if [ -f "$domain_file" ]; then
            domain="$(tr -d '[:space:]' < "$domain_file")"
        else
            service_slug="$(echo "$service" | tr '[:upper:]_' '[:lower:]-')"
            project_slug="$(echo "$project" | tr '[:upper:]_' '[:lower:]-')"
            domain="${service_slug}.${project_slug}.localhost"
        fi

        case "$type" in
            laravel8|laravel73|php8|php73)
                gen_vhost "$type" "$service_dir" "$domain" >> "$OUTPUT"
                ;;
            *)
                echo "nginx-reload: WARN unknown .nginx-type '${type}' in ${service_dir}, skipped" >&2
                ;;
        esac
    done
}

# /run/nginx.pid 在 master 真正啟動前可能已存在但是空檔（image 本身的殘留），
# 光用 -f 判斷會誤判成「正在跑」進而對空 pid 呼叫 nginx -s reload 而炸掉整個 entrypoint 鏈，
# 所以要求非空檔（-s）且裡面的 PID 確實對應一個存活中的行程（kill -0）。
reload_if_running() {
    if [ -s /run/nginx.pid ] && kill -0 "$(cat /run/nginx.pid)" 2>/dev/null; then
        nginx -s reload
    fi
}

# marker file（含內容）的指紋，用來判斷 www-auto/ 是否有變動（新增/刪除/改內容都會反映在這裡）
fingerprint() {
    for f in "$BASE_DIR"/*/*/.nginx-type "$BASE_DIR"/*/*/.nginx-domain "$BASE_DIR"/*/*/.nginx-extra.conf; do
        [ -e "$f" ] || continue
        printf '%s\n' "$f"
        cat "$f"
    done | md5sum | cut -d' ' -f1
}

generate_all
nginx -t
reload_if_running

# 背景輪詢：容器活著期間持續每 POLL_INTERVAL 秒檢查一次 www-auto/ 有沒有變動，
# 有變動才重新生成＋reload，新增專案不必再手動進容器重跑這支腳本。
# 用 WATCH_LOCK 記錄迴圈的 pid，避免手動重跑本腳本時疊加出第二個迴圈。
if ! { [ -s "$WATCH_LOCK" ] && kill -0 "$(cat "$WATCH_LOCK")" 2>/dev/null; }; then
    (
        # 這個子殼是要活一輩子的背景迴圈，關掉 set -e 繼承，
        # 讓單次 nginx -t / nginx -s reload 失敗只印警告、不會把整個輪詢迴圈跟著中止掉。
        set +e
        last_fp="$(fingerprint)"
        while true; do
            sleep "$POLL_INTERVAL"
            fp="$(fingerprint)"
            if [ "$fp" != "$last_fp" ]; then
                generate_all
                if nginx -t; then
                    reload_if_running
                    last_fp="$fp"
                else
                    echo "nginx-reload: WARN www-auto/ 有變動但新設定 nginx -t 沒過，維持舊設定，下次輪詢會再試一次" >&2
                fi
            fi
        done
    ) &
    echo "$!" > "$WATCH_LOCK"
fi
