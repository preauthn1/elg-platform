import { defineEventHandler, readBody } from 'h3';
import { forwardToApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 沒指定時的回傳上限 */
const DEFAULT_LIMIT = 100;

interface AgentOption {
  id: number;
  parent: number;
  username: string;
}

/**
 * 依關鍵字相關度排序：完全相同 → 開頭符合 → 中間符合。
 * 後端沒有排序也不截斷，直接切前 N 筆會把完整帳號切掉，故先排再切。
 */
function rankByKeyword(list: AgentOption[], keyword: string): AgentOption[] {
  const kw = keyword.trim().toLowerCase();
  if (!kw) return list;

  const rank = (name: string) => {
    const at = name.toLowerCase().indexOf(kw);
    if (at === -1) return 3;
    if (name.length === kw.length) return 0;
    return at === 0 ? 1 : 2;
  };

  return list.toSorted((a, b) => {
    const byRank = rank(a.username) - rank(b.username);
    if (byRank !== 0) return byRank;
    const byLength = a.username.length - b.username.length;
    return byLength === 0 ? a.username.localeCompare(b.username) : byLength;
  });
}

/**
 * 代理下拉轉發。
 *
 * 這支後端沒有 LIMIT，代理多的站台會一次回上千筆讓下拉卡住；在轉發層截斷，
 * 不動後端也不影響仍在使用同一支 API 的舊後台。
 */
export default defineEventHandler(async (event) => {
  const incoming = ((await readBody(event)) ?? {}) as Record<string, any>;
  const { limit, ...payload } = incoming;
  const session = await getProxySession(event);

  const result = await forwardToApi<AgentOption[]>(
    event,
    'api/agent/newDropdownList',
    payload,
    { actorId: session.data.id, token: [REDACTED] },
  );

  if (!Array.isArray(result.data)) return result;

  const max = Number(limit) > 0 ? Number(limit) : DEFAULT_LIMIT;
  const ranked = rankByKeyword(result.data, String(payload.username ?? ''));

  return { ...result, data: ranked.slice(0, max) };
});
