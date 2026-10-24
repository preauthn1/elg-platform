import { describe, expect, it } from 'vitest';

import {
  decideProxyGuard,
  isAgentApiPath,
  isCanonicalProxyPath,
  isDisabledAgentLoginPath,
  isLoggedIn,
  normalizeProxyPath,
  stripQueryAndFragment,
} from '../agent-api-guard';

const LOGIN_VARIANTS = [
  '/proxy/agent/api/login',
  '/proxy/agent/api/login?x=1#f',
  '/proxy/agent/api/%6Cogin',
  '/proxy/agent/api/%256Cogin',
  '/proxy/agent//api///login',
  '/proxy/Agent/API/Login',
  String.raw`/proxy/agent\api\login`,
  '/proxy/agent%5Capi%5Clogin',
  '/proxy/agent/api/./login',
  '/proxy/agent/api/x/../login',
  '/proxy/agent/api/login/',
  '/proxy/agent/api/%6Cogin/%zz/..',
  '/proxy/agent/api/%zz/../%6Cogin',
  '/proxy/agent%2Fapi%2Flogin',
];

describe('normalizeProxyPath', () => {
  it.each(LOGIN_VARIANTS)('%s 正規化為 proxy/agent/api/login', (raw) => {
    expect(normalizeProxyPath(raw)).toBe('proxy/agent/api/login');
  });

  it('非法的百分比編碼不丟例外，回傳字串', () => {
    expect(() => normalizeProxyPath('/proxy/agent/api/%zz')).not.toThrow();
    expect(normalizeProxyPath('/proxy/agent/api/%zz')).toBe(
      'proxy/agent/api/%zz',
    );
  });

  it('proxy/api（API_Agent）路徑不受影響', () => {
    expect(normalizeProxyPath('/proxy/api/login')).toBe('proxy/api/login');
  });
});

describe('isDisabledAgentLoginPath', () => {
  it.each(LOGIN_VARIANTS)('%s 判定為已停用登入路徑', (raw) => {
    expect(isDisabledAgentLoginPath(normalizeProxyPath(raw))).toBe(true);
  });

  it.each([
    'proxy/api/login',
    'proxy/agent/api/login-history',
    'proxy/agent/api/domain-manage/type-list',
  ])('%s 不是已停用登入路徑', (normalized) => {
    expect(isDisabledAgentLoginPath(normalized)).toBe(false);
  });
});

describe('isAgentApiPath', () => {
  it('agent/api 底下的路徑為 true', () => {
    expect(isAgentApiPath('proxy/agent/api/domain-manage/type-list')).toBe(
      true,
    );
  });

  it.each([
    'proxy/api/login',
    'proxy/cs/room/list',
    'proxy/internal/api/v1/report/sr07',
    'proxy/me',
  ])('%s 不是 agent/api 路徑', (normalized) => {
    expect(isAgentApiPath(normalized)).toBe(false);
  });
});

describe('isLoggedIn', () => {
  const future = () => Math.floor(Date.now() / 1000) + 100;

  it('action=index、有 token、未過期為已登入', () => {
    expect(isLoggedIn({ action: 'index', expire: future(), token: 't' })).toBe(
      true,
    );
  });

  it.each([
    ['缺 token', { action: 'index', expire: future() }],
    ['action 為 auth', { action: 'auth', expire: future(), token: 't' }],
    [
      'expire 已過期',
      {
        action: 'index',
        expire: Math.floor(Date.now() / 1000) - 100,
        token: 't',
      },
    ],
    ['空 session', {}],
  ])('%s 為未登入', (_label, data) => {
    expect(isLoggedIn(data)).toBe(false);
  });
});

describe('isCanonicalProxyPath', () => {
  it.each([
    '/proxy/agent/api/domain-manage/type-list',
    '/proxy/agent/api/ggr/plan/list',
    '/proxy/agent/api/report_v2/sr07',
    '/proxy/Agent/API/List',
  ])('%s 為乾淨的字面路徑', (raw) => {
    expect(isCanonicalProxyPath(raw)).toBe(true);
  });

  it.each([
    'proxy/agent/api/list',
    '//proxy/agent/api/list',
    '/proxy/agent//api/list',
    '/proxy/agent/api/list/',
    '/proxy/agent/api/%6Cogin',
    String.raw`/proxy/agent\api\list`,
    '/proxy/agent/api/./list',
    '/proxy/agent/api/x/../list',
    '/proxy/agent/api/a b',
    '/proxy/agent/api/a.b',
    '',
    '/',
  ])('%s 不是乾淨的字面路徑', (raw) => {
    expect(isCanonicalProxyPath(raw)).toBe(false);
  });
});

describe('decideProxyGuard', () => {
  it.each(LOGIN_VARIANTS)('%s 擋下（已停用登入路徑）', (raw) => {
    expect(decideProxyGuard(raw).kind).toBe('block');
  });

  it.each([
    '/proxy/agent/api/login/%2F/../../login',
    '/proxy/agent/api/login/%5C/../../login',
  ])('%s 擋下（解碼與點段順序造成的繞法）', (raw) => {
    expect(decideProxyGuard(raw).kind).toBe('block');
  });

  it('多層編碼的點段跳出前綴仍以原始前綴判定並擋下', () => {
    expect(
      decideProxyGuard('/proxy/agent/api/%252e%252e/%252e%252e/x'),
    ).toEqual({ kind: 'block', reason: 'ambiguous-path' });
  });

  it.each([
    '/proxy/agent/api/domain-manage/type-list',
    '/proxy/agent/api/report/agent-dashboard/overview',
    '/proxy/agent/api/ggr/plan/list',
  ])('%s 需檢查 session', (raw) => {
    expect(decideProxyGuard(raw)).toEqual({ kind: 'agent-api' });
  });

  it.each([
    '/proxy/agent/api/domain-manage/type-list/',
    '/proxy/agent/api/domain%2Dmanage/type-list',
    '/proxy/agent/api/a b',
  ])('%s 擋下（歧義路徑）', (raw) => {
    expect(decideProxyGuard(raw)).toEqual({
      kind: 'block',
      reason: 'ambiguous-path',
    });
  });

  it.each([
    '/proxy/api/login',
    '/proxy/api/domain/info',
    '/proxy/cs/room/list',
    '/proxy/internal/api/v1/report/sr07',
    '/proxy/me',
    '/proxy/logout',
    '/health',
  ])('%s 直接放行', (raw) => {
    expect(decideProxyGuard(raw)).toEqual({ kind: 'pass' });
  });
});

describe('stripQueryAndFragment', () => {
  it.each([
    ['/proxy/agent/api/login', '/proxy/agent/api/login'],
    ['/proxy/agent/api/login?secret=[REDACTED]', '/proxy/agent/api/login'],
    ['/proxy/agent/api/login#frag', '/proxy/agent/api/login'],
  ])('%s → %s', (raw, expected) => {
    expect(stripQueryAndFragment(raw)).toBe(expected);
  });
});
