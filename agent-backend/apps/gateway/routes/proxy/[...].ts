import { defineEventHandler, getRouterParam, readBody } from 'h3';
import { forwardToApi } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/** 通用轉發代理。 */
export default defineEventHandler(async (event) => {
  const realPath = getRouterParam(event, '_') ?? '';
  const payload = event.method === 'GET' ? {} : ((await readBody(event)) ?? {});
  const session = await getProxySession(event);

  return forwardToApi(event, realPath, payload as Record<string, any>, {
    actorId: session.data.id,
    token: [REDACTED],
  });
});
