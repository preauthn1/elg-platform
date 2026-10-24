<?php
/**
 * 白標 registry 結構 + defense-in-depth 驗證（CI 硬閘：phpbase 鏡像鐵定有 php）。
 *
 * 取代 check-registry.py：CI runner 無 python3，唯一保證可用的直譯器是 php，
 * 故把結構/型別不變式改用 php 實作，讓根 scripts/validate.sh §8 直接跑成硬閘。
 * 本機 / pre-commit 亦改跑本檔（單一 SSOT，避免 php/python 兩份邏輯漂移）。
 *
 * 驗證項（與退役的 check-registry.py 逐條對齊）：
 *   - 必填欄位（缺即無效＝fail-closed 語意）
 *   - canonical == key、key/canonical 無前後空白
 *   - connectionId safe-token（^[A-Za-z0-9_]+$）
 *   - env ∈ {production, test}
 *   - aliases 為非空、無前後空白的 string list
 *   - 別名/名稱全域大小寫不敏感唯一（碰撞即擋）
 *   - rootAgent 型別：production 須 int + 非空 username；test 須皆為 null（正式 rootAgent* 與測試 testRootAgent* 同規則）
 *   - sharesConnection 為 bool 且與連線共用實況一致（connectionId 出現 >1 次＝共用）
 *   - 規模閘 EXPECT_N：頂層 entry 數須等於常數（意圖性變更閘，新增/移除白標時同步）
 *
 * 只驗**結構/型別不變式**；擋不住「打錯但合法」的 rootAgentId（指到另一真實 agent）。
 * rootAgentId 的 live 語意（id 存在、username 吻合、hierarchy_path == /1/<id>）由
 * verify-registry-live.py 在 DB 可達時驗證——見 ../registry/README.md「驗證分層」。
 *
 * 用法：php check-registry.php [registry.yaml]
 *   省略路徑時用標準 registry；帶路徑供測試餵畸形 fixture。
 * exit：0 通過 / 1 驗證失敗 / 2 讀檔或解析錯誤。
 */

const EXPECT_N = 10;                       // registry 規模閘：新增/移除白標時同步此數
const REQUIRED = ['canonical', 'aliases', 'connectionId', 'env',
                  'rootAgentId', 'rootAgentUsername',
                  'testRootAgentId', 'testRootAgentUsername', 'sharesConnection',
                  'dbcPort', 'database'];
const DBC_PORT_MIN = 1024;                 // dbc instances.yaml 用 34xx/35xx；下限只擋 well-known port 誤填
const DBC_PORT_MAX = 65535;
// dbcPort: null 是安全降級（該白標退回共用帳密、查詢不進 dbc 稽核），必須是「意圖性」的：
// 只有列在這裡的白標允許 null（意圖閘，與 EXPECT_N 同慣例）。dbc 涵蓋後把 port 填上並從此表移除。
const DBC_NULL_ALLOWED = ['VPlus33'];      // VPlus33：sre/dbc instances.yaml 尚未收錄該站實例
// 同一個 dbcPort 對到多個 connectionId＝連錯站（所有站 database 都叫 xsgcasino，回傳看不出來）；
// 只有這裡列的組合允許共用 port（mysql 與 Dev 是同一顆 Dev 庫的別名對）。
const DBC_PORT_SHARED_OK = [['mysql', 'Dev']];
const ENVS = ['production', 'test'];

/** 受限 YAML parser：只認此 registry 用到的子集（頂層 map + block/flow entry），
 *  不追求通用 YAML。回傳 ['whitelabels' => [key => [field => value]]] 或拋例外。 */
