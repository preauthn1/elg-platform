#!/usr/bin/env python3
"""registry 的 live 語意驗證（補 check-registry.php 的結構驗證之不足）。

check-registry.php 只驗「型別 / 碰撞 / sharesConnection 與連線次數一致」等**離線不變式**，
擋不住「打錯但合法」的 agent id——一個指到另一個真實 agent 的 id 會通過結構驗證，
卻把查詢/對帳靜默 scope 到錯子樹、或把商戶配置掛到錯代理。本腳本對每個 production 白標連 DB 實證：

  正式代理 rootAgentId（該白標營運子樹 root，查詢範疇基準）：
    1. id 在該 connectionId 確實存在；
    2. agents.username == registry 的 rootAgentUsername。
       不強制 Admin 直屬（/1/<id>）——VWorld 營運 root 為 Acompany（hierarchy_path=/1/33893/66829），
       vworldmy(33893) 只是名義上層總代，故正式代理位置不設死。
  測試代理 testRootAgentId：
    1. id 在該 connectionId 確實存在；
    2. agents.username == registry 的 testRootAgentUsername；
    3. agents.parent == rootAgentId（測試代理直接掛在該白標正式總代理下）。

定位：**非 CI 硬閘**（CI 無 python3、連不到生產讀庫），屬本機 / 容器 pre-release gate。
依賴：host 可執行 docker、且 php-fpm8 容器能連到各白標讀庫（與 whitelabel-db-query 同一條讀路徑）。
唯讀：只跑 SELECT；PHP 端僅取數，比對邏輯單一收斂在本檔。

用法（在 gff-marketplace repo 內，DB 可達時）：
    python3 plugins/apis-toolkit/scripts/verify-registry-live.py
    python3 plugins/apis-toolkit/scripts/verify-registry-live.py --container php-fpm8

exit code（供 pre-commit hook 區分「擋」與「警告放行」）：
  0 = 全部 production 白標（正式＋測試代理）live 一致；
  1 = 資料不一致（MISMATCH / agent-not-found——DB 可達但 registry 值錯）→ 應擋；
  2 = registry 解析 / 用法錯誤（yaml 模組缺、檔壞）→ 應擋；
  3 = 環境不可達（docker/tinker 跑不起、連線 timeout、無輸出）→ 非資料問題，hook 可警告放行。
"""
import argparse
import json
import pathlib
import subprocess
import sys

try:
    import yaml
except ImportError:
    print("FAIL: 需要 python3 yaml 模組（pip install pyyaml）", file=sys.stderr)
    sys.exit(2)

# PHP 端笨取數器：對每個 {conn,id} 取 agents row，輸出 pipe 分隔行供 python 比對。
# 連線/查詢例外一律 catch 成 __ERR__，不讓單站失敗中斷整批。輸出恆 5 段：conn|id|user|parent|hierarchy_path。
PHP_FETCHER = r"""
$targets = json_decode(<<<'JSON'
%s
JSON, true);
foreach ($targets as $t) {
    try {
        $row = DB::connection($t['conn'])->table('agents')
            ->select('id', 'username', 'parent', 'hierarchy_path')->where('id', $t['id'])->first();
        if (! $row) { echo $t['conn'].'|'.$t['id'].'|__NONE__|__NONE__|__NONE__'."\n"; continue; }
        $u = str_replace(["\n", '|'], ' ', (string) $row->username);
        $p = str_replace(["\n", '|'], ' ', (string) $row->parent);
        $h = str_replace(["\n", '|'], ' ', (string) $row->hierarchy_path);
        echo $t['conn'].'|'.$t['id'].'|'.$u.'|'.$p.'|'.$h."\n";
    } catch (\Throwable $e) {
        echo $t['conn'].'|'.$t['id'].'|__ERR__|__ERR__|'.str_replace(["\n", '|'], ' ', $e->getMessage())."\n";
    }
}
"""


def load_production_entries(registry_path: pathlib.Path) -> list[dict]:
    data = yaml.safe_load(registry_path.read_text(encoding="utf-8"))
    wl = (data or {}).get("whitelabels")
    if not isinstance(wl, dict) or not wl:
        print("FAIL: whitelabels 缺失或為空", file=sys.stderr)
        sys.exit(2)
    out = []
    for key, e in wl.items():
        if not isinstance(e, dict) or e.get("env") != "production":
            continue
        out.append({
            "canonical": key,
            "conn": e.get("connectionId"),
            "root_id": e.get("rootAgentId"),
            "root_user": e.get("rootAgentUsername"),
            "test_id": e.get("testRootAgentId"),
            "test_user": e.get("testRootAgentUsername"),
        })
    return out


