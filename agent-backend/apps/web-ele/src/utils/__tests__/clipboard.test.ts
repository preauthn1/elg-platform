import { afterEach, describe, expect, it, vi } from 'vitest';

import { copyText, isCopyable } from '../clipboard';

/** 覆寫 navigator.clipboard（happy-dom 下它是唯讀屬性，要用 defineProperty） */
function stubClipboard(clipboard: unknown) {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: clipboard,
    writable: true,
  });
}

/** 覆寫 window.isSecureContext */
function stubSecureContext(secure: boolean) {
  Object.defineProperty(window, 'isSecureContext', {
    configurable: true,
    value: secure,
    writable: true,
  });
}

/** 覆寫 document.execCommand 的回傳值 */
function stubExecCommand(result: boolean) {
  const fn = vi.fn(() => result);
  Object.defineProperty(document, 'execCommand', {
    configurable: true,
    value: fn,
    writable: true,
  });
  return fn;
}

afterEach(() => {
  Reflect.deleteProperty(navigator, 'clipboard');
  Reflect.deleteProperty(window, 'isSecureContext');
  Reflect.deleteProperty(document, 'execCommand');
  vi.restoreAllMocks();
});

describe('copyText', () => {
  it('secure context 下 Clipboard API 可用時直接複製', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    stubClipboard({ writeText });
    stubSecureContext(true);

    await expect(copyText('agent001')).resolves.toBe(true);
    expect(writeText).toHaveBeenCalledWith('agent001');
  });

  it('writeText 失敗時退回 execCommand', async () => {
    const writeText = vi.fn(() => Promise.reject(new Error('denied')));
    stubClipboard({ writeText });
    stubSecureContext(true);
    const execCommand = stubExecCommand(true);

    await expect(copyText('agent001')).resolves.toBe(true);
    expect(writeText).toHaveBeenCalledTimes(1);
    expect(execCommand).toHaveBeenCalledWith('copy');
  });

  it('沒有 Clipboard API 時直接走 execCommand', async () => {
    stubClipboard(undefined);
    stubSecureContext(false);
    const execCommand = stubExecCommand(true);

    await expect(copyText('agent001')).resolves.toBe(true);
    expect(execCommand).toHaveBeenCalledWith('copy');
  });

  it('兩條路都失敗時回 false，不 throw', async () => {
    const writeText = vi.fn(() => Promise.reject(new Error('denied')));
    stubClipboard({ writeText });
    stubSecureContext(true);
    stubExecCommand(false);

    await expect(copyText('agent001')).resolves.toBe(false);
  });
});

describe('isCopyable', () => {
  it('一般帳號要顯示複製 icon', () => {
    expect(isCopyable('agent001')).toBe(true);
  });

  it.each([
    { input: '' },
    { input: '   ' },
    { input: '-' },
    { input: null },
    { input: undefined },
  ])('空值、純空白或佔位符不顯示複製 icon（$input）', ({ input }) => {
    expect(isCopyable(input)).toBe(false);
  });
});
