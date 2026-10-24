import { ref } from 'vue';

import { getAgentViewByIdApi } from '#/api';

/**
 * 「重置2FA」的反灰原因（target/tickets#4503）；回傳語系 key，空字串＝可按。
 * 判斷順序照需求表：自己那一列 → 對方未開 2FA → 操作者未開 2FA → 對方套用 admin 角色。
 * 後端 2fa/reset_agent 會再檢查一次，這裡只是提前反灰並給提示。
 */
export function reset2faBlockReason(
  target: {
    has_2fa?: number | string;
    id: number | string;
    is_admin_role?: number | string;
  },
  selfId: number,
  selfHas2fa: boolean,
): string {
  if (Number(target.id) === selfId) return 'tfa_cannot_reset_self';
  if (Number(target.has_2fa) !== 1) return 'tfa_not_enabled';
  if (!selfHas2fa) return 'tfa_operator_not_enabled';
  if (Number(target.is_admin_role) === 1) return 'tfa_admin_role_blocked';
  return '';
}

/** 操作者本人是否已開啟 2FA（關閉、重置別人的 2FA 都要驗操作者本人的驗證碼） */
export function useSelfHas2fa(selfId: number) {
  const selfHas2fa = ref(false);
  async function loadSelfHas2fa() {
    try {
      const view: any = await getAgentViewByIdApi(selfId);
      selfHas2fa.value = Number(view?.has_2fa) === 1;
    } catch {
      selfHas2fa.value = false;
    }
  }
  return { loadSelfHas2fa, selfHas2fa };
}
