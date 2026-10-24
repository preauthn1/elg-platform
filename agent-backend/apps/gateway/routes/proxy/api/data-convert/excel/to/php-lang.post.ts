import { defineEventHandler, getQuery, readBody } from 'h3';
import { forwardToApiPlain } from '~/utils/gateway';
import { getProxySession } from '~/utils/proxy-session';

/**
 * Excel 轉多語系工具（internal-helper/excel_helper）。
 * 對應舊版 ajax.php helper_convert_excel_to_langFileFormat：
 * 此端點走「不加密」表單轉發（curlPostWithoutEncrypt），與一般 api 不同。
 */
export default defineEventHandler(async (event) => {
  const payload = ((await readBody(event)) ?? {}) as Record<string, any>;
  const session = await getProxySession(event);
  const isCheck = String(getQuery(event).isCheck ?? '0');

  return forwardToApiPlain(
    event,
    `api/data-convert/excel/to/php-lang?isCheck=${encodeURIComponent(isCheck)}`,
    payload,
    {
      actorId: session.data.id,
      token: [REDACTED],
    },
  );
});
