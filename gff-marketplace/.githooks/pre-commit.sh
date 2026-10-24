#!/usr/bin/env bash
#
# GFF-Marketplace pre-commit hook：白標 registry 變更時自動驗證。
#
# 行為（registry-gated）：只有當 whitelabels.metadata.yaml 在本次 staged 時才觸發；
# 其餘 commit 完全不受影響。觸發時：
#   1. check-registry.php（結構/型別，離線可跑、與 CI 同一支 SSOT）：失敗即擋 commit（exit 1）。
#   2. verify-registry-live.py（live 語意，需 DB 可達）：
#        - DB 可達且一致      → 通過；
#        - DB/docker 不可達    → 只印警告、放行（push/release 前自行補跑）；
#        - DB 可達但值不一致   → 擋 commit（registry rootAgentId/username 寫錯）。
#
# 安裝（每位開發者一次）：
#   cp .githooks/pre-commit.sh .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
#
set -euo pipefail

REGISTRY="plugins/apis-toolkit/registry/whitelabels.metadata.yaml"
CHECK="plugins/apis-toolkit/scripts/check-registry.php"
VERIFY="plugins/apis-toolkit/scripts/verify-registry-live.py"

# registry-gated：registry 沒被 staged 就直接放行，不增加任何摩擦。
if ! git diff --cached --name-only --diff-filter=ACM | grep -qx "$REGISTRY"; then
    exit 0
fi

echo "[pre-commit] 偵測到 $REGISTRY 變更，驗證 registry…"

# 1) 結構/型別驗證（離線硬閘，php 與 CI 同一支）
if ! php "$CHECK"; then
    echo "[pre-commit] ❌ check-registry.php 未過，commit 中止。"
    exit 1
fi

# 2) live 語意驗證（連不到只警告、不擋）
set +e
python3 "$VERIFY"
rc=$?
set -e
case "$rc" in
    0) echo "[pre-commit] ✔ registry live 驗證通過。" ;;
    3) echo "[pre-commit] ⚠️  DB/docker 不可達，略過 live 驗證。push/release 前請手動跑：python3 $VERIFY" ;;
    *) echo "[pre-commit] ❌ registry 值與 DB 不一致（exit ${rc}），commit 中止。修正 registry 的 rootAgentId/username 後重試。"
       exit 1 ;;
esac

exit 0