function parse_registry(string $text): array
{
    $lines = explode("\n", $text);
    $root = null;                          // 目前是否已進入 whitelabels: 區塊
    $whitelabels = [];
    $curKey = null;                        // 目前正在累積的 block entry key
    $n = count($lines);

    for ($ln = 0; $ln < $n; $ln++) {
        $raw = rtrim($lines[$ln], "\r");
        if (trim($raw) === '' || preg_match('/^\s*#/', $raw)) {
            continue;                      // 空行 / 註解行
        }
        // 頂層根鍵 whitelabels:
        if (preg_match('/^whitelabels:\s*$/', $raw)) {
            $root = true;
            continue;
        }
        if ($root !== true) {
            continue;                      // whitelabels: 之前的內容一律略過
        }
        // 頂層 entry key：恰 2-space 縮排 + key + 冒號
        if (preg_match('/^  ([A-Za-z0-9_]+):\s*(.*)$/', $raw, $m)) {
            $curKey = $m[1];
            $rest = trim($m[2]);
            if ($rest === '') {
                $whitelabels[$curKey] = [];   // block entry：後續 4-space 欄位填入
            } elseif ($rest[0] === '{') {
                // flow entry：整個 { ... } 單行
                $whitelabels[$curKey] = parse_flow_map($rest);
                $curKey = null;               // flow entry 已完整，無後續 block 欄位
            } else {
                // 頂層 entry 值非 mapping（seq/scalar）＝畸形；存起來讓 validate 判「非 mapping」
                $whitelabels[$curKey] = parse_scalar($rest);
                $curKey = null;
            }
            continue;
        }
        // block entry 的子欄位：4+ space 縮排 field: value
        if ($curKey !== null && preg_match('/^    ([A-Za-z0-9_]+):\s*(.*)$/', $raw, $m)) {
            $whitelabels[$curKey][$m[1]] = parse_scalar($m[2]);
            continue;
        }
        throw new RuntimeException("無法解析第 " . ($ln + 1) . " 行：$raw");
    }

    if ($root !== true) {
        throw new RuntimeException('registry 缺 whitelabels 根鍵');
    }
    return ['whitelabels' => $whitelabels];
}

/** 解析 flow mapping "{ k: v, k: v }"，尊重 [] 深度與 "" 引號內的逗號/冒號。 */
function parse_flow_map(string $s): array
{
    $s = trim($s);
    if ($s === '' || $s[0] !== '{' || substr($s, -1) !== '}') {
        throw new RuntimeException("flow mapping 格式錯誤：$s");
    }
    $inner = trim(substr($s, 1, -1));
    $out = [];
    if ($inner === '') {
        return $out;
    }
    foreach (split_top_level($inner) as $pair) {
        $pair = trim($pair);
        if ($pair === '') {
            continue;
        }
        $colon = find_top_level_colon($pair);
        if ($colon < 0) {
            throw new RuntimeException("flow 欄位缺冒號：$pair");
        }
        $k = trim(substr($pair, 0, $colon));
        $v = trim(substr($pair, $colon + 1));
        $out[$k] = parse_scalar($v);
    }
    return $out;
}

/** 依頂層逗號切分（跳過 [] 內與 "" 內的逗號）。 */
function split_top_level(string $s): array
{
    $parts = [];
    $depth = 0;
    $inStr = false;
    $buf = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"') {
            $inStr = !$inStr;
            $buf .= $c;
        } elseif (!$inStr && ($c === '[' || $c === '{')) {
            $depth++;
            $buf .= $c;
        } elseif (!$inStr && ($c === ']' || $c === '}')) {
            $depth--;
            $buf .= $c;
        } elseif (!$inStr && $depth === 0 && $c === ',') {
            $parts[] = $buf;
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    $parts[] = $buf;
    return $parts;
}

/** 找出頂層（非 [] / 非 "" 內）第一個冒號位置，找不到回 -1。 */
function find_top_level_colon(string $s): int
{
    $depth = 0;
    $inStr = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"') {
            $inStr = !$inStr;
        } elseif (!$inStr && ($c === '[' || $c === '{')) {
            $depth++;
        } elseif (!$inStr && ($c === ']' || $c === '}')) {
            $depth--;
        } elseif (!$inStr && $depth === 0 && $c === ':') {
            return $i;
        }
    }
    return -1;
}

