import {
  defineEventHandler,
  getRequestHeader,
  getRequestHeaders,
  setResponseHeader,
} from 'h3';
import { resolveClientIp } from '~/utils/gateway';

/**
 * 供 target/tickets#4187 IP 偽造測試用診斷端點，對齊 gffv3/apis 的
 * routes/shared/detect_ip.php 格式：除了 resolveClientIp() 的解析結果，
 * 同時列出各家候選 header 原始值與完整 header 清單，方便比對哪個 header
 * 被 CDN／LB 覆寫或濾掉。
 *
 * curl -H "X-Forwarded-For: 0.0.0.0" https://{host}/proxy/detect-ip
 *
 * 刻意不驗證 session：診斷 IP 解析本來就要能在登入之前測，跟 apis 那支一致
 * （沒有掛驗證 middleware）。
 */
export default defineEventHandler((event) => {
  const remoteAddr =
    event.node.req.socket?.remoteAddress?.replace('::ffff:', '') ?? '';

  const candidateHeaders: Record<string, string> = {
    'REMOTE_ADDR (直接連線 IP)': remoteAddr || '未偵測到',
    'X-Forwarded-For (完整鏈)':
      getRequestHeader(event, 'x-forwarded-for') ?? '未偵測到',
    'CF-Connecting-IP':
      getRequestHeader(event, 'cf-connecting-ip') ?? '未偵測到',
    'Ali-CDN-Real-IP': getRequestHeader(event, 'ali-cdn-real-ip') ?? '未偵測到',
    'Client-IP': getRequestHeader(event, 'client-ip') ?? '未偵測到',
    'True-Client-IP': getRequestHeader(event, 'true-client-ip') ?? '未偵測到',
    'V-HTTP-CLIENT-IP':
      getRequestHeader(event, 'v-http-client-ip') ?? '未偵測到',
    'resolveClientIp() 解析結果': resolveClientIp(event),
    TRUSTED_PROXY_SKIP_LAST_HOP:
      process.env.TRUSTED_PROXY_SKIP_LAST_HOP === 'true' ? 'true' : 'false',
    TRUSTED_PROXY_EXTRA_CIDRS: process.env.TRUSTED_PROXY_EXTRA_CIDRS || '(空)',
  };

  const separator = '='.repeat(50);
  const lines: string[] = [
    separator,
    '           核心 IP 欄位檢查 (您的測試欄位)         ',
    separator,
  ];

  for (const [label, value] of Object.entries(candidateHeaders)) {
    lines.push(`${label}: ${value}`);
  }

  lines.push(
    '',
    separator,
    '         當前請求的全部 HTTP Headers 列表          ',
    separator,
  );

  const headers = getRequestHeaders(event);
  for (const key of Object.keys(headers).toSorted()) {
    lines.push(`${key.padEnd(30)} : ${headers[key]}`);
  }

  lines.push(separator);

  setResponseHeader(event, 'Content-Type', 'text/plain; charset=utf-8');
  return lines.join('\n');
});
