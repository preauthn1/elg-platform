#!/usr/bin/env bash
# 安裝到 VM 的 /usr/local/sbin/backup-tpower，並設為 root:root 0755。
# 由 deploy-tpower 在 seed / container replacement 前呼叫，也可由 root 手動執行。
set -Eeuo pipefail

STACK_DIR=/data/official-sites
SERVICE_NAME=tpower-official
CURRENT_RELEASE_FILE=$STACK_DIR/.tpower-current
BACKUP_ROOT=${TPOWER_BACKUP_ROOT:-/data/backups/tpower}
BACKUP_TIMEZONE=${TPOWER_BACKUP_TIMEZONE:-Asia/Kuala_Lumpur}
target_release=${1:-manual}

if [[ $EUID -ne 0 ]]; then
  echo '此腳本必須由 root 執行' >&2
  exit 1
fi

if [[ $target_release != manual && ! $target_release =~ ^[0-9a-f]{40}$ ]]; then
  echo '目標版本必須是完整的 40 字元 Git commit SHA，或 manual' >&2
  exit 1
fi

if [[ $BACKUP_ROOT != /* || $BACKUP_ROOT == / || $BACKUP_ROOT == /data || $BACKUP_ROOT == "$STACK_DIR" ]]; then
  echo "不安全的備份根目錄：$BACKUP_ROOT" >&2
  exit 1
fi

if [[ $BACKUP_TIMEZONE == *..* || ! -f /usr/share/zoneinfo/$BACKUP_TIMEZONE ]]; then
  echo "無效的備份時區：$BACKUP_TIMEZONE" >&2
  exit 1
fi

for required_path in "$STACK_DIR/docker-compose.yaml" "$STACK_DIR/env/tpower.env"; do
  if [[ ! -f $required_path ]]; then
    echo "缺少必要檔案，取消備份：$required_path" >&2
    exit 1
  fi
done

umask 077
install -d -o root -g root -m 0700 \
  "$BACKUP_ROOT/runs" \
  "$BACKUP_ROOT/daily" \
  "$BACKUP_ROOT/weekly" \
  "$BACKUP_ROOT/monthly" \
  "$BACKUP_ROOT/.tmp"

exec 8>"$BACKUP_ROOT/.backup.lock"
if ! flock -n 8; then
  echo '已有另一個 TPOWER 備份正在執行' >&2
  exit 1
fi

timestamp=$(TZ=$BACKUP_TIMEZONE date +%Y%m%dT%H%M%S%z)
day=$(TZ=$BACKUP_TIMEZONE date +%F)
day_compact=${day//-/}
week=$(TZ=$BACKUP_TIMEZONE date +%G-W%V)
month=$(TZ=$BACKUP_TIMEZONE date +%Y-%m)
archive=$BACKUP_ROOT/runs/${timestamp}_${target_release:0:12}.tar.gz
partial=$BACKUP_ROOT/.tmp/${timestamp}_${target_release:0:12}.tar.gz.partial
uncompressed=$BACKUP_ROOT/.tmp/${timestamp}_${target_release:0:12}.tar
manifest_dir=$(mktemp -d "$BACKUP_ROOT/.tmp/manifest.XXXXXX")
paused=0

cleanup() {
  status=$?
  trap - EXIT
  if (( paused )); then
    cd "$STACK_DIR"
    docker compose unpause "$SERVICE_NAME" >/dev/null 2>&1 || status=1
  fi
  rm -f -- "$partial"
  rm -f -- "$uncompressed"
  rm -rf -- "$manifest_dir"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cd "$STACK_DIR"
current_release=none
if [[ -f $CURRENT_RELEASE_FILE ]]; then
  read -r current_release < "$CURRENT_RELEASE_FILE" || true
fi

container_id=$(docker compose ps -q "$SERVICE_NAME" 2>/dev/null || true)
container_image=none
container_image_id=none
if [[ -n $container_id ]]; then
  container_image=$(docker inspect --format '{{.Config.Image}}' "$container_id")
  container_image_id=$(docker inspect --format '{{.Image}}' "$container_id")
fi

cat > "$manifest_dir/manifest.txt" <<EOF
format_version=1
created_at=$timestamp
timezone=$BACKUP_TIMEZONE
hostname=$(hostname -f 2>/dev/null || hostname)
current_release=$current_release
target_release=$target_release
container_image=$container_image
container_image_id=$container_image_id
EOF

archive_paths=(
  data/official-sites/docker-compose.yaml
  data/official-sites/env/tpower.env
)
optional_paths=(
  data/official-sites/.tpower-current
  data/official-sites/nginx/conf.d
  data/official-sites/data/tpower/content
  data/official-sites/data/tpower/uploads
)
for path in "${optional_paths[@]}"; do
  [[ -e /$path ]] && archive_paths+=("$path")
done

# 暫停寫入，確保 content、帳號資料與 uploads 位於同一個時間點。
if [[ -n $container_id ]] && [[ $(docker inspect --format '{{.State.Running}}' "$container_id") == true ]]; then
  docker compose pause "$SERVICE_NAME" >/dev/null
  paused=1
fi

# 先快速建立未壓縮的一致性 archive，恢復服務後才做耗 CPU 的 gzip。
tar -cf "$uncompressed" \
  -C / "${archive_paths[@]}" \
  -C "$manifest_dir" manifest.txt

if (( paused )); then
  docker compose unpause "$SERVICE_NAME" >/dev/null
  paused=0
fi

gzip -c "$uncompressed" > "$partial"
rm -f -- "$uncompressed"
gzip -t "$partial"
mv -f -- "$partial" "$archive"
chmod 0600 "$archive"

link_tier() {
  local tier=$1
  local name=$2
  local destination=$BACKUP_ROOT/$tier/$name.tar.gz
  local temporary=$BACKUP_ROOT/$tier/.${name}.tar.gz.tmp
  local digest

  rm -f -- "$temporary"
  ln -- "$archive" "$temporary"
  mv -fT -- "$temporary" "$destination"
  digest=$(sha256sum "$destination" | awk '{print $1}')
  printf '%s  %s\n' "$digest" "$(basename "$destination")" > "$destination.sha256.tmp"
  mv -f -- "$destination.sha256.tmp" "$destination.sha256"
}

# 每次部署更新該日／週／月的最後一份；hard link 不會重複占用 archive 空間。
link_tier daily "$day"
link_tier weekly "$week"
link_tier monthly "$month"

digest=$(sha256sum "$archive" | awk '{print $1}')
printf '%s  %s\n' "$digest" "$(basename "$archive")" > "$archive.sha256"

# runs 只保留今天最後 7 次；跨日代表檔由 daily/weekly/monthly 的 hard link 保存。
shopt -s nullglob
for old_archive in "$BACKUP_ROOT/runs/"*.tar.gz; do
  [[ $(basename "$old_archive") == "$day_compact"* ]] || {
    rm -f -- "$old_archive" "$old_archive.sha256"
  }
done

mapfile -t today_archives < <(
  printf '%s\n' "$BACKUP_ROOT/runs/""$day_compact"*.tar.gz | sort -r
)
for (( index=7; index<${#today_archives[@]}; index++ )); do
  rm -f -- "${today_archives[$index]}" "${today_archives[$index]}.sha256"
done

# 分層代表檔分別保留 7、31、93 天。
find "$BACKUP_ROOT/daily" -type f -mtime +6 -delete
find "$BACKUP_ROOT/weekly" -type f -mtime +30 -delete
find "$BACKUP_ROOT/monthly" -type f -mtime +92 -delete

echo "TPOWER 備份完成：$archive"
