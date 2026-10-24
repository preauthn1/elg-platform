#!/usr/bin/env python3
"""把 docs/reports/ 下的報告 HTML 轉成可發布為 Claude artifact 的單檔版本。

repo 的 .html 是唯一內容來源（SSOT）；artifact 版是產物，內容改動一律改 repo 版後重跑本腳本，
避免兩份漂移。轉換做三件事：

1. 內聯 assets/report.css —— artifact 的 CSP 載不到相對路徑樣式表。
2. 剝掉 <!DOCTYPE>／<html>／<head>／<body> 外殼 —— artifact 發布時會自行包上這層骨架。
3. 拆掉指向其他 repo 報告的相對連結 —— 那些檔案不會一起上 artifact，留著就是死連結。

用法：python3 docs/reports/build-artifact.py <報告.html> <輸出.html>
"""
import re
import sys
from pathlib import Path


def build(src: Path, dst: Path) -> None:
    html = src.read_text(encoding='utf-8')

    title = re.search(r'<title>(.*?)</title>', html, re.S)
    if not title:
        sys.exit(f'{src.name}: 找不到 <title>')

    body = re.search(r'<body[^>]*>(.*)</body>', html, re.S)
    if not body:
        sys.exit(f'{src.name}: 找不到 <body>')
    body_html = body.group(1)

    # head 裡的頁內 <style>（報告專屬樣式）要一併帶出
    inline_styles = re.findall(r'<style>.*?</style>', html[:body.start()], re.S)

    css_link = re.search(r'<link rel="stylesheet" href="(assets/[^"]+\.css)">', html)
    if not css_link:
        sys.exit(f'{src.name}: 找不到 assets/*.css 的 <link>')
    css_path = src.parent / css_link.group(1)
    css = css_path.read_text(encoding='utf-8')

    # 指向其他報告的相對連結在 artifact 上是死的：側欄整塊移除、內文降級為粗體文字
    body_html = re.sub(
        r'<div class="menu-label">延伸</div>\s*<ul class="menu">.*?</ul>',
        '', body_html, flags=re.S,
    )
    body_html = re.sub(
        r'<a class="xref" href="[^"]*\.html">(.*?)</a>',
        r'<b>\1</b>', body_html, flags=re.S,
    )

    fonts = '\n'.join(re.findall(r'<link[^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*>', html))

    dst.write_text(
        f'<title>{title.group(1)}</title>\n'
        f'{fonts}\n'
        f'<style>\n{css}\n</style>\n'
        + '\n'.join(inline_styles) + '\n'
        + body_html.strip() + '\n',
        encoding='utf-8',
    )


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    build(Path(sys.argv[1]), Path(sys.argv[2]))
    print(f'{sys.argv[2]}: {Path(sys.argv[2]).stat().st_size} bytes')
