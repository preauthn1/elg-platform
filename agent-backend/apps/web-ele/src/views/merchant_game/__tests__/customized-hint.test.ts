import type { MerchantGameQuerySnapshot } from '../customized-hint';

import type { MerchantGameCustomizedItems } from '#/api/merchant-game';

import { describe, expect, it } from 'vitest';

import {
  customizedStatusLocaleKey,
  customizedStatusTagType,
  shouldShowCustomizedHint,
} from '../customized-hint';

/** 四條件全成立的查詢條件：組別與遊戲商有選，其餘四項空白 */
function baseQuery(
  patch: Partial<MerchantGameQuerySnapshot> = {},
): MerchantGameQuerySnapshot {
  return {
    bettable: '',
    game_code: '',
    group_id: 9,
    name: '',
    status: '',
    vendor_id: 3,
    ...patch,
  };
}

function baseHint(
  items: MerchantGameCustomizedItems['items'] = [
    { id: 1, item_type: 1, name: '遊戲商主頁', status: 0 },
  ],
): MerchantGameCustomizedItems {
  return {
    items,
    vendor: { id: 3, name: 'FakeGame' },
    vendor_infos: [{ id: 7, name: 'FakeGame-A', status: 1 }],
  };
}

describe('shouldShowCustomizedHint', () => {
  it('四條件全成立且 items 非空 → true', () => {
    expect(shouldShowCustomizedHint(baseQuery(), 0, baseHint())).toBe(true);
  });

  it('未選組別 → false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ group_id: '' }), 0, baseHint()),
    ).toBe(false);
  });

  it('未選遊戲商 → false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ vendor_id: '' }), 0, baseHint()),
    ).toBe(false);
  });

  it('遊戲名稱有值 → false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ name: '老虎' }), 0, baseHint()),
    ).toBe(false);
  });

  it('遊戲代碼有值 → false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ game_code: 'AB01' }), 0, baseHint()),
    ).toBe(false);
  });

  it('狀態有選（含 0）→ false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ status: 0 }), 0, baseHint()),
    ).toBe(false);
    expect(
      shouldShowCustomizedHint(baseQuery({ status: 1 }), 0, baseHint()),
    ).toBe(false);
  });

  it('可否投注有選（含 0）→ false', () => {
    expect(
      shouldShowCustomizedHint(baseQuery({ bettable: 0 }), 0, baseHint()),
    ).toBe(false);
  });

  it('總筆數不為 0 → false（含越界分頁導致當頁為空的情況）', () => {
    expect(shouldShowCustomizedHint(baseQuery(), 5, baseHint())).toBe(false);
  });

  it('後端沒回 customized_items → false', () => {
    expect(shouldShowCustomizedHint(baseQuery(), 0, null)).toBe(false);
    expect(shouldShowCustomizedHint(baseQuery(), 0, undefined)).toBe(false);
  });

  it('items 為空陣列 → false', () => {
    expect(shouldShowCustomizedHint(baseQuery(), 0, baseHint([]))).toBe(false);
  });
});

describe('版位狀態對照表', () => {
  it('0/1/2 都有語系 key 與 ElTag type', () => {
    expect(customizedStatusLocaleKey[0]).toBe('disable');
    expect(customizedStatusLocaleKey[1]).toBe('enable');
    expect(customizedStatusLocaleKey[2]).toBe('coming_soon');
    expect(customizedStatusTagType[0]).toBe('danger');
    expect(customizedStatusTagType[1]).toBe('success');
    expect(customizedStatusTagType[2]).toBe('warning');
  });
});