/** 解析 scalar / flow-seq / quoted，型別對齊 python yaml 於此子集的行為。 */
function parse_scalar(string $v)
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    // flow sequence [a, b]
    if ($v[0] === '[') {
        if (substr($v, -1) !== ']') {
            throw new RuntimeException("flow sequence 格式錯誤：$v");
        }
        $inner = trim(substr($v, 1, -1));
        if ($inner === '') {
            return [];
        }
        $items = [];
        foreach (split_top_level($inner) as $item) {
            $items[] = parse_scalar(trim($item));
        }
        return $items;
    }
    // quoted string：保留引號內原樣（含前後空白），不 trim
    if ($v[0] === '"' && substr($v, -1) === '"' && strlen($v) >= 2) {
        return substr($v, 1, -1);
    }
    // bare token
    if ($v === 'null' || $v === '~') {
        return null;
    }
    if ($v === 'true') {
        return true;
    }
    if ($v === 'false') {
        return false;
    }
    if (preg_match('/^-?\d+$/', $v)) {
        return (int) $v;
    }
    return $v;                             // bare string（YAML 語意已 trim）
}

/** 是否為 list（連續 0-based int key）；空陣列視為 list。手寫以免踩 range(0,-1) 的坑。 */
function is_list_array($a): bool
{
    if (!is_array($a)) {
        return false;
    }
    $i = 0;
    foreach ($a as $k => $_) {
        if ($k !== $i++) {
            return false;
        }
    }
    return true;
}

