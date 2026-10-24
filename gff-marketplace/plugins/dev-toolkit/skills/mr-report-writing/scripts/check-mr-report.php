<?php

declare(strict_types=1);

/**
 * MR description（MR 報告）格式驗證器。
 *
 * 用法：php check-mr-report.php <markdown 檔>（檔名寫 - 就讀 stdin）
 *       每條檢查印 ✔ 或 ✘，有任何 ✘ 就 exit 1。
 * 也可以 require 本檔後呼叫 check_mr_report(string $text): array，回傳錯誤訊息陣列，空陣列代表通過
 * （scripts/check-mr-report-test.php 就是這樣用它跑夾具）。
 *
 * 檢查的事（九節的定義見 ../SKILL.md，報告資料的欄位見 ../reference/report-data.md）：
 *   1. 九個 ## 標題齊全、順序正確、沒有多餘的 ## 標題；第一個標題之前只能有空行
 *   2. 第 1 到 8 節每節都有內容；沒有內容就要寫「無」
 *   3. 沒有留下範本佔位符（角括號包中文，例如 <一句話說明>；行內反引號裡的不算，yaml 區塊裡的照抓）
 *   4. 驗收進度不是「無」時要有表格：表頭 | 預期結果 | (規則 |) 自動 | 手動 | 備註 |，自動欄是 無 或以 ✅ 開頭，手動欄是 ⬜ 或 ✅
 *   5. 設計決定不是「無」時要有表格：表頭 | 決定 | 理由 | 放棄的做法 |，每列的「放棄的做法」非空
 *   6. 報告資料恰好一個 yaml 區塊，能用本檔的 YAML 子集解析，必填欄位齊全、允許值正確、
 *      generated.at 加引號且帶時區、status 是 draft 時 mergedAt 是 null
 *   7. 最後一個非空行是署名 🤖 Generated with [Claude Code](https://example.invalid/claude-code)
 *
 * 刻意不做：前八節與 YAML 之間的數量一致性（決定表幾列、decisions 幾項）、工單網址是否存在。
 *
 * 只用 PHP 內建功能：CI 鏡像沒有 ext-yaml，所以 YAML 由本檔的子集 parser 解析，接受的文法見 report-data.md。
 */

const MR_SECTIONS = ['主旨', '驗收進度', '變更', '設計決定', '跟原計畫不同的地方', '測試', '上線步驟', '待決與後續', '報告資料'];
const MR_SIGNATURE = '🤖 Generated with [Claude Code](https://example.invalid/claude-code)';
const MR_REQUIRED_KEYS = [
    'type', 'title', 'description', 'status', 'generated', 'sources',
    'project', 'tickets', 'commits', 'mergedAt', 'release', 'tests',
    'acceptance', 'decisions', 'planChanges', 'openItems',
];
const MR_STATUS = ['draft', 'stable', 'deprecated'];
const MR_AUTOMATED = ['covered', 'none', 'not-applicable'];
const MR_MANUAL = ['pending', 'passed', 'failed', 'not-applicable'];
const MR_TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';
const MR_DECISION_ID = '/^D-[A-Za-z0-9]+-\d+$/';

