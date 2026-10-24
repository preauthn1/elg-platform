#!/usr/bin/env bash
#
# mirror.sh — 將當前 repo 推送到 CSR（觸發 Cloud Build）
#
# 用法：
#   .gcb/mirror.sh                      # 推送當前分支
#   .gcb/mirror.sh develop              # 推送指定分支
#   .gcb/mirror.sh release_20260423     # 推送指定 tag
#   .gcb/mirror.sh --all                # 推送所有分支 + tag
#
# 前置條件：
#   1. 已執行 `gcloud auth login` 並具備目標專案的推送權限
#   2. 已透過 `gcloud config set project <PROJECT_ID>` 選定目標專案
#   3. 已執行 `.gcb/setup.sh` 完成 CSR repo 初始化

set -euo pipefail

CSR_REPO_NAME="apis-mirror"
CSR_REMOTE_NAME="csr"

# ─────────────────────────────────────────────────────────────
# 取得 PROJECT_ID
# ─────────────────────────────────────────────────────────────
PROJECT_ID="$(gcloud config get-value project 2>/dev/null)"
if [[ -z "${PROJECT_ID}" || "${PROJECT_ID}" == "(unset)" ]]; then
  echo "❌ 未設定 gcloud project，請先執行：gcloud config set project <PROJECT_ID>" >&2
  exit 1
fi

CSR_URL="https://example.invalid/p/${PROJECT_ID}/r/${CSR_REPO_NAME}"

# ─────────────────────────────────────────────────────────────
# 自動偵測／補建 csr remote
# ─────────────────────────────────────────────────────────────
if git remote get-url "${CSR_REMOTE_NAME}" >/dev/null 2>&1; then
  EXISTING_URL="$(git remote get-url "${CSR_REMOTE_NAME}")"
  if [[ "${EXISTING_URL}" != "${CSR_URL}" ]]; then
    echo "ℹ 既有 csr remote URL 為 ${EXISTING_URL}"
    echo "  目前 PROJECT_ID 對應的 URL 為 ${CSR_URL}"
    echo "  將以新 URL 覆寫 csr remote"
    git remote set-url "${CSR_REMOTE_NAME}" "${CSR_URL}"
  fi
else
  echo "→ 新增 git remote：${CSR_REMOTE_NAME} → ${CSR_URL}"
  git remote add "${CSR_REMOTE_NAME}" "${CSR_URL}"
fi

# ─────────────────────────────────────────────────────────────
# 決定推送目標
# ─────────────────────────────────────────────────────────────
TARGET="${1:-}"

if [[ "${TARGET}" == "--all" ]]; then
  echo "→ 推送所有分支到 csr"
  git push "${CSR_REMOTE_NAME}" --all
  echo "→ 推送所有 tag 到 csr"
  git push "${CSR_REMOTE_NAME}" --tags
  echo "✅ 完成"
  exit 0
fi

if [[ -z "${TARGET}" ]]; then
  TARGET="$(git rev-parse --abbrev-ref HEAD)"
  if [[ "${TARGET}" == "HEAD" ]]; then
    echo "❌ 當前處於 detached HEAD 狀態，請手動指定分支或 tag" >&2
    exit 1
  fi
  echo "→ 未指定目標，使用當前分支：${TARGET}"
fi

# ─────────────────────────────────────────────────────────────
# 推送到 CSR
# ─────────────────────────────────────────────────────────────
echo "→ 推送 ${TARGET} 到 ${CSR_REMOTE_NAME}"
git push "${CSR_REMOTE_NAME}" "${TARGET}"

cat <<EOF

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ 已推送 ${TARGET} 到 CSR
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

如果 ${TARGET} 符合 trigger 條件（develop 分支或 release_* tag），
Cloud Build 會自動執行。

查看最新 build：
  gcloud builds list --limit=5 --project=${PROJECT_ID}

即時追蹤最新 build：
  BUILD_ID=\$(gcloud builds list --limit=1 --format='value(id)' --project=${PROJECT_ID})
  gcloud builds log \${BUILD_ID} --stream --project=${PROJECT_ID}

EOF
