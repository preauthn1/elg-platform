import { describe, expect, it } from 'vitest';

import { orderStatusChangeText } from '../order-status-change';

describe('orderStatusChangeText', () => {
  it('異動前後都有狀態文字時，組成「異動前 → 異動後」', () => {
    expect(
      orderStatusChangeText({
        orderStatusAfter: '建立',
        orderStatusBefore: '失敗',
      }),
    ).toBe('失敗 → 建立');
  });

  it('只有一邊有狀態文字時回空字串', () => {
    expect(
      orderStatusChangeText({
        orderStatusAfter: '建立',
        orderStatusBefore: '',
      }),
    ).toBe('');
  });

  it('後端沒有回這兩個欄位時回空字串', () => {
    expect(orderStatusChangeText({})).toBe('');
  });
});
