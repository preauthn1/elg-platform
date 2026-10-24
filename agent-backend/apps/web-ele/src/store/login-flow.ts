import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

/**
 * 登入二階段驗證流程暫存（partial session：後端尚未核發 token）。
 * gateway session 另存 username/password 供 action=auth 驗證用；
 * 這裡只在記憶體保存本次登入輸入，重新整理即失效回登入頁。
 */
export type LoginFlowAction =
  | 'auth'
  | 'bind_mobile'
  | 'm2fa_auth'
  | 'mobile_auth';

interface LoginFlowPayload {
  action: LoginFlowAction;
  domain?: string;
  id?: number | string;
  mobile?: string;
  password?: string;
  username?: string;
}

export const useLoginFlowStore = defineStore('login-flow', () => {
  const action = ref<'' | LoginFlowAction>('');
  const domain = ref('');
  const id = ref<number | string>('');
  const mobile = ref('');
  const password = [REDACTED]'');
  const username = ref('');

  /** 手機遮罩：只留末 3 碼，前面 3 碼換成 XXX（對齊舊版 m2fa_auth/mobile_auth） */
  const maskedMobile = computed(() => {
    const m = mobile.value ?? '';
    const cut = m.length - 3;
    if (cut <= 3) return m ? `XXX${m.slice(-3)}` : '';
    return `${m.slice(0, cut - 3)}XXX${m.slice(cut)}`;
  });

  /** 進入（或切換）驗證關卡；帳密只在首次登入帶入，後續關卡沿用 */
  function start(payload: LoginFlowPayload) {
    action.value = payload.action;
    if (payload.domain !== undefined) domain.value = payload.domain;
    if (payload.id !== undefined) id.value = payload.id;
    if (payload.mobile !== undefined) mobile.value = payload.mobile;
    if (payload.password !== undefined) password.value = payload.password;
    if (payload.username !== undefined) username.value = payload.username;
  }

  function clear() {
    action.value = '';
    domain.value = '';
    id.value = '';
    mobile.value = '';
    password.value = '';
    username.value = '';
  }

  function $reset() {
    clear();
  }

  return {
    $reset,
    action,
    clear,
    domain,
    id,
    maskedMobile,
    mobile,
    password,
    start,
    username,
  };
});
