import { describe, expect, it } from 'vitest';

import {
  calcAuditAmount,
  parseFlowLimitMultiple,
  resolveSubmitAuditAmount,
} from '../transfer-audit';

describe('parseFlowLimitMultiple', () => {
  it('倍率設 0 時回 0，不再被當成 1', () => {
    expect(parseFlowLimitMultiple('0')).toBe(0);
    expect(parseFlowLimitMultiple(0)).toBe(0);
  });

  it('有效數字照原值回傳（含小數）', () => {
    expect(parseFlowLimitMultiple('2')).toBe(2);
    expect(parseFlowLimitMultiple(1.5)).toBe(1.5);
  });

  it('非有限數字一律當 0 倍', () => {
    expect(parseFlowLimitMultiple(undefined)).toBe(0);
    expect(parseFlowLimitMultiple(null)).toBe(0);
    expect(parseFlowLimitMultiple('')).toBe(0);
    expect(parseFlowLimitMultiple('abc')).toBe(0);
    expect(parseFlowLimitMultiple(Number.NaN)).toBe(0);
    expect(parseFlowLimitMultiple(Number.POSITIVE_INFINITY)).toBe(0);
    expect(parseFlowLimitMultiple('Infinity')).toBe(0);
  });
});

describe('calcAuditAmount', () => {
  it('發紅包：金額 × 倍率', () => {
    expect(calcAuditAmount(1, 100, 0)).toBe(0);
    expect(calcAuditAmount(1, 100, 1)).toBe(100);
    expect(calcAuditAmount(1, 100, 2)).toBe(200);
  });

  it('發紅包但金額未填：回 undefined 留白', () => {
    expect(calcAuditAmount(1, undefined, 2)).toBeUndefined();
    expect(calcAuditAmount(1, null, 2)).toBeUndefined();
  });

  it('非發紅包類型：回 undefined 交客服手填', () => {
    expect(calcAuditAmount(4, 100, 2)).toBeUndefined();
    expect(calcAuditAmount(6, 100, 2)).toBeUndefined();
    expect(calcAuditAmount(2, 100, 2)).toBeUndefined();
  });
});

describe('resolveSubmitAuditAmount', () => {
  it('收紅包一律送 0', () => {
    expect(resolveSubmitAuditAmount(2, false, 999)).toBe(0);
  });

  it('多錢包一律送 0', () => {
    expect(resolveSubmitAuditAmount(1, true, 999)).toBe(0);
  });

  it('其餘類型送目前值', () => {
    expect(resolveSubmitAuditAmount(1, false, 100)).toBe(100);
    expect(resolveSubmitAuditAmount(1, false, 999)).toBe(999);
  });

  it('空值視為 0', () => {
    expect(resolveSubmitAuditAmount(4, false, undefined)).toBe(0);
    expect(resolveSubmitAuditAmount(4, false, null)).toBe(0);
  });
});
