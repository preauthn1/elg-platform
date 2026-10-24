import { defineEventHandler, getRouterParam, readBody } from 'h3';
import { forwardToCsApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 客服(CS)通用轉發。 */
export default defineEventHandler(async (event) => {
  const realPath = getRouterParam(event, '_') ?? '';
  const payload = event.method === 'GET' ? {} : ((await readBody(event)) ?? {});
  const session = await getProxySession(event);

  return forwardToCsApi(event, realPath, payload as Record<string, any>, {
    actorId: session.data.csId,
    token: [REDACTED],
  });
});