def run_fetcher(container: str, targets: list[dict]) -> dict[tuple, tuple]:
    # 收集每白標的正式＋測試兩個 agent id，(conn,id) 去重後一次取數。
    pairs = []
    seen = set()
    for t in targets:
        for aid in (t["root_id"], t["test_id"]):
            key = (t["conn"], aid)
            if aid is not None and key not in seen:
                seen.add(key)
                pairs.append({"conn": t["conn"], "id": aid})
    payload = json.dumps(pairs)
    php = PHP_FETCHER % payload
    cmd = [
        "docker", "exec", container, "bash", "-lc",
        "cd /var/www/APIs && php artisan tinker --execute " + _shquote(php),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0 and not proc.stdout.strip():
        print(f"FAIL: 無法在容器 {container} 執行 tinker（DB 可達？容器啟動？）\n{proc.stderr.strip()}",
              file=sys.stderr)
        sys.exit(3)
    result = {}
    for line in proc.stdout.splitlines():
        parts = line.split("|", 4)
        if len(parts) != 5:
            continue
        conn, rid, user, parent, path = parts
        result[(conn, rid)] = (user, parent, path)
    return result


def _shquote(s: str) -> str:
    return "'" + s.replace("'", "'\\''") + "'"


def verdict_for(fetched: dict, conn: str, agent_id, exp_user, *, exp_parent=None):
    """比對單一 agent。回傳 (verdict, shown_user, shown_parent, shown_path, data_err, env_err)。
    exp_parent 有值＝驗 agents.parent（測試代理須直接掛在正式代理下）；不傳＝只驗 id+username（正式代理）。"""
    db = fetched.get((conn, str(agent_id)))
    if db is None:
        return ("FAIL:no-output", "-", "-", "-", False, True)
    db_user, db_parent, db_path = db
    if db_user == "__ERR__":
        # db_path 段存 error message（見 PHP_FETCHER catch）
        return ("FAIL:conn-error", "-", "-", db_path, False, True)
    if db_user == "__NONE__":
        # 查詢有跑、id 在 DB 不存在＝registry 值錯（資料問題）
        return ("FAIL:agent-not-found", "-", "-", "-", True, False)
    bad = []
    if db_user != str(exp_user):
        bad.append("user")
    if exp_parent is not None and db_parent != str(exp_parent):
        bad.append(f"parent(exp {exp_parent})")
    if not bad:
        return ("OK", db_user, db_parent, db_path, False, False)
    return ("MISMATCH " + " ".join(bad), db_user, db_parent, db_path, True, False)


def main() -> int:
    ap = argparse.ArgumentParser(description="registry live 語意驗證（唯讀）")
    ap.add_argument("--container", default="php-fpm8", help="APIs 容器名（預設 php-fpm8）")
    args = ap.parse_args()

    registry = pathlib.Path(__file__).resolve().parents[1] / "registry" / "whitelabels.metadata.yaml"
    targets = load_production_entries(registry)
    if not targets:
        print("FAIL: registry 無 production 白標可驗")
        return 1

    fetched = run_fetcher(args.container, targets)

    print(f"{'canonical':<12} {'role':<5} {'conn':<10} {'id':<7} {'exp_user':<14} "
          f"{'db_user':<14} {'parent':<8} {'hierarchy_path':<24} verdict")
    print("-" * 118)
    data_errs = 0   # MISMATCH / agent-not-found：DB 可達但 registry 值錯 → 應擋（exit 1）
    env_errs = 0    # conn-error / no-output：環境不可達 → 非資料問題（exit 3）
    for t in targets:
        conn = t["conn"]
        rows = [
            # 正式代理：只驗 id 存在 + username（位置不設死，允許 VWorld 這類非 Admin 直屬營運 root）
            ("root", t["root_id"], t["root_user"], {}),
            # 測試代理：id + username + parent == rootAgentId（直接掛在正式總代理下）
            ("test", t["test_id"], t["test_user"], {"exp_parent": t["root_id"]}),
        ]
        for role, aid, exp_user, kw in rows:
            verdict, db_user, db_parent, db_path, de, ee = verdict_for(fetched, conn, aid, exp_user, **kw)
            if de:
                data_errs += 1
            if ee:
                env_errs += 1
            print(f"{t['canonical']:<12} {role:<5} {conn:<10} {str(aid):<7} {str(exp_user):<14} "
                  f"{db_user:<14} {db_parent:<8} {db_path:<24} {verdict}")

    total = len(targets) * 2
    if data_errs:
        print(f"\nFAIL: {data_errs}/{total} 代理項 registry 值與 DB 不一致（資料問題）")
        return 1
    if env_errs:
        print(f"\nWARN: {env_errs}/{total} 代理項因環境不可達未能驗證（DB/docker 連線問題，非資料問題）")
        return 3
    print(f"\nOK: {total} 代理項（{len(targets)} 白標 × 正式+測試）live 驗證全部一致")
    return 0


if __name__ == "__main__":
    sys.exit(main())
