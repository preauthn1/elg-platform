import { tl } from '#/locales';

type TagType = 'danger' | 'info' | 'primary' | 'success' | 'warning';

/** user_joins.status 對照 */
export const JOIN_USER_STATUS: Record<number, { key: string; type: TagType }> =
  {
    0: { key: 'in_progress', type: 'primary' },
    1: { key: 'event_progressive_jackpot_status_waiting_unlock', type: 'info' },
    2: { key: 'completed', type: 'success' },
    3: { key: 'event_progressive_jackpot_status_expired', type: 'warning' },
    4: { key: 'event_progressive_jackpot_status_locked', type: 'danger' },
    5: { key: 'closed', type: 'info' },
  };

export function joinUserStatusLabel(status?: number | string) {
  const key = JOIN_USER_STATUS[Number(status)]?.key;
  return key ? tl(key) : '-';
}

export function joinUserStatusType(status?: number | string): TagType {
  return JOIN_USER_STATUS[Number(status)]?.type ?? 'info';
}

/** 檔位名稱：level 0 為首儲，其餘顯示 Lv1、Lv2... */
export function tierLabel(level?: number | string) {
  return Number(level) === 0
    ? tl('event_progressive_jackpot_first_deposit_tier')
    : `Lv${Number(level)}`;
}

/** event_log.type 對照（1=解鎖彩金, 2=領取彩金, 3=修改狀態） */
export function eventLogTypeLabel(type?: number | string) {
  switch (Number(type)) {
    case 1: {
      return `${tl('event_progressive_jackpot_unlock')}${tl('jackpot')}`;
    }
    case 2: {
      return tl('event_progressive_jackpot_receive_jackpot');
    }
    case 3: {
      return `${tl('modify')}${tl('status')}`;
    }
    default: {
      return String(type ?? '');
    }
  }
}
