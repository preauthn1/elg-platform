import type { CsMenuNode } from '#/api/cs';

import { describe, expect, it, vi } from 'vitest';

import { csWorkspaceRoute } from '../cs-menu';

// cs-menu 執行期會 import #/locales 的 tl（連帶拉 @vben/preferences、element-plus locale），這裡隔離掉
vi.mock('#/locales', () => ({
  tl: (key: string, fallback?: string) => fallback ?? key,
}));

function node(id: number, code: string, url: string): CsMenuNode {
  return { code, id, name: code, parent: 0, url };
}

function findChild(
  nodes: CsMenuNode[] | null,
  name: string,
  csRoleId: null | number = null,
) {
  const route = csWorkspaceRoute(nodes, () => false, csRoleId);
  return route.children?.find((c) => c.name === name);
}

describe('csWorkspaceRoute：紀錄查詢側欄依客服角色顯示、路由一律註冊（#4487）', () => {
  it('營運商(2)：userMenu/list 有回 transaction_log 也不顯示在側欄，但路由仍註冊', () => {
    const nodes = [
      node(1, 'chat', 'chat'),
      node(2, 'transaction_log', 'transaction/list'),
    ];
    const route = findChild(nodes, 'cs_transaction_log', 2);

    expect(route).toBeDefined();
    expect(route?.path).toBe('/cs/transaction/list');
    expect(route?.meta?.hideInMenu).toBe(true);
  });

  it.each([1, 3, 5])(
    '角色 %i：userMenu/list 沒回 transaction_log 也顯示在側欄（不看 userMenu/list）',
    (roleId) => {
      const nodes = [node(1, 'chat', 'chat')];
      const route = findChild(nodes, 'cs_transaction_log', roleId);

      expect(route).toBeDefined();
      expect(route?.meta?.hideInMenu).toBeFalsy();
    },
  );

  it('取不到角色且客服後端逾時（nodes 為 null）時路由仍註冊，但從側欄隱藏', () => {
    const route = findChild(null, 'cs_transaction_log', null);

    expect(route).toBeDefined();
    expect(route?.meta?.hideInMenu).toBe(true);
  });

  it('例外只套 transaction_log：營運商(2) 沒權限的 sr02 不註冊路由', () => {
    const nodes = [node(1, 'chat', 'chat')];

    expect(findChild(nodes, 'cs_sr02', 2)).toBeUndefined();
  });
});
