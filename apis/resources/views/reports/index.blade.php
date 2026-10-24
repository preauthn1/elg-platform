@php
    /** 可檢視 / 待產出 統計。 */
    $available = collect($reports)->where('exists', true)->count();
    $missing = count($reports) - $available;

    /** 報告類型 → 線性 SVG 圖示（stroke=currentColor，由容器著色，貼近 Vuexy 線性風格）。 */
    $svg = fn (string $p) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
    $icons = [
        'card' => $svg('<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 15h4"/>'),
        'in' => $svg('<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4 21h16"/>'),
        'out' => $svg('<path d="M12 21V9"/><path d="m7 14 5-5 5 5"/><path d="M4 3h16"/>'),
        'doc' => $svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>'),
    ];
    $icon = fn (string $key) => $icons[$key] ?? $icons['doc'];
@endphp
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>APIs 報告中心</title>
<link rel="preconnect" href="https://example.invalid">
<link rel="preconnect" href="https://example.invalid" crossorigin>
<link href="https://example.invalid/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ route('reports.assets', 'report.css') }}">
<style>
/* 報告中心首頁專屬元件（報告卡列表與按鈕，其他報告不使用） */
  .grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:22px; margin:24px 0 4px}
  .rcard{background:var(--card); border:1px solid var(--border); border-radius:var(--radius-lg);
    box-shadow:var(--shadow); padding:22px; display:flex; flex-direction:column; transition:.18s}
  .rcard:hover{transform:translateY(-3px); box-shadow:0 8px 30px 0 rgba(38,51,77,.14)}
  .rcard .rc-head{display:flex; align-items:flex-start; gap:14px}
  .rcard .rc-icon{width:48px; height:48px; flex:0 0 48px; border-radius:10px; font-size:22px;
    display:flex; align-items:center; justify-content:center; background:var(--primary-soft)}
  .rcard h2{font-size:16px; margin:0; color:var(--heading); font-weight:700; line-height:1.35}
  .rcard .rc-name{font-family:"SFMono-Regular",ui-monospace,Menlo,Consolas,monospace; font-size:11px; color:var(--faint); margin-top:3px}
  .rcard .rc-desc{color:var(--text); font-size:13px; margin:14px 0 0; flex:1 1 auto}
  .rcard .rc-meta{display:flex; align-items:center; gap:8px; margin-top:14px; flex-wrap:wrap}
  .badge{display:inline-block; font-size:11px; font-weight:700; padding:4px 10px; border-radius:6px; white-space:nowrap; letter-spacing:.02em}
  .b-success{background:var(--success-soft); color:var(--success)}
  .b-secondary{background:var(--secondary-soft); color:var(--secondary)}
  .rc-time{font-size:11.5px; color:var(--faint)}
  .rc-cmd{margin-top:14px; font-family:"SFMono-Regular",ui-monospace,Menlo,Consolas,monospace; font-size:11.5px;
    background:#F3F2F7; color:var(--primary); padding:8px 11px; border-radius:6px; overflow-x:auto; white-space:nowrap}
  .rc-cmd.warn{color:var(--warning)}
  .rc-foot{margin-top:18px}
  .btn{display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; font-size:13px;
    font-weight:600; transition:.15s; cursor:pointer; border:none}
  .btn-primary{background:linear-gradient(118deg,var(--primary),rgba(90,141,238,.85)); color:#fff;
    box-shadow:0 4px 12px rgba(115,103,240,.4)}
  .btn-primary:hover{box-shadow:0 6px 18px rgba(90,141,238,.55); transform:translateY(-1px)}
  .btn-ghost{background:var(--secondary-soft); color:var(--secondary); cursor:not-allowed}
  .brand .logo svg{width:19px; height:19px; color:#fff}
  .menu a .ic svg{width:17px; height:17px; display:block; margin:0 auto}
  .stat .avatar svg{width:23px; height:23px}
  .rc-icon{color:var(--primary)}
  .rc-icon svg{width:25px; height:25px}
</style>
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="brand"><div class="logo">{!! $svg('<line x1="6" y1="20" x2="6" y2="13"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="18" y1="20" x2="18" y2="9"/>') !!}</div><div class="name">Reports</div></div>
    <div class="menu-label">報告清單</div>
    <ul class="menu">
      @foreach ($reports as $r)
        <li>
          <a href="{{ route('reports.show', $r['name']) }}" class="{{ $r['exists'] ? '' : 'disabled' }}">
            <span class="ic">{!! $icon($r['icon']) !!}</span> {{ $r['title'] }}
          </a>
        </li>
      @endforeach
    </ul>
    <div class="foot">內部報告中心<br>· LocalDebugOnly ·</div>
  </aside>

  <div class="content">

    <div class="topbar">
      <div>
        <div class="crumb">Reports <b>/</b> 索引</div>
        <h1>APIs 報告中心</h1>
      </div>
      <div class="chips">
        <span class="chip">{{ count($reports) }} 份報告</span>
        <span class="chip">可檢視 {{ $available }}</span>
      </div>
    </div>

    <div class="container">

      <div class="stats">
        <div class="stat"><div class="avatar av-primary">{!! $svg('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M15 2v5h5"/>') !!}</div><div><div class="num">{{ count($reports) }}</div><div class="lbl">報告總數</div></div></div>
        <div class="stat"><div class="avatar av-success">{!! $svg('<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>') !!}</div><div><div class="num">{{ $available }}</div><div class="lbl">可檢視</div></div></div>
        <div class="stat"><div class="avatar av-warning">{!! $svg('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>') !!}</div><div><div class="num">{{ $missing }}</div><div class="lbl">待產出</div></div></div>
      </div>

      <div class="grid">
        @foreach ($reports as $r)
          <div class="rcard">
            <div class="rc-head">
              <div class="rc-icon">{!! $icon($r['icon']) !!}</div>
              <div>
                <h2>{{ $r['title'] }}</h2>
                <div class="rc-name">{{ $r['name'] }}.html</div>
              </div>
            </div>
            <p class="rc-desc">{{ $r['desc'] }}</p>
            <div class="rc-meta">
              @if ($r['exists'])
                <span class="badge b-success">可檢視</span>
                <span class="rc-time">更新於 {{ $r['mtime'] }}</span>
              @else
                <span class="badge b-secondary">待產出</span>
              @endif
            </div>
            @if ($r['command'])
              <div class="rc-cmd">{{ str_starts_with($r['command'], '/') ? $r['command'] : '$ ' . $r['command'] }}</div>
            @else
              <div class="rc-cmd warn"># 產出方式未登記</div>
            @endif
            <div class="rc-foot">
              @if ($r['exists'])
                <a class="btn btn-primary" href="{{ route('reports.show', $r['name']) }}" target="_blank">開啟報告 →</a>
              @else
                <button class="btn btn-ghost" disabled>尚未產出</button>
              @endif
            </div>
          </div>
        @endforeach
      </div>

    </div>
  </div>
</div>
</body>
</html>
