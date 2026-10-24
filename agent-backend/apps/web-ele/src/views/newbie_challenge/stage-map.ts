import { tl } from '#/locales';

/** 1=進行中 2=待領取彩金 3=彩金任務中 4=已完成 5=已取消（代理後台）6=已放棄 */
export const NEWBIE_STAGES = [1, 2, 3, 4, 5, 6] as const;

export function stageText(stage: null | number | string | undefined) {
  switch (Number(stage)) {
    case 1: {
      return tl('in_progress');
    }
    case 2: {
      return tl('newbie_challenge_stage_bonus_pending');
    }
    case 3: {
      return tl('newbie_challenge_stage_bonus_flow');
    }
    case 4: {
      return tl('completed');
    }
    case 5: {
      return tl('newbie_challenge_stage_operator_quit');
    }
    case 6: {
      return tl('newbie_challenge_stage_quit');
    }
    default: {
      return stage === null || stage === undefined ? '-' : String(stage);
    }
  }
}

/** 1=系統 2=會員 3=代理 */
export function operatorTypeText(type: number | undefined) {
  switch (Number(type)) {
    case 1: {
      return tl('newbie_challenge_operator_system');
    }
    case 2: {
      return tl('newbie_challenge_operator_member');
    }
    case 3: {
      return tl('newbie_challenge_operator_agent');
    }
    default: {
      return String(type ?? '');
    }
  }
}

const ACTION_KEYS: Record<string, string> = {
  bonus_claimed: 'newbie_challenge_action_bonus_claimed',
  bonus_flow_completed: 'newbie_challenge_action_bonus_flow_completed',
  challenge_completed: 'newbie_challenge_action_challenge_completed',
  join: 'newbie_challenge_action_join',
  quit: 'newbie_challenge_action_quit',
};

export function actionText(action: string | undefined) {
  const key = action ? ACTION_KEYS[action] : undefined;
  return key ? tl(key) : (action ?? '');
}

/** 1=拒絕參加 2=允許參加 3=已參加 */
export function blockStatusText(status: number | undefined) {
  switch (Number(status)) {
    case 1: {
      return tl('reject_join');
    }
    case 2: {
      return tl('allow_join');
    }
    case 3: {
      return tl('joined');
    }
    default: {
      return String(status ?? '');
    }
  }
}

export function blockStatusClass(status: number | undefined) {
  switch (Number(status)) {
    case 1: {
      return 'text-red-500';
    }
    case 2: {
      return 'text-green-600';
    }
    case 3: {
      return 'text-blue-500';
    }
    default: {
      return 'text-muted-foreground';
    }
  }
}

/** 1=阻擋重複IP 2=阻擋重複手機號 3=阻擋重複真實姓名 */
export function blockTypeText(type: number | undefined) {
  switch (Number(type)) {
    case 1: {
      return tl('block_duplicate_ip');
    }
    case 2: {
      return tl('block_duplicate_phone');
    }
    case 3: {
      return tl('block_duplicate_account');
    }
    default: {
      return String(type ?? '');
    }
  }
}