/** 主驗證：回傳錯誤訊息陣列（空＝通過）。 */
function validate_registry(array $data): array
{
    $wl = $data['whitelabels'] ?? null;
    if (!is_array($wl) || $wl === []) {
        return ['whitelabels 缺失或為空'];
    }
    $errs = [];

    // 連線共用實況：同一 connectionId 出現次數 > 1 → 被多白標共用
    $connCounts = [];
    foreach ($wl as $e) {
        if (is_array($e) && isset($e['connectionId']) && is_string($e['connectionId'])) {
            $cid = $e['connectionId'];
            $connCounts[$cid] = ($connCounts[$cid] ?? 0) + 1;
        }
    }

    $seenAlias = [];                       // casefold token -> source key
    $connDbc = [];                         // connectionId -> 首見的 {key, dbcPort, database}（同 connection 一致性）
    $portOwner = [];                       // dbcPort -> 首見的 {key, cid}（反向唯一：一 port 一 connection）
    foreach ($wl as $key => $e) {
        if (!is_array($e) || (is_list_array($e) && $e !== [])) {
            // 非 mapping（非空純序列或非陣列）；空 flow map {} 視為 mapping、走缺欄位檢查
            $errs[] = "$key: 非 mapping";
            continue;
        }
        $missing = array_diff(REQUIRED, array_keys($e));
        if ($missing) {
            sort($missing);
            $errs[] = "$key: 缺欄位 [" . implode(', ', $missing) . ']';
        }
        $canonical = $e['canonical'] ?? null;
        if ($canonical !== $key) {
            $errs[] = "$key: canonical(" . fmt($canonical) . ') != key';
        }
        $env = $e['env'] ?? null;
        if (!in_array($env, ENVS, true)) {
            $errs[] = "$key: env(" . fmt($env) . ') 不在 {production, test}';
        }
        $cid = $e['connectionId'] ?? null;
        if (!(is_string($cid) && preg_match('/^[A-Za-z0-9_]+$/', $cid))) {
            $errs[] = "$key: connectionId 非 safe-token(" . fmt($cid) . ')';
        }
        $al = $e['aliases'] ?? null;
        $alValid = is_list_array($al);
        if ($alValid) {
            foreach ($al as $a) {
                if (!is_string($a) || trim($a) === '' || $a !== trim($a)) {
                    $alValid = false;
                    break;
                }
            }
        }
        if (!$alValid) {
            $errs[] = "$key: aliases 須為非空、且無前後空白的 string list";
            $al = [];
        }
        if (is_string($key) && $key !== trim($key)) {
            $errs[] = fmt($key) . ': canonical/key 不得含前後空白';
        }
        // 全域大小寫不敏感唯一：canonical(key) + 每個 alias
        $tokens = array_merge([$key], is_array($al) ? $al : []);
        foreach ($tokens as $tok) {
            if (!is_string($tok)) {
                continue;
            }
            $lo = mb_strtolower(trim($tok));
            if (isset($seenAlias[$lo]) && $seenAlias[$lo] !== $key) {
                $errs[] = "$key: 別名/名稱碰撞 " . fmt($tok) . " 已被 {$seenAlias[$lo]} 佔用";
            }
            $seenAlias[$lo] = $key;
        }
        // rootAgent 型別：production 須 int + 非空 username；test 須皆 null。
        // 正式代理 rootAgent* 與測試代理 testRootAgent* 同規則（測試代理掛正式代理下的 live 語意由 verify-registry-live.py 驗）。
        $rid = $e['rootAgentId'] ?? null;
        $ruser = $e['rootAgentUsername'] ?? null;
        $trid = $e['testRootAgentId'] ?? null;
        $truser = $e['testRootAgentUsername'] ?? null;
        if ($env === 'production') {
            if (!is_int($rid)) {           // php is_int(true)===false，天然排除 bool
                $errs[] = "$key: production rootAgentId 須為 int（得 " . fmt($rid) . '）';
            }
            if (!(is_string($ruser) && trim($ruser) !== '')) {
                $errs[] = "$key: production rootAgentUsername 須為非空 string（得 " . fmt($ruser) . '）';
            }
            if (!is_int($trid)) {
                $errs[] = "$key: production testRootAgentId 須為 int（得 " . fmt($trid) . '）';
            }
            if (!(is_string($truser) && trim($truser) !== '')) {
                $errs[] = "$key: production testRootAgentUsername 須為非空 string（得 " . fmt($truser) . '）';
            }
        } elseif ($env === 'test') {
            if ($rid !== null || $ruser !== null) {
                $errs[] = "$key: test env 的 rootAgentId/rootAgentUsername 須為 null";
            }
            if ($trid !== null || $truser !== null) {
                $errs[] = "$key: test env 的 testRootAgentId/testRootAgentUsername 須為 null";
            }
        }
        // sharesConnection 須為 bool 且與連線共用實況一致
        $sc = $e['sharesConnection'] ?? null;
        if (!is_bool($sc)) {
            $errs[] = "$key: sharesConnection 須為 bool（得 " . fmt($sc) . '）';
        } elseif (is_string($cid)) {
            $expect = ($connCounts[$cid] ?? 0) > 1;
            if ($sc !== $expect) {
                $errs[] = "$key: sharesConnection(" . fmt($sc) . ') != 連線共用實況('
                        . fmt($expect) . "；connectionId=" . fmt($cid)
                        . ' 出現 ' . ($connCounts[$cid] ?? 0) . ' 次)';
            }
        }
        // dbc 座標：dbcPort 為 int（DBC_PORT_MIN..MAX）或 null（dbc 未涵蓋、mysql-mcp.js 走舊路徑）；
        // database 為 safe-token。同 connectionId 的白標共用同一顆庫，dbcPort / database 必須一致。
        $dp = $e['dbcPort'] ?? null;
        if ($dp !== null && !(is_int($dp) && $dp >= DBC_PORT_MIN && $dp <= DBC_PORT_MAX)) {
            $errs[] = "$key: dbcPort 須為 " . DBC_PORT_MIN . '..' . DBC_PORT_MAX . ' 的 int 或 null（得 ' . fmt($dp) . '）';
        }
        if ($dp === null && array_key_exists('dbcPort', $e) && !in_array($key, DBC_NULL_ALLOWED, true)) {
            $errs[] = "$key: dbcPort: null 是降級（退回共用帳密、不進 dbc 稽核），僅允許 DBC_NULL_ALLOWED 內的白標 ["
                    . implode(', ', DBC_NULL_ALLOWED) . ']；要降級請先在 check-registry.php 登記意圖';
        }
        if (is_int($dp) && is_string($cid)) {
            if (!isset($portOwner[$dp])) {
                $portOwner[$dp] = ['key' => $key, 'cid' => $cid];
            } elseif ($portOwner[$dp]['cid'] !== $cid && !dbc_port_share_allowed($portOwner[$dp]['key'], $key)) {
                $errs[] = "$key: dbcPort $dp 已被 {$portOwner[$dp]['key']}（connectionId=" . fmt($portOwner[$dp]['cid'])
                        . '）使用；不同 connection 不得共用 port（會連錯站），除非登記於 DBC_PORT_SHARED_OK';
            }
        }
        $db = $e['database'] ?? null;
        if (!(is_string($db) && preg_match('/^[A-Za-z0-9_]+$/', $db))) {
            $errs[] = "$key: database 非 safe-token(" . fmt($db) . ')';
        }
        if (is_string($cid)) {
            if (!isset($connDbc[$cid])) {
                $connDbc[$cid] = ['key' => $key, 'dbcPort' => $dp, 'database' => $db];
            } elseif ($connDbc[$cid]['dbcPort'] !== $dp || $connDbc[$cid]['database'] !== $db) {
                $errs[] = "$key: dbcPort/database(" . fmt($dp) . ', ' . fmt($db) . ") != 同 connectionId 的 {$connDbc[$cid]['key']}("
                        . fmt($connDbc[$cid]['dbcPort']) . ', ' . fmt($connDbc[$cid]['database']) . '）';
            }
        }
    }

    // 規模閘：頂層 entry 數須等於 EXPECT_N（意圖性變更閘）
    $count = count($wl);
    if ($count !== EXPECT_N) {
        $errs[] = "registry 頂層 entry 數 $count != 預期 " . EXPECT_N . '（新增/移除白標需同步 EXPECT_N）';
    }
    return $errs;
}

