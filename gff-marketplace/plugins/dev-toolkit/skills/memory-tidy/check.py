#!/usr/bin/env python3
"""memory-tidy 驗證器。於 memory 目錄下執行。

用法:
  python3 check.py                     # 現況報告
  python3 check.py snapshot            # 輸出 JSON 快照（整理前存檔用）
  python3 check.py verify BEFORE.json  # 與快照對帳（整理後跑）

檢查涵蓋：字元數與百分比、索引契約（單行長度／導航行數只在有 idx_*.md 的分層索引檢查；全檔字元一律檢查）、
兩層可達性（每支記憶檔能否從頂層經 idx_*.md 或 digest 到達）、
連結 manifest 對帳（逐筆列新增/移除，涵蓋 MEMORY.md ＋ 全部 idx_*.md）、
索引的懸空連結、記憶檔內文的 [[wikilink]] 懸空、個別記憶檔 recall cap、archive 檔雙向配對。
"""
import json
import re
import sys
from collections import Counter
from pathlib import Path

LIMIT = 25000        # Claude Code 的索引讀取上限（字元，不是 bytes）
SOFT_LIMIT = 20000   # 80%，本 skill 的整理門檻，與系統提醒觸發點一致
LINE_MAX = 150       # 索引單行上限；超過代表這行在扛不該扛的內容
NAV_MAX = 9          # 導航區行數上限；依該專案的導航設計調整；本機另裝了寫入檢查（hook 或 mod）時，與它的上限保持一致
RECALL_CAP = 4096    # 個別記憶檔的 recall 上限；超出部分在自動 recall 時看不到
LINK = re.compile(r'\]\(([A-Za-z0-9_][A-Za-z0-9_.\-]*\.md)\)')
# name slug 只含英數、底線、連字號；不收點號，[[...]] 這類省略號佔位詞才不會被當成連結
WIKILINK = re.compile(r'\[\[([A-Za-z0-9_\-]+)\]\]')
NAME_FIELD = re.compile(r'(?m)^name:[ \t]*(\S+)[ \t]*$')
GUARD_MARK = re.compile(r'(🔴|⛔|⚠️)')
# 前段出現這些導引詞，代表 recall 時已被導去讀全文，後段的護欄看不到也無妨
LEAD_HINT = re.compile(r'(接手|先讀|首節|動工前|動手前|開工前|請讀|見檔內|全在那|別翻回|已推翻|禁跑|不可|禁止)')


def wikilink_targets():
    """[[wikilink]] 指向的是對方 frontmatter 的 `name:`，未必等於檔名，且兩邊常一個用
    連字號一個用底線。回傳「正規化後的鍵 → 檔名」，檔名與 name 都收，比對前一律把
    連字號換成底線。只比對檔名會把完全正確的連結誤判成懸空。"""
    table = {}
    for p in sorted(Path('.').glob('*.md')):
        table.setdefault(p.stem.replace('-', '_'), p.name)
        m = NAME_FIELD.search(p.read_text(encoding='utf-8')[:2000])
        if m:
            table.setdefault(m.group(1).replace('-', '_'), p.name)
    return table


def resolve_wikilinks(body, table):
    """把一段內文裡的 [[wikilink]] 解析成實際檔名（解不到的略過）。"""
    return [table[m.replace('-', '_')] for m in WIKILINK.findall(body)
            if m.replace('-', '_') in table]


def index_files():
    """索引檔＝MEMORY.md ＋ 全部 idx_*.md。只看頂層會把「搬到第二層」誤判成弄丟。"""
    return ['MEMORY.md'] + sorted(p.name for p in Path('.').glob('idx_*.md'))


