import dayjs from 'dayjs';

import { currentTzOffset, nowInUserTz } from './timezone-shift';

/**
 * 六鈕快速日期：
 * 本週=週一~週日、本月=1號~月底、上月=整月。
 * 回傳 [start, end]，格式 YYYY-MM-DD HH:mm。
 *
 * 「今天」由所選時區定義（target/tickets#4095）：預設取使用者所選時區的現在，
 * 不是瀏覽器機器時區。固定 GMT+8 的頁面（純日期死角）傳 offsetHours=8。 */
export function sixTypesQuickRange(
  kind: string,
  offsetHours?: number,
): [string, string] {
  const fmtS = (d: dayjs.Dayjs) => d.format('YYYY-MM-DD 00:00');
  const fmtE = (d: dayjs.Dayjs) => d.format('YYYY-MM-DD 23:59');
  const now = nowInUserTz(offsetHours ?? currentTzOffset());
  const monday = now.subtract((now.day() + 6) % 7, 'day');
  switch (kind) {
    case 'last_month': {
      const lm = now.subtract(1, 'month');
      return [fmtS(lm.startOf('month')), fmtE(lm.endOf('month'))];
    }
    case 'last_week': {
      return [fmtS(monday.subtract(7, 'day')), fmtE(monday.subtract(1, 'day'))];
    }
    case 'this_month': {
      return [fmtS(now.startOf('month')), fmtE(now.endOf('month'))];
    }
    case 'this_week': {
      return [fmtS(monday), fmtE(monday.add(6, 'day'))];
    }
    case 'yesterday': {
      const y = now.subtract(1, 'day');
      return [fmtS(y), fmtE(y)];
    }
    default: {
      return [fmtS(now), fmtE(now)];
    }
  }
}

/** 同 sixTypesQuickRange，但回傳日期（YYYY-MM-DD），給只收日期的報表用。 */
export function sixTypesQuickRangeDate(
  kind: string,
  offsetHours?: number,
): [string, string] {
  const [s, e] = sixTypesQuickRange(kind, offsetHours);
  return [s.slice(0, 10), e.slice(0, 10)];
}

/** 六鈕清單（label 為 legacy lang key） */
export const SIX_TYPES_KINDS = [
  'today',
  'yesterday',
  'this_week',
  'last_week',
  'this_month',
  'last_month',
] as const;
