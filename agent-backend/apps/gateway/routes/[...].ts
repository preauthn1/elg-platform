import { defineEventHandler } from 'h3';

export default defineEventHandler(() => {
  return `
<h1>Agent Backend Gateway</h1>
<p>加密轉發代理層。前端打 /proxy/&lt;真實API路徑&gt;。</p>
<ul>
<li>POST /proxy/api/login — 登入</li>
<li>POST /proxy/logout — 登出（清除 session）</li>
<li>GET  /proxy/me — 目前登入者（未登入回 401）</li>
<li>ANY  /proxy/&lt;path&gt; — 通用轉發（agent/api/ 走新 API，其餘走舊 API）</li>
</ul>
`;
});