/** 主入口：回傳錯誤訊息陣列，空陣列＝通過。 */
function check_mr_report(string $text): array
{
    $errors = [];
    $lines = explode("\n", str_replace("\r\n", "\n", $text));

    // ---- 1. 標題 ----
    $headings = mr_find_headings($lines);                 // [[name, lineIndex], ...]，依出現順序
    $names = array_column($headings, 0);
    foreach (MR_SECTIONS as $s) {
        if (!in_array($s, $names, true)) {
            $errors[] = "缺少「## {$s}」";
        }
    }
    foreach ($headings as [$name, $idx]) {
        if (!in_array($name, MR_SECTIONS, true)) {
            $errors[] = '多餘的標題「## ' . $name . '」（第 ' . ($idx + 1) . ' 行）：九節以外的小節請用 ###';
        }
    }
    $present = array_values(array_filter($names, fn ($n) => in_array($n, MR_SECTIONS, true)));
    $expected = array_values(array_filter(MR_SECTIONS, fn ($s) => in_array($s, $present, true)));
    foreach ($present as $i => $name) {
        $j = array_search($name, $expected, true);
        if ($j !== $i) {
            $errors[] = '「## ' . $name . '」順序錯誤：應排在第 ' . (array_search($name, MR_SECTIONS, true) + 1) . ' 節';
        }
    }
    if ($headings !== []) {
        $first = $headings[0][1];
        for ($i = 0; $i < $first; $i++) {
            if (trim($lines[$i]) !== '') {
                $errors[] = '第 ' . ($i + 1) . ' 行在「## ' . $headings[0][0] . '」之前：第一個標題之前只能有空行';
                break;
            }
        }
    }

    // ---- 各節內容 ----
    $bodies = mr_section_bodies($lines, $headings);       // name => [lineIndex => line]

    // 2. 空節
    foreach (array_slice(MR_SECTIONS, 0, 8) as $s) {
        if (!isset($bodies[$s])) {
            continue;
        }
        if (mr_nonblank($bodies[$s]) === []) {
            $errors[] = "「## {$s}」沒有內容，沒有就寫「無」";
        }
    }

    // 3. 範本佔位符：角括號包中文。行內反引號裡的不算（`--sheet-url=<直鏈>` 這種是命令的變數寫法），
    //    fenced 區塊（報告資料的 yaml）裡的照抓，範本的 yaml 佔位符才擋得住
    $fence = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^(```|~~~)/', $line, $f)) {
            $fence = $fence === null ? $f[1] : ($fence === $f[1] ? null : $fence);
            continue;
        }
        $probe = $fence === null ? preg_replace('/`[^`\n]*`/', '', $line) : $line;
        if (preg_match('/<[^<>\n]*\p{Han}[^<>\n]*>/u', $probe, $m)) {
            $errors[] = '第 ' . ($i + 1) . ' 行還留著範本佔位符 ' . $m[0];
        }
    }

    // 4. 驗收進度表
    if (isset($bodies['驗收進度']) && !mr_is_none($bodies['驗收進度'])) {
        $table = mr_find_table($bodies['驗收進度']);
        $header = $table['header'] ?? null;
        $okHeader = $header === ['預期結果', '自動', '手動', '備註'] || $header === ['預期結果', '規則', '自動', '手動', '備註'];
        if (!$okHeader) {
            $errors[] = '「## 驗收進度」的表頭要是 | 預期結果 | 自動 | 手動 | 備註 |（動到知識庫的規則時在預期結果後加一欄 | 規則 |）';
        } else {
            $auto = array_search('自動', $header, true);
            $manual = array_search('手動', $header, true);
            foreach ($table['rows'] as $n => $cells) {
                $a = $cells[$auto] ?? '';
                $m = $cells[$manual] ?? '';
                if ($a !== '無' && !str_starts_with($a, '✅')) {
                    $errors[] = "「## 驗收進度」第 {$n} 列的自動欄要是 無 或以 ✅ 開頭（後接 feature test 檔名）";
                }
                if ($m !== '⬜' && $m !== '✅') {
                    $errors[] = "「## 驗收進度」第 {$n} 列的手動欄要是 ⬜ 或 ✅";
                }
            }
        }
    }

    // 5. 設計決定表
    if (isset($bodies['設計決定']) && !mr_is_none($bodies['設計決定'])) {
        $table = mr_find_table($bodies['設計決定']);
        if (($table['header'] ?? null) !== ['決定', '理由', '放棄的做法']) {
            $errors[] = '「## 設計決定」的表頭要是 | 決定 | 理由 | 放棄的做法 |';
        } else {
            foreach ($table['rows'] as $n => $cells) {
                if (trim($cells[2] ?? '') === '') {
                    $errors[] = "「## 設計決定」第 {$n} 列的「放棄的做法」是空的：填不出來代表它不是決定，不要列";
                }
            }
        }
    }

    // 6. 報告資料
    if (isset($bodies['報告資料'])) {
        $blocks = mr_find_yaml_blocks($bodies['報告資料']);
        if (count($blocks) !== 1) {
            $errors[] = count($blocks) === 0
                ? '「## 報告資料」要有一個 ```yaml 區塊'
                : '「## 報告資料」只能有一個 yaml 區塊，現在有 ' . count($blocks) . ' 個';
        } else {
            [$yamlLines, $base] = $blocks[0];
            try {
                [$data, $raw] = mr_yaml_parse($yamlLines, $base);
                array_push($errors, ...mr_check_report_data($data, $raw));
            } catch (RuntimeException $e) {
                $errors[] = '報告資料 YAML ' . $e->getMessage();
            }
        }
    }

    // 7. 署名
    $last = '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (trim($lines[$i]) !== '') {
            $last = trim($lines[$i]);
            break;
        }
    }
    if ($last !== MR_SIGNATURE) {
        $errors[] = '最後一行必須是署名 ' . MR_SIGNATURE . '（放在 yaml 區塊之後）';
    }

    return $errors;
}