/** 兩個白標 key 是否登記為允許共用 dbcPort（DBC_PORT_SHARED_OK 任一組同時含兩者）。 */
function dbc_port_share_allowed(string $a, string $b): bool
{
    foreach (DBC_PORT_SHARED_OK as $group) {
        if (in_array($a, $group, true) && in_array($b, $group, true)) {
            return true;
        }
    }
    return false;
}

/** 除錯用值格式化（對齊 python repr 的可讀性）。 */
function fmt($v): string
{
    if ($v === null) {
        return 'null';
    }
    if ($v === true) {
        return 'true';
    }
    if ($v === false) {
        return 'false';
    }
    if (is_string($v)) {
        return "'$v'";
    }
    if (is_array($v)) {
        return '[' . implode(', ', array_map('fmt', $v)) . ']';
    }
    return (string) $v;
}

// ---- 進入點 ----
$path = $argv[1] ?? (dirname(__DIR__) . '/registry/whitelabels.metadata.yaml');
$text = @file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "FAIL: 讀不到 registry 檔：$path\n");
    exit(2);
}
try {
    $data = parse_registry($text);
} catch (Throwable $ex) {
    fwrite(STDERR, 'FAIL: 解析錯誤：' . $ex->getMessage() . "\n");
    exit(2);
}
$errs = validate_registry($data);
if ($errs) {
    echo "FAIL:\n  " . implode("\n  ", $errs) . "\n";
    exit(1);
}
echo 'OK: ' . count($data['whitelabels']) . " whitelabels 通過結構+defense-in-depth 驗證\n";
exit(0);
