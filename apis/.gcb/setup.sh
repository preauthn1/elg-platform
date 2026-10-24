#!/usr/bin/env bash
#
# setup.sh — 一次性初始化 CSR 災備環境
#
# 前置條件：
#   1. 已執行 `gcloud auth login`
#   2. 已透過 `gcloud config set project <PROJECT_ID>` 選定目標專案
#   3. 該專案已啟用 Cloud Build、Cloud Source Repositories、Artifact Registry API
#
# 此腳本為冪等腳本：重複執行只會補齊缺少的資源，不會破壞既有設定。

set -euo pipefail

# ─────────────────────────────────────────────────────────────
# 1) 取得 PROJECT_ID
# ─────────────────────────────────────────────────────────────
PROJECT_ID="$(gcloud config get-value project 2>/dev/null)"
if [[ -z "${PROJECT_ID}" || "${PROJECT_ID}" == "(unset)" ]]; then
  echo "❌ 未設定 gcloud project，請先執行：gcloud config set project <PROJECT_ID>" >&2
  exit 1
fi
echo "✅ PROJECT_ID = ${PROJECT_ID}"

CSR_REPO_NAME="apis-mirror"
AR_LOCATION="asia-east1"
AR_REPO_NAME="bucket2024"

# ─────────────────────────────────────────────────────────────
# 2) 建立 CSR repo（若已存在則略過）
# ─────────────────────────────────────────────────────────────
echo "→ 檢查 / 建立 CSR repo：${CSR_REPO_NAME}"
if gcloud source repos describe "${CSR_REPO_NAME}" --project="${PROJECT_ID}" >/dev/null 2>&1; then
  echo "  ℹ CSR repo 已存在，略過"
else
  gcloud source repos create "${CSR_REPO_NAME}" --project="${PROJECT_ID}"
  echo "  ✅ 已建立 CSR repo"
fi

# ─────────────────────────────────────────────────────────────
# 3) 驗證 Artifact Registry bucket2024 存在
#    csr/ 子路徑為 repo 內邏輯路徑，無需事先建立
# ─────────────────────────────────────────────────────────────
echo "→ 驗證 Artifact Registry：${AR_REPO_NAME} @ ${AR_LOCATION}"
if gcloud artifacts repositories describe "${AR_REPO_NAME}" \
  --location="${AR_LOCATION}" \
  --project="${PROJECT_ID}" >/dev/null 2>&1; then
  echo "  ✅ Artifact Registry repo 已存在"
else
  echo "  ❌ Artifact Registry repo ${AR_REPO_NAME} 不存在於 ${AR_LOCATION}，請先手動建立" >&2
  exit 1
fi

# ─────────────────────────────────────────────────────────────
# 4) 建立 Cloud Build triggers
#    - apis-develop：develop 分支推送時觸發
#    - apis-release：release_\d+ tag 推送時觸發
# ─────────────────────────────────────────────────────────────
echo "→ 建立 Cloud Build trigger：apis-develop"
if gcloud builds triggers describe apis-develop --project="${PROJECT_ID}" >/dev/null 2>&1; then
  echo "  ℹ trigger apis-develop 已存在，略過"
else
  gcloud builds triggers create cloud-source-repositories \
    --project="${PROJECT_ID}" \
    --name=apis-develop \
    --repo="${CSR_REPO_NAME}" \
    --branch-pattern='^develop$' \
    --build-config=cloudbuild.yaml
  echo "  ✅ 已建立 trigger apis-develop"
fi

echo "→ 建立 Cloud Build trigger：apis-release"
if gcloud builds triggers describe apis-release --project="${PROJECT_ID}" >/dev/null 2>&1; then
  echo "  ℹ trigger apis-release 已存在，略過"
else
  gcloud builds triggers create cloud-source-repositories \
    --project="${PROJECT_ID}" \
    --name=apis-release \
    --repo="${CSR_REPO_NAME}" \
    --tag-pattern='^release_\d+$' \
    --build-config=cloudbuild.yaml
  echo "  ✅ 已建立 trigger apis-release"
fi

# ─────────────────────────────────────────────────────────────
# 5) 授予 Cloud Build default SA 所需權限
# ─────────────────────────────────────────────────────────────
echo "→ 取得 Cloud Build default service account"
PROJECT_NUMBER="$(gcloud projects describe "${PROJECT_ID}" --format='value(projectNumber)')"
CB_SA="${PROJECT_NUMBER}@example.invalid"
echo "  CB_SA = ${CB_SA}"

echo "→ 授予 roles/artifactregistry.writer"
gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member="serviceAccount:${CB_SA}" \
  --role="roles/artifactregistry.writer" \
  --condition=None \
  >/dev/null

echo "→ 授予 roles/source.reader"
gcloud projects add-iam-policy-binding "${PROJECT_ID}" \
  --member="serviceAccount:${CB_SA}" \
  --role="roles/source.reader" \
  --condition=None \
  >/dev/null

echo "  ✅ IAM 綁定完成"

# ─────────────────────────────────────────────────────────────
# 6) 下一步提示
# ─────────────────────────────────────────────────────────────
cat <<EOF

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ CSR 災備環境初始化完成
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

下一步：將程式碼推送到 CSR 以觸發 Cloud Build。

推薦使用封裝腳本：
  chmod +x .gcb/mirror.sh
  .gcb/mirror.sh                      # 推送當前分支
  .gcb/mirror.sh develop              # 推送指定分支
  .gcb/mirror.sh release_20260423     # 推送指定 tag
  .gcb/mirror.sh --all                # 推送所有分支與 tag

或手動執行：
  git remote add csr https://example.invalid/p/${PROJECT_ID}/r/${CSR_REPO_NAME}
  git push csr develop

查看 build 狀態：
  gcloud builds list --limit=5 --project=${PROJECT_ID}

查看 image：
  gcloud artifacts docker images list \\
    ${AR_LOCATION}-docker.pkg.dev/${PROJECT_ID}/${AR_REPO_NAME}/csr \\
    --project=${PROJECT_ID}

EOF