/** 找出 fenced code 以外的 ## 標題，回傳 [[name, lineIndex], ...]。 */
function mr_find_headings(array $lines): array
{
    $out = [];
    $fence = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/^(```|~~~)/', $line, $m)) {
            if ($fence === null) {
                $fence = $m[1];
            } elseif ($fence === $m[1]) {
                $fence = null;
            }
            continue;
        }
        if ($fence === null && preg_match('/^## (.+?)\s*$/u', $line, $m)) {
            $out[] = [$m[1], $i];
        }
    }
    return $out;
}

/** 每個 ## 標題底下的內容（到下一個 ## 標題之前）。 */
function mr_section_bodies(array $lines, array $headings): array
{
    $bodies = [];
    $count = count($headings);
    foreach ($headings as $k => [$name, $start]) {
        $end = $k + 1 < $count ? $headings[$k + 1][1] : count($lines);
        $body = [];
        for ($i = $start + 1; $i < $end; $i++) {
            $body[$i] = $lines[$i];
        }
        $bodies[$name] = $body;
    }
    return $bodies;
}

function mr_nonblank(array $body): array
{
    return array_filter($body, fn ($l) => trim($l) !== '');
}

/** 該節是不是寫「無」（第一個非空行以「無」開頭，例如「無」「無，只改文件」）。 */
function mr_is_none(array $body): bool
{
    $nb = mr_nonblank($body);
    if ($nb === []) {
        return false;
    }
    return str_starts_with(trim(reset($nb)), '無');
}

/** 找該節第一張 Markdown 表格。回傳 ['header' => [...], 'rows' => [列號(從 1 起) => [...]]]，沒有表格回傳 []。 */
function mr_find_table(array $body): array
{
    $header = null;
    $rows = [];
    $n = 0;
    foreach ($body as $line) {
        $t = trim($line);
        if (!str_starts_with($t, '|')) {
            if ($header !== null) {
                break;                                 // 表格結束
            }
            continue;
        }
        $cells = mr_split_row($t);
        if ($header === null) {
            $header = $cells;
            continue;
        }
        if (preg_match('/^\|?\s*:?-+/', $t) && count(array_filter($cells, fn ($c) => !preg_match('/^:?-+:?$/', $c))) === 0) {
            continue;                                  // 分隔列 |---|---|
        }
        $rows[++$n] = $cells;
    }
    return $header === null ? [] : ['header' => $header, 'rows' => $rows];
}

/** 把 | a | b | 切成 ['a', 'b']，反引號內的 | 不切。 */
function mr_split_row(string $row): array
{
    $row = trim($row);
    $row = preg_replace('/^\|/', '', $row);
    $row = preg_replace('/\|$/', '', $row);
    $cells = [];
    $cur = '';
    $inCode = false;
    $len = strlen($row);
    for ($i = 0; $i < $len; $i++) {
        $ch = $row[$i];
        if ($ch === '`') {
            $inCode = !$inCode;
        }
        if ($ch === '|' && !$inCode) {
            $cells[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    $cells[] = trim($cur);
    return $cells;
}

/** 找該節裡的 ```yaml / ~~~yaml 區塊，回傳 [[內容行(保留原行號為 key), 區塊第一行的行號(從 0 起)], ...]。 */
function mr_find_yaml_blocks(array $body): array
{
    $blocks = [];
    $fence = null;
    $cur = [];
    $start = 0;
    foreach ($body as $i => $line) {
        if ($fence === null) {
            if (preg_match('/^(```|~~~)\s*yaml\s*$/', $line, $m)) {
                $fence = $m[1];
                $cur = [];
                $start = $i + 1;
            }
            continue;
        }
        if (preg_match('/^' . preg_quote($fence, '/') . '\s*$/', $line)) {
            $blocks[] = [$cur, $start];
            $fence = null;
            continue;
        }
        $cur[$i] = $line;
    }
    return $blocks;
}

// ---------- YAML 子集 parser ----------
// 接受：頂層 key: value；value 是純量、[flow 序列]、{ flow 對映 }，或空值後接縮排的「- 項目」區塊序列（項目是純量或 flow 對映）。
// 不接受：縮排的 block mapping、多行字串、anchor、tag、---。整行 # 是註解。
// 回傳 [資料, 每個頂層鍵的原始值文字]（原始文字用來判斷引號）。

function mr_yaml_parse(array $lines, int $base): array
{
    $data = [];
    $raw = [];
    $seqKey = null;                                        // 正在收區塊序列的鍵
    $seqCount = 0;
    foreach ($lines as $i => $line) {
        $no = $i + 1;                                      // 人看的行號（整份描述的行號）
        $line = rtrim($line);
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        if (preg_match('/^\s+-\s+(.*)$/', $line, $m)) {
            if ($seqKey === null) {
                throw new RuntimeException("第 {$no} 行無法解析：「- 項目」前面要有一個值空著的鍵（例如 decisions:）");
            }
            $item = trim($m[1]);
            if ($item === '' || $item[0] === '[') {
                throw new RuntimeException("第 {$no} 行無法解析：區塊序列的項目只能是純量或 { flow 對映 }");
            }
            $data[$seqKey][] = $item[0] === '{' ? mr_yaml_flow_map($item, $no) : mr_yaml_scalar($item, $no);
            $raw[$seqKey] .= "\n" . $item;
            $seqCount++;
            continue;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*):(?:\s+(.*))?$/', $line, $m)) {
            if ($seqKey !== null && $seqCount === 0) {
                throw new RuntimeException('第 ' . ($no - 1) . " 行之後無法解析：鍵 {$seqKey} 沒有值，沒有就寫 []");
            }
            $key = $m[1];
            if (array_key_exists($key, $data)) {
                throw new RuntimeException("第 {$no} 行無法解析：鍵 {$key} 重複");
            }
            $rest = trim($m[2] ?? '');
            $raw[$key] = $rest;
            if ($rest === '') {
                $data[$key] = [];
                $seqKey = $key;
                $seqCount = 0;
                continue;
            }
            $seqKey = null;
            $data[$key] = mr_yaml_value($rest, $no);
            continue;
        }
        if (preg_match('/^\s+\S/', $line)) {
            throw new RuntimeException("第 {$no} 行無法解析：不接受縮排的 block mapping，鍵底下只能放「- 項目」，巢狀資料請改成 flow 寫法 { k: v }");
        }
        throw new RuntimeException("第 {$no} 行無法解析：" . trim($line));
    }
    if ($seqKey !== null && $seqCount === 0) {
        throw new RuntimeException("結尾無法解析：鍵 {$seqKey} 沒有值，沒有就寫 []");
    }
    return [$data, $raw];
}

function mr_yaml_value(string $s, int $no): mixed
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if ($s[0] === '{') {
        return mr_yaml_flow_map($s, $no);
    }
    if ($s[0] === '[') {
        return mr_yaml_flow_seq($s, $no);
    }
    return mr_yaml_scalar($s, $no);
}

