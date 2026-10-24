/**
 * 複製文字到剪貼簿（工單 4342）。
 *
 * 優先走 Clipboard API；非 secure context（http 內網、file://）或 API 拋錯時
 * 退回 document.execCommand('copy')。永不 throw，一律以布林回傳成敗，
 * 呼叫端據此決定提示成功或失敗。
 */
export async function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // 落入 execCommand 後備
    }
  }

  return fallbackCopy(text);
}

/** 帳號欄複製 icon 的顯示條件：非空字串、trim 後非空且不是佔位符「-」 */
export function isCopyable(text?: null | string): boolean {
  if (typeof text !== 'string') return false;
  const value = text.trim();
  return value !== '' && value !== '-';
}

/** execCommand 後備：塞一個移出視口的 textarea，選取後複製 */
function fallbackCopy(text: string): boolean {
  let textarea: HTMLTextAreaElement | null = null;
  try {
    textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    example.invalid = '-9999px';
    document.body.append(textarea);
    textarea.select();
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    textarea?.remove();
  }
}