def scan():
    text = Path('MEMORY.md').read_text(encoding='utf-8')
    existing = {p.name for p in Path('.').glob('*.md')}

    links = []
    for name in index_files():
        links += LINK.findall(Path(name).read_text(encoding='utf-8'))
    dangling = sorted(set(links) - existing)

    # 單行長度與導航行數是分層索引的契約：清單已下放到 idx_*.md，頂層才壓得進單行。
    # 單層清單每行本來就是一筆記憶的摘要，套這兩項只會整片誤報，故只在分層時檢查。
    layered = len(index_files()) > 1

    # 契約：單行長度（全檔，不限特定區塊）
    line_over = [
        [n, len(ln), ln.strip()[:40]]
        for n, ln in enumerate(text.split('\n'), 1)
        if layered and ln.strip().startswith('- ') and len(ln) > LINE_MAX
    ]

    # 契約：導航區行數
    nav_count = None
    if layered and '## 導航' in text:
        nav_count = len([ln for ln in text.split('## 導航', 1)[1].split('\n')
                         if ln.strip().startswith('- ')])

    # 兩層可達性：記憶檔能否從頂層經 idx_*.md 或 digest 到達
    hubs = index_files() + [f for f in ('project_closed_items_digest.md',) if f in existing]
    reachable = set()
    for name in hubs:
        reachable |= set(LINK.findall(Path(name).read_text(encoding='utf-8')))
    # archive_*.md 依規約不入索引（靠主檔的 [[wikilink]] 到達），不列入可達性要求
    targets = (existing - {'MEMORY.md'}
               - {p.name for p in Path('.').glob('idx_*.md')}
               - {p.name for p in Path('.').glob('archive_*.md')})
    unreachable = sorted(targets - reachable)

    # recall 可見性：超過 RECALL_CAP 的檔，護欄有沒有落在看不到的範圍。
    # 超標本身不是問題：長設計文件不可能把所有護欄塞進前段，也不需要。要問的是
    # 「recall 到它時，前段看不看得出這裡有雷」，故分三類：
    #   A 護欄全在前段｜B 後段有護欄但前段已有警示或導引｜C 後段有護欄且前段毫無警示（要修）
    # 只看記憶檔：MEMORY.md 有自己的上限，idx_*.md 是索引、靠主動讀取不靠 recall。
    oversized, blind = [], []
    a_count = b_count = 0
    for p in Path('.').glob('*.md'):
        if p.name == 'MEMORY.md' or p.name.startswith('idx_'):
            continue
        body = p.read_text(encoding='utf-8')
        if len(body) <= RECALL_CAP:
            continue
        oversized.append([len(body), p.name])
        hidden = len(GUARD_MARK.findall(body[RECALL_CAP:]))
        if hidden == 0:
            a_count += 1
        elif GUARD_MARK.search(body[:RECALL_CAP]) or LEAD_HINT.search(body[:RECALL_CAP]):
            b_count += 1
        else:
            blind.append([hidden, len(body), p.name])
    oversized.sort(reverse=True)
    blind.sort(reverse=True)
    recall = {'guarded_head': a_count, 'hinted_head': b_count, 'blind': blind}

    table = wikilink_targets()

    # 記憶檔內文的 [[wikilink]] 懸空：連結解析不到任何檔名或 name。只列不判，
    # 教學示範連結、skill 名誤寫這類命中不是缺陷，判讀交給人（見 SKILL.md「驗證」節）
    wiki_sources = {}
    for p in sorted(Path('.').glob('*.md')):
        for m in set(WIKILINK.findall(p.read_text(encoding='utf-8'))):
            if m.replace('-', '_') not in table:
                wiki_sources.setdefault(m, []).append(p.name)
    wiki_dangling = sorted([m, files] for m, files in wiki_sources.items())

    archive_issues = []
    for arch in sorted(Path('.').glob('archive_*.md')):
        body = arch.read_text(encoding='utf-8')
        mains = [f for f in resolve_wikilinks(body, table) if not f.startswith('archive_')]
        if not mains:
            archive_issues.append(f'{arch.name}: 導言找不到存在的主檔 [[wikilink]]')
        elif not any(arch.name in resolve_wikilinks(Path(m).read_text(encoding='utf-8'), table)
                     for m in mains):
            archive_issues.append(f'{arch.name}: 主檔缺回指 {arch.name} 的 [[wikilink]]')
        if arch.name in links:
            archive_issues.append(f'{arch.name}: 出現在索引（規約不應入索引）')

    return {'chars': len(text), 'links': links, 'dangling': dangling,
            'layered': layered, 'line_over': line_over, 'nav_count': nav_count,
            'unreachable': unreachable, 'wiki_dangling': wiki_dangling,
            'oversized': oversized, 'recall': recall,
            'index_files': index_files(), 'archive_issues': archive_issues}


