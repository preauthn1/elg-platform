@php
    /** 內部受信任資料的關鍵字強調（保留 baseline 視覺語意，確定性渲染）。 */
    $dangerNote = function (string $s): string {
        foreach (['無驗簽', '代收無失敗分支'] as $kw) {
            $s = str_replace($kw, '<b style="color:var(--danger)">'.$kw.'</b>', $s);
        }
        return $s;
    };
    $emph = function (string $s): string {
        foreach (['繞過框架', '代收無失敗分支', '失敗也回 OK', '驗簽過即成功（不讀 code）'] as $kw) {
            $s = str_replace($kw, '<b class="dis">'.$kw.'</b>', $s);
        }
        return $s;
    };
    /** 線性 SVG 圖示（stroke=currentColor，與報告中心一致，取代 emoji）。 */
    $svg = fn (string $p) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>';
@endphp
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>APIs 金流商完整盤點報告</title>
<link rel="preconnect" href="https://example.invalid">
<link rel="preconnect" href="https://example.invalid" crossorigin>
<link href="https://example.invalid/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/report.css">
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="brand"><div class="logo">{!! $svg('<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 15h4"/>') !!}</div><div class="name">Pay&nbsp;Audit</div></div>
    <div class="menu-label">報告章節</div>
    <ul class="menu">
      <li><a href="#inventory" class="active"><span class="ic">{!! $svg('<line x1="8" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="8" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>') !!}</span> 金流商清單</a></li>
      <li><a href="#whitelabel"><span class="ic">{!! $svg('<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>') !!}</span> 白標使用對照</a></li>
      <li><a href="#callback"><span class="ic">{!! $svg('<path d="M21 3v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 9"/><path d="M3 21v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 15"/>') !!}</span> 回調邏輯</a></li>
      <li><a href="#docs"><span class="ic">{!! $svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>') !!}</span> 文檔缺口</a></li>
    </ul>
    <div class="foot">report:payment-providers<br>· {{ $generatedAt }} ·</div>
  </aside>

  <div class="content">

    <div class="topbar">
      <div>
        <div class="crumb">Reports <b>/</b> Payment <b>/</b> 金流商盤點</div>
        <h1>APIs 金流商完整盤點</h1>
      </div>
      <div class="chips">
        <span class="chip">{!! $svg('<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>') !!} {{ $generatedAt }}</span>
        <span class="chip">{{ $stats['total'] }} providers · {{ count($whitelabel['columns']) }} 白標</span>
        <span class="chip">report:payment-providers</span>
      </div>
    </div>

    <div class="container">

      <div class="stats">
        <div class="stat"><div class="avatar av-primary">{!! $svg('<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>') !!}</div><div><div class="num">{{ $stats['total'] }}</div><div class="lbl">對接金流商</div></div></div>
        <div class="stat"><div class="avatar av-info">{!! $svg('<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>') !!}</div><div><div class="num">{{ $stats['withdrawal'] }}</div><div class="lbl">支援代收＋代付</div></div></div>
        <div class="stat"><div class="avatar av-secondary">{!! $svg('<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 21h16"/>') !!}</div><div><div class="num">{{ $stats['depositOnly'] }}</div><div class="lbl">代收限定</div></div></div>
        <div class="stat"><div class="avatar av-success">{!! $svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>') !!}</div><div><div class="num">{{ $stats['docHave'] }}</div><div class="lbl">已有三方文檔</div></div></div>
        <div class="stat"><div class="avatar av-danger">{!! $svg('<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>') !!}</div><div><div class="num">{{ $stats['docMissing'] }}</div><div class="lbl">缺三方文檔</div></div></div>
      </div>

      {{-- §1 --}}
      <section id="inventory" class="card">
        <div class="card-head">
          <h2><span class="idx">1</span>金流商清單（共 {{ $stats['total'] }} 家）</h2>
          <p class="desc">註冊於 <code>config/payment.php</code> 的 provider driver。代收限定（{{ $stats['depositOnly'] }} 家）：{{ implode('、', $depositOnly) }}。</p>
        </div>
        <div class="card-body">
          <div class="tbl-scroll">
            <table>
              <thead><tr>
                <th>#</th><th>Provider</th><th>config key</th><th class="center">代收</th><th class="center">代付</th><th class="center">三方文檔</th><th>備註</th>
              </tr></thead>
              <tbody>
                @foreach ($inventory as $row)
                <tr>
                  <td>{{ $row['n'] }}</td>
                  <td><b>{{ $row['name'] }}</b></td>
                  <td><span class="mono">{{ $row['key'] }}</span></td>
                  <td class="center"><span class="tick">✓</span></td>
                  <td class="center">@if ($row['withdrawal'])<span class="tick">✓</span>@else<span class="dash">—</span>@endif</td>
                  <td class="center">@if ($row['hasDoc'])<span class="badge b-success">✓</span>@else<span class="badge b-danger">缺</span>@endif</td>
                  <td>{!! $dangerNote($row['note']) !!}</td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="legend">
            <span><span class="tick">✓</span> 支援</span>
            <span><span class="dash">—</span> 不支援 / 代收限定</span>
            <span><span class="badge b-success">✓</span> 有文檔</span>
            <span><span class="badge b-danger">缺</span> 缺文檔</span>
          </div>
        </div>
      </section>

      {{-- §2 --}}
      <section id="whitelabel" class="card">
        <div class="card-head">
          <h2><span class="idx">2</span>白標站點使用對照</h2>
          <p class="desc">啟用中 = <code>agent_cash_flows.status = 1</code>；由 <code>report:payment-providers</code> 即時查各租戶 DB。WTT/Wintoto 共用 VWorld。只統計由 APIs 處理的通道（<code>CashFlowScopeEnum::isHandledByApis()</code>：<code>cash_flows.id</code> 大於 62，或在 APIs 承接名單內）；舊系統範疇的通道由 API_Agent 處理，不在本表。</p>
        </div>
        <div class="card-body">
          @if (! $whitelabel['available'])
            <div class="callout">{!! $svg('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>') !!} 白標資料本次未取得（{{ implode('；', $whitelabel['errors']) }}）。</div>
          @else
          <div class="tbl-scroll">
            <table class="matrix">
              <thead><tr>
                <th>Provider</th>
                @foreach ($whitelabel['columns'] as $col)<th class="center">{{ $col }}</th>@endforeach
              </tr></thead>
              <tbody>
                @foreach ($whitelabel['rows'] as $row)
                <tr>
                  <td><b>{{ $row['name'] }}</b></td>
                  @foreach ($row['cells'] as $cell)
                    @php
                      $d = $cell['deposit']; $w = $cell['withdrawal'];
                      if ($d === 'none' && $w === 'none') { $cellHtml = '<span class="dash">—</span>'; }
                      elseif ($w === 'none') { $cellHtml = $d === 'on' ? '代收' : '<span class="dis">代收</span>'; }
                      elseif ($d === 'none') { $cellHtml = $w === 'on' ? '代付' : '<span class="dis">代付</span>'; }
                      elseif ($d === 'on' && $w === 'on') { $cellHtml = '代收代付'; }
                      elseif ($d === 'off' && $w === 'off') { $cellHtml = '<span class="dis">代收代付</span>'; }
                      elseif ($d === 'on' && $w === 'off') { $cellHtml = '代收 / <span class="dis">代付</span>'; }
                      else { $cellHtml = '<span class="dis">代收</span> / 代付'; }
                    @endphp
                    <td class="center">{!! $cellHtml !!}</td>
                  @endforeach
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div class="legend">
            <span>代收代付 = 該方向有啟用記錄</span>
            <span><span class="dis">橙色</span> = 已配置但全部停用（acf=0）</span>
            <span><span class="dash">—</span> = 未配置</span>
          </div>
          @if (! empty($whitelabel['observations']))
          <h3>關鍵觀察</h3>
          <ul class="clean">
            @foreach ($whitelabel['observations'] as $o)<li>{!! $o !!}</li>@endforeach
          </ul>
          @endif
          @endif
        </div>
      </section>

      {{-- §3 --}}
      <section id="callback" class="card">
        <div class="card-head">
          <h2><span class="idx">3</span>回調成功/失敗標記邏輯</h2>
          <p class="desc">所有金流商繼承共用框架語意；下表為各家「明確標記成功」的觸發條件與差異（來源：payment-callback-analysis 分析）。</p>
        </div>
        <div class="card-body">
          <div class="callout">
            <b>共用框架語意</b>
            <ul class="clean">
              <li><b>代收</b>：僅 <code>status=CREATED(0)</code> 可被回調修改；終態回成功響應停重試（冪等）。金額不符 → <code>WAITING_MANUAL_DEPOSIT</code> + BotNotify；否則設 <code>SUCCESS</code> / <code>FAILED</code>。</li>
              <li><b>代付</b>：僅 <code>THIRD_PARTY_SUCCESS(24)</code> 視為已處理（冪等）。<code>THIRD_PARTY_FAILED(29)</code> 為<b>非終態</b>，允許補發成功 / 重試。金額僅成功時驗，不符只 warning。</li>
            </ul>
          </div>
          <h3>各家觸發條件總表</h3>
          <div class="tbl-scroll">
            <table>
              <thead><tr>
                <th>Provider</th><th>回應字串（成功 / 失敗）</th><th>代收「成功」觸發</th><th>代付「成功」觸發</th><th>狀態判定風格</th>
              </tr></thead>
              <tbody>
                @foreach ($callback as $row)
                <tr>
                  <td><b>{{ $row['name'] }}</b></td>
                  <td>{{ $row['response'] }}</td>
                  <td>@if ($row['depositSuccessTrigger'] === '—')<span class="dash">—</span>@else<code>{{ $row['depositSuccessTrigger'] }}</code>@endif</td>
                  <td>@if ($row['withdrawalSuccessTrigger'] === '—')<span class="dash">—</span>@else<code>{{ $row['withdrawalSuccessTrigger'] }}</code>@endif</td>
                  <td>{!! $emph(e($row['statusStyle'])) !!}</td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
      </section>

      {{-- §4 --}}
      <section id="docs" class="card">
        <div class="card-head">
          <h2><span class="idx">4</span>三方文檔缺口</h2>
          <p class="desc"><code>docs/integrations/payments/</code> 目前 {{ $stats['docHave'] }} 家有文檔，其餘 {{ $stats['docMissing'] }} 家缺。</p>
        </div>
        <div class="card-body">
          <h3 style="color:var(--success)">✓ 已有文檔（{{ $stats['docHave'] }}）</h3>
          <div class="badges">
            @foreach ($docHaves as $n)<span class="badge b-success">{{ $n }}</span>@endforeach
          </div>
          <h3 style="color:var(--danger)">✗ 缺文檔（{{ $stats['docMissing'] }}）</h3>
          <div class="badges">
            @foreach ($docMissing as $n)<span class="badge b-secondary">{{ $n }}</span>@endforeach
          </div>
        </div>
      </section>

      <div class="callout">
        {!! $svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>') !!} 各金流商的風險與待修項目不在本報告，見報告中心的「金流商風險發現」。
      </div>

      <footer style="padding:8px 2px 0; color:var(--faint); font-size:12px">
        APIs 金流商完整盤點報告 · {{ $generatedAt }} · 金流商清單、白標使用對照、文檔缺口由 report:payment-providers 即時計算；回調邏輯來自 payment-callback-analysis 分析 JSON
      </footer>

    </div>
  </div>
</div>
</body>
</html>
