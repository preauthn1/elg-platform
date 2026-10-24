import { tl } from '#/locales';

/** 邀請綁定狀態：0=進行中 1=已完成 2=已關閉 */
export function eventInviteStatusText(status: number | string | undefined) {
  switch (Number(status)) {
    case 0: {
      return tl('in_progress');
    }
    case 1: {
      return tl('completed');
    }
    case 2: {
      return tl('closed');
    }
    default: {
      return '-';
    }
  }
}

export function eventInviteStatusTagType(
  status: number | string | undefined,
): 'info' | 'primary' | 'success' {
  switch (Number(status)) {
    case 1: {
      return 'success';
    }
    case 2: {
      return 'info';
    }
    default: {
      return 'primary';
    }
  }
}
