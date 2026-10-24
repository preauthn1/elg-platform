import { describe, expect, it } from 'vitest';

import {
  amountThousands,
  diffAmounts,
  floorAmount,
  sumAmounts,
} from '../format';

describe('floorAmount', () => {
  it('捨去多餘小數但不吃掉本來就在位數內的值', () => {
    expect(floorAmount('39.8000')).toBe(39.8);
    expect(floorAmount(8.2)).toBe(8.2);
    expect(floorAmount(0.29)).toBe(0.29);
    expect(floorAmount('39.799')).toBe(39.79);
    expect(floorAmount(1234.5678)).toBe(1234.56);
  });

  it('往零截斷（負值不再往下多捨一分）', () => {
    expect(floorAmount(-39.799)).toBe(-39.79);
    expect(floorAmount(-8.2)).toBe(-8.2);
  });

  it('收掉 number 帶進來的二進位雜訊', () => {
    expect(floorAmount(0.3 - 0.1)).toBe(0.2);
    expect(floorAmount(0.1 + 0.2)).toBe(0.3);
  });

  it('digits 可調', () => {
    expect(floorAmount('39.8999', 0)).toBe(39);
    expect(floorAmount('39.89999', 4)).toBe(39.8999);
  });

  it('非數值回 NaN', () => {
    expect(floorAmount('abc')).toBeNaN();
    expect(floorAmount(undefined)).toBeNaN();
  });
});

describe('amountThousands', () => {
  it('補零加千分位', () => {
    expect(amountThousands('39.8000')).toBe('39.80');
    expect(amountThousands(8.2)).toBe('8.20');
    expect(amountThousands('1234567.891')).toBe('1,234,567.89');
  });

  it('負值的千分位與符號', () => {
    expect(amountThousands('-1234567.891')).toBe('-1,234,567.89');
    expect(amountThousands(-999)).toBe('-999.00');
  });

  it('大額不因 number 失真', () => {
    expect(amountThousands('123456789012345.67')).toBe(
      '123,456,789,012,345.67',
    );
  });

  it('非數值走 fallback', () => {
    expect(amountThousands(undefined)).toBe('0.00');
    expect(amountThousands('abc', 2, '-')).toBe('-');
  });

  it('null 與空字串沿用 0 顯示', () => {
    expect(amountThousands(null)).toBe('0.00');
    expect(amountThousands('')).toBe('0.00');
  });
});

describe('sumAmounts', () => {
  it('一百筆就會掉一分', () => {
    const rows = Array.from({ length: 100 }, () => '0.29');
    expect(sumAmounts(rows)).toBe('29');
    expect(amountThousands(rows.reduce((s, v) => s + Number(v), 0))).toBe(
      '28.99',
    );
    expect(amountThousands(sumAmounts(rows))).toBe('29.00');
  });

  it('一萬筆同樣不掉一分', () => {
    expect(sumAmounts(Array.from({ length: 10_000 }, () => '0.29'))).toBe(
      '2900',
    );
  });

  it('大額加小額不被吃掉', () => {
    expect(sumAmounts(['100000000000', '0.29', '0.71'])).toBe('100000000001');
  });

  it('不預先截位', () => {
    expect(sumAmounts(['1.23456', '1'])).toBe('2.23456');
    expect(amountThousands(sumAmounts(['1.23456', '1']), 4)).toBe('2.2345');
  });

  it('略過非數值項目', () => {
    expect(sumAmounts(['1.5', null, undefined, 'abc', 2.5])).toBe('4');
  });
});

describe('diffAmounts', () => {
  it('相減不產生雜訊', () => {
    expect(diffAmounts('0.3', '0.1')).toBe('0.2');
    expect(amountThousands(diffAmounts('0.3', '0.1'))).toBe('0.20');
  });

  it('可為負', () => {
    expect(diffAmounts('1', '2.5')).toBe('-1.5');
  });

  it('非數值當 0', () => {
    expect(diffAmounts('5', null)).toBe('5');
    expect(diffAmounts(undefined, '5')).toBe('-5');
  });
});