function mr_yaml_flow_map(string $s, int $no): array
{
    $s = trim($s);
    if ($s === '' || $s[0] !== '{' || !str_ends_with($s, '}')) {
        throw new RuntimeException("第 {$no} 行無法解析：flow 對映要寫成 { k: v, k2: v2 } 並在同一行結束");
    }
    $inner = trim(substr($s, 1, -1));
    $out = [];
    if ($inner === '') {
        return $out;
    }
    foreach (mr_yaml_split_commas($inner, $no) as $pair) {
        $pair = trim($pair);
        if ($pair === '') {
            continue;
        }
        $colon = mr_yaml_find_colon($pair);
        if ($colon < 0) {
            throw new RuntimeException("第 {$no} 行無法解析：flow 對映的欄位缺「: 」：{$pair}");
        }
        $k = trim(substr($pair, 0, $colon));
        $v = trim(substr($pair, $colon + 1));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $k)) {
            throw new RuntimeException("第 {$no} 行無法解析：flow 對映的鍵不合法：{$k}");
        }
        if ($v !== '' && $v[0] === '{') {
            throw new RuntimeException("第 {$no} 行無法解析：flow 對映裡不能再放 flow 對映（鍵 {$k}）");
        }
        $out[$k] = ($v !== '' && $v[0] === '[') ? mr_yaml_flow_seq($v, $no) : mr_yaml_scalar($v, $no);
    }
    return $out;
}

