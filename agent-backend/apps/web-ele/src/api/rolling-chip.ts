import type { Pagination } from '#/api/member';

import { requestClient } from '#/api/request';

/**
 * 禮碼（rolling chip）報表 API。 */

export interface RollingChipDashboard {
  member_rolling_chip?: null | number | string;
  member_withdrawable?: null | number | string;
  today_pay?: null | number | string;
  total_pay?: null | number | string;
  total_use?: null | number | string;
  yesterday_bet?: null | number | string;
}

/** 禮碼儀錶板 */
export async function getRollingChipDashboardApi() {
  return requestClient.post<{
    code: number | string;
    data?: RollingChipDashboard;
    message: string;
  }>('/api/rolling_chip/dashboard', {}, { responseReturn: 'body' });
}

export interface RollingChipDayRow {
  pay_rolling_chip?: number | string;
  summary_time?: string;
  use_rolling_chip?: number | string;
}

export interface RollingChipDayBody {
  code: number | string;
  data?: RollingChipDayRow[];
  message: string;
  pagination?: Pagination;
  summary?: {
    pay_rolling_chip?: number | string;
    use_rolling_chip?: number | string;
  };
}

/** 禮碼日出入報表 */
export async function getRollingChipDayReportApi(params: {
  end_time: string;
  page?: number;
  per_page?: number;
  start_time: string;
  username?: string;
}) {
  return requestClient.post<RollingChipDayBody>(
    '/api/rolling_chip/day_report',
    { page: 1, per_page: 20, username: '', ...params },
    { responseReturn: 'body' },
  );
}
