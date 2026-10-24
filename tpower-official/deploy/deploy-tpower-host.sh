#!/usr/bin/env bash
# 安裝到 VM 的 /usr/local/sbin/deploy-tpower，檔案必須由 root 擁有且 deploy user 不可寫。
# CI 先用 prepare 建立 deploy user 可寫的 staging，傳完後再用 deploy 執行部署。
set -Eeuo pipefail

STACK_DIR=/data/official-sites
SOURCE_DIR=/data/official-sites/tpower.game
SERVICE_NAME=tpower-official
IMAGE_NAME=tpower-official
NGINX_SERVICE=nginx
STAGING_ROOT=/var/tmp/tpower-upload
CURRENT_RELEASE_FILE=/data/official-sites/.tpower-current
BACKUP_COMMAND=/usr/local/sbin/backup-tpower

if [[ ${EUID} -ne 0 || -z ${SUDO_USER:-} ]]; then
  echo '此腳本必須由部署帳號透過 sudo 執行' >&2
  exit 1
fi

action=${1:-}
release_tag=${2:-}

if [[ $action != prepare && $action != deploy ]]; then
  echo '用法：deploy-tpower prepare|deploy <完整 commit SHA>' >&2
  exit 1
fi

if [[ ! $release_tag =~ ^[0-9a-f]{40}$ ]]; then
  echo 'release tag 必須是完整的 40 字元 Git commit SHA' >&2
  exit 1
fi

deploy_group=$(id -gn "$SUDO_USER")
upload_dir=$STAGING_ROOT/$SUDO_USER/$release_tag

if [[ $action == prepare ]]; then
  install -d -o root -g root -m 0755 "$STAGING_ROOT"
  # user 上層由 root 擁有，避免部署帳號把整個 release 目錄替換成 symlink。
  install -d -o root -g root -m 0755 "$STAGING_ROOT/$SUDO_USER"
  install -d -o "$SUDO_USER" -g "$deploy_group" -m 0700 "$upload_dir"
  exit 0
fi

resolved_upload_dir=$(realpath -e -- "$upload_dir" 2>/dev/null || true)
if [[ $resolved_upload_dir != "$upload_dir" || ! -d $resolved_upload_dir ]]; then
  echo "找不到安全的上傳目錄：$upload_dir" >&2
  exit 1
fi

# 除了 GitLab resource_group，再從 VM 端阻止人工部署與 CI 同時執行。
exec 9>/run/lock/tpower-deploy.lock
if ! flock -n 9; then
  echo '已有另一個 TPOWER 部署正在執行' >&2
  exit 1
fi

install -d -o root -g root -m 0755 "$SOURCE_DIR"
rsync -a --delete --safe-links --chown=root:root \
  --exclude='.git/' \
  --exclude='node_modules/' \
  --exclude='.env*' \
  --exclude='content/' \
  --exclude='uploads/' \
  "$resolved_upload_dir/" "$SOURCE_DIR/"

cd "$STACK_DIR"

previous_tag=''
if [[ -f $CURRENT_RELEASE_FILE ]]; then
  read -r previous_tag < "$CURRENT_RELEASE_FILE" || true
  if [[ ! $previous_tag =~ ^[0-9a-f]{40}$ ]]; then
    echo "忽略格式不正確的目前版本紀錄：$CURRENT_RELEASE_FILE" >&2
    previous_tag=''
  elif ! docker image inspect "$IMAGE_NAME:$previous_tag" >/dev/null 2>&1; then
    echo "找不到上一版 image，這次部署無法自動回復：$IMAGE_NAME:$previous_tag" >&2
    previous_tag=''
  fi
fi

TPOWER_TAG=$release_tag docker compose build "$SERVICE_NAME"

# 在 seed 或替換 container 可能修改正式資料前先備份；備份失敗即停止部署。
if [[ ! -x $BACKUP_COMMAND ]]; then
  echo "找不到可執行的備份程式：$BACKUP_COMMAND" >&2
  exit 1
fi
"$BACKUP_COMMAND" "$release_tag"

# seed 本身只補缺少的 entity，不會覆蓋既有正式內容。每次執行可修復前次中斷
# 所留下的部分 seed，也能在後續版本加入新的預設 entity。
TPOWER_TAG=$release_tag docker compose run --rm --no-deps "$SERVICE_NAME" npm run seed

if ! TPOWER_TAG=$release_tag docker compose up \
  -d --no-deps --wait --wait-timeout 90 "$SERVICE_NAME"; then
  docker compose logs --no-color --tail=200 "$SERVICE_NAME" >&2 || true

  if [[ -n $previous_tag && $previous_tag != "$release_tag" ]]; then
    echo "新版啟動失敗，回復上一版：$previous_tag" >&2
    if TPOWER_TAG=$previous_tag docker compose up \
      -d --no-deps --wait --wait-timeout 90 "$SERVICE_NAME"; then
      docker compose exec -T "$NGINX_SERVICE" nginx -t
      docker compose exec -T "$NGINX_SERVICE" nginx -s reload
      echo "已回復上一版：$previous_tag" >&2
    else
      echo "上一版也無法恢復：$previous_tag" >&2
      docker compose logs --no-color --tail=200 "$SERVICE_NAME" >&2 || true
    fi
  fi
  exit 1
fi

current_release_tmp=$(mktemp "$STACK_DIR/.tpower-current.XXXXXX")
printf '%s\n' "$release_tag" > "$current_release_tmp"
chmod 0644 "$current_release_tmp"
mv -f "$current_release_tmp" "$CURRENT_RELEASE_FILE"

docker compose exec -T "$NGINX_SERVICE" nginx -t
docker compose exec -T "$NGINX_SERVICE" nginx -s reload

echo "TPOWER 已部署：$release_tag"