function mr_yaml_flow_seq(string $s, int $no): array
{
    $s = trim($s);
    if ($s === '' || $s[0] !== '[' || !str_ends_with($s, ']')) {
        throw new RuntimeException("第 {$no} 行無法解析：flow 序列要寫成 [a, b] 並在同一行結束");
    }
    $inner = trim(substr($s, 1, -1));
    if ($inner === '') {
        return [];
    }
    $out = [];
    foreach (mr_yaml_split_commas($inner, $no) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if ($item[0] === '[' || $item[0] === '{') {
            throw new RuntimeException("第 {$no} 行無法解析：flow 序列的項目只能是純量");
        }
        $out[] = mr_yaml_scalar($item, $no);
    }
    return $out;
}

/** 依頂層逗號切分（跳過 []、{} 內與引號內的逗號）。 */
function mr_yaml_split_commas(string $s, int $no): array
{
    $parts = [];
    $cur = '';
    $depth = 0;
    $quote = null;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($quote !== null) {
            $cur .= $ch;
            if ($ch === '\\' && $quote === '"' && $i + 1 < $len) {
                $cur .= $s[++$i];
            } elseif ($ch === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '[' || $ch === '{') {
            $depth++;
        } elseif ($ch === ']' || $ch === '}') {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $parts[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if ($quote !== null) {
        throw new RuntimeException("第 {$no} 行無法解析：引號沒有關上");
    }
    $parts[] = $cur;
    return $parts;
}

/** 找鍵值分隔的冒號：第一個不在引號、不在括號內、而且後面是空白或結尾的冒號。 */
function mr_yaml_find_colon(string $s): int
{
    $depth = 0;
    $quote = null;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($quote !== null) {
            if ($ch === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '[' || $ch === '{') {
            $depth++;
        } elseif ($ch === ']' || $ch === '}') {
            $depth--;
        } elseif ($ch === ':' && $depth === 0 && ($i + 1 === $len || $s[$i + 1] === ' ')) {
            return $i;
        }
    }
    return -1;
}

/** 純量：雙引號、單引號、null、true/false、整數，其餘是裸字串。 */
function mr_yaml_scalar(string $v, int $no): mixed
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if ($v[0] === '"') {
        if (strlen($v) < 2 || !str_ends_with($v, '"')) {
            throw new RuntimeException("第 {$no} 行無法解析：雙引號沒有關上：{$v}");
        }
        return stripcslashes(substr($v, 1, -1));
    }
    if ($v[0] === "'") {
        if (strlen($v) < 2 || !str_ends_with($v, "'")) {
            throw new RuntimeException("第 {$no} 行無法解析：單引號沒有關上：{$v}");
        }
        return str_replace("''", "'", substr($v, 1, -1));
    }
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
    if (preg_match('/(^|\s)#/', $v)) {
        throw new RuntimeException("第 {$no} 行無法解析：含「#」的值要加雙引號：{$v}");
    }
    if (preg_match('/:\s/', $v) || str_ends_with($v, ':')) {
        throw new RuntimeException("第 {$no} 行無法解析：含「: 」的值要加雙引號：{$v}");
    }
    if (preg_match('/^[\[\]{}&*!|>%@`]/', $v)) {
        throw new RuntimeException("第 {$no} 行無法解析：以 {$v[0]} 開頭的值要加雙引號：{$v}");
    }
    return $v;
}

// ---------- 報告資料的欄位檢查 ----------

function mr_check_report_data(array $d, array $raw): array
{
    $e = [];
    foreach (MR_REQUIRED_KEYS as $k) {
        if (!array_key_exists($k, $d)) {
            $e[] = "報告資料缺少欄位 {$k}";
        }
    }
    if (array_key_exists('type', $d) && $d['type'] !== 'Task Report') {
        $e[] = '報告資料的 type 要固定寫 Task Report';
    }
    foreach (['title', 'description', 'project'] as $k) {
        if (array_key_exists($k, $d) && (!is_string($d[$k]) || trim($d[$k]) === '')) {
            $e[] = "報告資料的 {$k} 要是非空字串";
        }
    }
    if (array_key_exists('status', $d) && !in_array($d['status'], MR_STATUS, true)) {
        $e[] = '報告資料的 status 只能是 draft、stable、deprecated';
    }
    if (array_key_exists('generated', $d)) {
        $g = $d['generated'];
        if (!is_array($g) || !isset($g['by']) || !array_key_exists('at', $g)) {
            $e[] = '報告資料的 generated 要寫成 { by: <工具/版本 或 human:帳號>, at: "<時間>" }';
        } else {
            if (!is_string($g['by']) || !preg_match('~^(human:\S+|[A-Za-z0-9][A-Za-z0-9.-]*/\S+)$~', $g['by'])) {
                $e[] = '報告資料的 generated.by 要寫 <工具>/<版本>（例如 claude-code/claude-opus-5-5）或 human:<GitLab 帳號>';
            }
            $quoted = preg_match('/\bat:\s*"/', $raw['generated'] ?? '') === 1;
            if (!$quoted || !is_string($g['at']) || !preg_match(MR_TIMESTAMP, $g['at'])) {
                $e[] = '報告資料的 generated.at 要加雙引號並帶時區，例如 "2026-10-19T14:00:00+08:00"';
            }
        }
    }
    if (array_key_exists('verified', $d) && ($d['status'] ?? null) === 'draft') {
        $e[] = '報告資料的 verified 不要自己填，合併時由程序補上';
    }
    foreach (['tickets', 'commits', 'planChanges', 'openItems', 'sources', 'acceptance', 'decisions'] as $k) {
        if (array_key_exists($k, $d) && !mr_is_list($d[$k])) {
            $e[] = "報告資料的 {$k} 要是序列（沒有就寫 []）";
        }
    }
    if (array_key_exists('commits', $d) && mr_is_list($d['commits']) && $d['commits'] === []) {
        $e[] = '報告資料的 commits 至少要有一個短 SHA';
    }
    if (array_key_exists('mergedAt', $d)) {
        if (($d['status'] ?? null) === 'draft' && $d['mergedAt'] !== null) {
            $e[] = '報告資料的 status 是 draft 時 mergedAt 必須是 null，合併時才由程序補上';
        } elseif ($d['mergedAt'] !== null && (!is_string($d['mergedAt']) || !preg_match(MR_TIMESTAMP, $d['mergedAt']))) {
            $e[] = '報告資料的 mergedAt 要是 null 或帶時區的時間';
        }
    }
    if (array_key_exists('release', $d) && $d['release'] !== null && (!is_string($d['release']) || $d['release'] === '')) {
        $e[] = '報告資料的 release 要是 null（還沒發版）、none（不用發版）或 release 的 tag';
    }
    if (array_key_exists('tests', $d)) {
        $t = $d['tests'];
        if (!is_array($t) || !isset($t['command']) || !is_string($t['command'])) {
            $e[] = '報告資料的 tests 要寫成 { command: "<命令>", total: n, added: n, failed: n }';
        } else {
            // 不知道的數字填 null（例如事後補寫、沒留總數），不要猜
            foreach (['total', 'added', 'failed'] as $k) {
                if (!array_key_exists($k, $t) || ($t[$k] !== null && !is_int($t[$k]))) {
                    $e[] = "報告資料的 tests.{$k} 要是整數，不知道就填 null";
                }
            }
        }
    }
    if (array_key_exists('sources', $d) && mr_is_list($d['sources'])) {
        foreach ($d['sources'] as $i => $s) {
            $n = $i + 1;
            if (!is_array($s) || !isset($s['id'], $s['resource'], $s['title'])) {
                $e[] = "報告資料的 sources 第 {$n} 項要寫成 { id: \"tickets#<編號>\", resource: \"<工單網址>\", title: \"<工單標題>\" }";
            }
        }
    }
    if (array_key_exists('acceptance', $d) && mr_is_list($d['acceptance'])) {
        foreach ($d['acceptance'] as $i => $a) {
            $n = $i + 1;
            if (!is_array($a) || !isset($a['item']) || !array_key_exists('rule', $a) || !isset($a['automated'], $a['manual'])) {
                $e[] = "報告資料的 acceptance 第 {$n} 項要寫成 { item: \"<預期結果>\", rule: null 或 \"<規則 ID>\", automated: …, manual: … }";
                continue;
            }
            if (!in_array($a['automated'], MR_AUTOMATED, true)) {
                $e[] = "報告資料的 acceptance 第 {$n} 項的 automated 只能是 covered、none、not-applicable";
            }
            if (!in_array($a['manual'], MR_MANUAL, true)) {
                $e[] = "報告資料的 acceptance 第 {$n} 項的 manual 只能是 pending、passed、failed、not-applicable";
            }
            if ($a['rule'] !== null && (!is_string($a['rule']) || trim($a['rule']) === '')) {
                $e[] = "報告資料的 acceptance 第 {$n} 項的 rule 要是 null 或規則 ID 字串";
            }
        }
    }
    if (array_key_exists('decisions', $d) && mr_is_list($d['decisions'])) {
        foreach ($d['decisions'] as $i => $dc) {
            $n = $i + 1;
            if (!is_array($dc) || !isset($dc['id'], $dc['summary'], $dc['rejected']) || !array_key_exists('supersedes', $dc)) {
                $e[] = "報告資料的 decisions 第 {$n} 項要寫成 { id: D-<工單編號>-<序號>, summary: \"…\", rejected: \"…\", supersedes: null }";
                continue;
            }
            if (!is_string($dc['id']) || !preg_match(MR_DECISION_ID, $dc['id'])) {
                $e[] = "報告資料的 decisions 第 {$n} 項的 id 要寫 D-<工單編號>-<序號>，沒工單用 D-MR-<序號>";
            }
            if (!is_string($dc['rejected']) || trim($dc['rejected']) === '') {
                $e[] = "報告資料的 decisions 第 {$n} 項的 rejected（放棄的做法）不能空";
            }
            if ($dc['supersedes'] !== null && (!is_string($dc['supersedes']) || !preg_match(MR_DECISION_ID, $dc['supersedes']))) {
                $e[] = "報告資料的 decisions 第 {$n} 項的 supersedes 要是 null 或被推翻的決定 id";
            }
        }
    }
    foreach (['planChanges', 'openItems', 'tickets', 'commits'] as $k) {
        if (array_key_exists($k, $d) && mr_is_list($d[$k])) {
            foreach ($d[$k] as $i => $v) {
                if (!is_string($v) || trim($v) === '') {
                    $e[] = "報告資料的 {$k} 第 " . ($i + 1) . ' 項要是非空字串';
                }
            }
        }
    }
    return $e;
}

function mr_is_list(mixed $v): bool
{
    return is_array($v) && array_is_list($v);
}

// ---------- CLI ----------

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $path = $argv[1] ?? null;
    if ($path === null) {
        fwrite(STDERR, "用法：php check-mr-report.php <markdown 檔>（- 讀 stdin）\n");
        exit(2);
    }
    $text = $path === '-' ? stream_get_contents(STDIN) : @file_get_contents($path);
    if ($text === false) {
        fwrite(STDERR, "讀不到檔案：{$path}\n");
        exit(2);
    }
    $errors = check_mr_report($text);
    if ($errors === []) {
        echo "  ✔ 九節結構與報告資料格式都通過\n";
        exit(0);
    }
    foreach ($errors as $msg) {
        echo "  ✘ {$msg}\n";
    }
    echo '  共 ' . count($errors) . " 處要修\n";
    exit(1);
}