def report(state):
    chars = state['chars']
    print(f"MEMORY.md {chars} 字元 = 上限的 {chars / LIMIT:.0%}"
          f"（門檻 {SOFT_LIMIT}｜{'超過，該整理' if chars >= SOFT_LIMIT else '有餘裕'}）")
    print(f"索引檔 {len(state['index_files'])} 個：{'、'.join(state['index_files'])}")
    print(f"連結 {len(state['links'])} 個（去重 {len(set(state['links']))}）")

    print('--- 索引契約 ---')
    if not state['layered']:
        print(f'  ➖ 單層索引（沒有 idx_*.md）：單行 {LINE_MAX} 字元與導航行數只適用分層索引，略過')
    else:
        if state['line_over']:
            print(f'  ❌ {len(state["line_over"])} 行超過 {LINE_MAX} 字元：')
            for lineno, length, head in state['line_over']:
                print(f'     L{lineno} {length} 字元 {head}…')
        else:
            print(f'  ✅ 單行皆 ≤ {LINE_MAX} 字元')
        if state['nav_count'] is None:
            print('  ⚠️ 找不到「## 導航」區（標題名稱不同？）')
        elif state['nav_count'] > NAV_MAX:
            print(f'  ❌ 導航區 {state["nav_count"]} 行，超過 {NAV_MAX}，新主題應併進既有領域索引')
        else:
            print(f'  ✅ 導航區 {state["nav_count"]} 行（上限 {NAV_MAX}）')

    print('--- 可達性 ---')
    if state['unreachable']:
        print(f'  ❌ {len(state["unreachable"])} 支記憶檔無法從頂層兩層內到達：')
        for f in state['unreachable']:
            print(f'     {f}')
    else:
        print('  ✅ 全部記憶檔皆可從頂層經 idx_*.md 或 digest 到達')
    print(f"  懸空連結: {'、'.join(state['dangling']) if state['dangling'] else '無 ✅'}")

    print('--- 記憶檔內文 [[wikilink]] 懸空（目標集＝檔名＋frontmatter name，連字號視同底線）---')
    if state['wiki_dangling']:
        print(f'  ⚠️ {len(state["wiki_dangling"])} 個連結解析不到記憶檔，逐筆照 SKILL.md「驗證」節判讀，不是每筆都要修：')
        for link, files in state['wiki_dangling']:
            print(f'     [[{link}]] ← {"、".join(files)}')
    else:
        print('  ✅ 無')

    if state['oversized']:
        rc = state['recall']
        print(f'--- recall 可見性（超過 {RECALL_CAP} 字元的檔，超出部分自動 recall 時看不到）---')
        print(f'  {len(state["oversized"])} 支超標：護欄全在前段 {rc["guarded_head"]}'
              f'｜前段已有警示 {rc["hinted_head"]}｜前段毫無警示 {len(rc["blind"])}')
        if rc['blind']:
            print('  ❌ 前段毫無警示：recall 到它時看起來人畜無害，但後段藏著護欄：')
            for hidden, size, name in rc['blind']:
                print(f'     後段 {hidden} 個護欄標記 / 全檔 {size} 字元  {name}')
            print('  修法是在檔案最前面補一行警示並在 description 標一句，把人導向後段；')
            print('  不要為此拆檔：拆會破壞單一主題的完整性，代價大於收益。')
        else:
            print('  ✅ 超標的檔前段都有護欄或導引，recall 時看得出有雷')

    if state['archive_issues']:
        print('--- archive 配對問題 ---')
        for issue in state['archive_issues']:
            print(f'  ⚠️ {issue}')


def verify(before_path, state):
    before = json.load(open(before_path, encoding='utf-8'))
    removed = sorted((Counter(before['links']) - Counter(state['links'])).elements())
    added = sorted((Counter(state['links']) - Counter(before['links'])).elements())
    print(f"字元數 {before['chars']} → {state['chars']}（{state['chars'] / LIMIT:.0%}）")
    print(f"移除連結 {len(removed)} 個: {'、'.join(removed) if removed else '無'}")
    print(f"新增連結 {len(added)} 個: {'、'.join(added) if added else '無'}")
    if removed:
        print('⚠️ 每筆移除都必須對得上去向（digest／已外移／archive）；對不上＝弄丟了')
    else:
        print('零移除 ✅（內容只在兩層之間搬動時應為此結果）')
    print('---')


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else ''
    state = scan()
    if mode == 'snapshot':
        print(json.dumps(state, ensure_ascii=False))
        return
    if mode == 'verify':
        verify(sys.argv[2], state)
    report(state)


main()
