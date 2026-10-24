<?php
// plugin 內 markdown 的相對連結檢查（validate.sh 第 3b 項呼叫，在 repo 根執行）
//
// 安裝後每支 plugin 各自放在 cache/<marketplace>/<plugin>/<版本>/，repo 裡的 plugins/ 那一層不存在。
// 所以路徑中途只要退到自己 plugin 目錄的上一層就算違規，就算最後又繞回自己的 plugin 也一樣
// （例：../../op-toolkit/skills/x.md 在 repo 能用，安裝後會斷）。
//
// 抓的寫法：[x](a)、[x](<a b>)、![x](a)、參考式定義 [x]: a（可放在列表項目裡、可跨行）、HTML href="a"；
// 連結中間的空白與換行都算。只檢查含「..」路徑段的相對連結（含 ./../ 與 sub/../..），
// 其他相對連結不檢查存在與否，因為 skill 文件裡有大量 xxx.md 這類佔位寫法。
//
// 不涵蓋：檔名含括號（Markdown 要跳脫，這裡不解析跳脫）、symlink 目錄底下的檔案（不會進去掃）。
// 寫在行內程式碼或 code block 裡的範例連結也會被當成連結檢查（寧可多擋）。
//
// 結束碼：0 通過、1 有違規或讀檔失敗、2 找不到 plugins/*

$patterns = [
    '/\]\(\s*<([^>\n]*)>/',                                   // [x](<a b>)，尖括號內可含空白
    '/\]\(\s*([^\s)<>]+)/',                                    // [x](a)、![x](a)，括號後可有空白或換行
    '/^ {0,3}(?:[-*+] +)?\[[^\]\n]+\]:\s*<?([^\s>]+)/m',       // [x]: a，可在列表項目裡、可跨行
    '/href\s*=\s*["\']([^"\']+)["\']/i',                       // <a href="a">
];

$plugins = glob('plugins/*', GLOB_ONLYDIR);
if ($plugins === false || $plugins === []) {
    echo "找不到 plugins/* 目錄，無法檢查\n";
    exit(2);
}

$bad = 0;
foreach ($plugins as $plugin) {
    $root = realpath($plugin);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        $path = $file->getPathname();
        $text = file_get_contents($path);
        if ($text === false) {
            echo "讀不到檔案：$path\n";
            $bad = 1;
            continue;
        }
        // 這個 markdown 所在目錄比 plugin 根目錄深幾層
        $depth = count(array_filter(explode('/', substr(dirname($path), strlen($plugin))), 'strlen'));

        foreach ($patterns as $re) {
            preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$raw, $offset]) {
                $target = rawurldecode(explode('#', $raw, 2)[0]);
                // 網址（http:、mailto: 等）、絕對路徑、純錨點、沒有 .. 路徑段的一律略過
                if ($target === '' || $target[0] === '/' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)
                    || !preg_match('#(^|/)\.\.(/|$)#', $target)) {
                    continue;
                }
                $where = $path . ':' . (substr_count($text, "\n", 0, $offset) + 1) . ' ' . $raw;

                $d = $depth;
                $escaped = false;
                foreach (explode('/', $target) as $seg) {
                    if ($seg === '..') {
                        if (--$d < 0) {
                            $escaped = true;
                            break;
                        }
                    } elseif ($seg !== '' && $seg !== '.') {
                        $d++;
                    }
                }
                if ($escaped) {
                    echo "跑出自己的 plugin（安裝後會失效）：$where\n";
                    $bad = 1;
                    continue;
                }

                $dest = realpath(dirname($path) . '/' . $target);
                if ($dest === false) {
                    echo "目標不存在：$where\n";
                    $bad = 1;
                } elseif ($dest !== $root && strpos($dest, $root . DIRECTORY_SEPARATOR) !== 0) {
                    // 路徑本身沒退出去，但經過 symlink 指到別的 plugin
                    echo "經 symlink 指到自己的 plugin 以外：$where\n";
                    $bad = 1;
                }
            }
        }
    }
}
exit($bad);
