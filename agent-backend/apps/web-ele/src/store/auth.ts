import type { Recordable, UserInfo } from '@vben/types';

import type { AuthApi } from '#/api';
import type { LoginFlowAction } from '#/store/login-flow';

import { ref } from 'vue';
import { useRouter } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';
import { preferences } from '@vben/preferences';
import { resetAllStores, useAccessStore, useUserStore } from '@vben/stores';

import { ElMessage, ElMessageBox, ElNotification } from 'element-plus';
import { defineStore } from 'pinia';

import {
  getAccessCodesApi,
  getAgentViewApi,
  getUserInfoApi,
  loginApi,
  logoutApi,
} from '#/api';
import { resetCsState } from '#/api/cs';
import { $t } from '#/locales';
import { useForcedSetupStore } from '#/store/forced-setup';
import { useLoginFlowStore } from '#/store/login-flow';
import { resetMemberDrawerAccess } from '#/views/member/components/member-detail-drawer-state';

/** 登入流程關卡（後端尚未發 token 的二階段驗證類）→ 導向對應驗證頁 */
const TWO_FACTOR_ACTIONS = new Set([
  'auth',
  'bind_mobile',
  'm2fa_auth',
  'mobile_auth',
]);
export const LOGIN_VERIFY_PATH = '/auth/login-verify';
export const LOGIN_BIND_MOBILE_PATH = '/auth/bind-mobile';

export const useAuthStore = defineStore('auth', () => {
  const accessStore = useAccessStore();
  const userStore = useUserStore();
  const router = useRouter();

  const loginLoading = ref(false);

  /**
   * 异步处理登录操作
   * Asynchronously handle the login process
   * @param params 登录表单数据
   */
  async function authLogin(
    params: Recordable<any>,
    onSuccess?: () => Promise<void> | void,
  ) {
    try {
      loginLoading.value = true;

      const res = await loginApi({ action: 'login', ...params });
      const userInfo = await applyLoginResult(
        res,
        {
          domain: params.domain,
          password: [REDACTED],
          username: params.username,
        },
        onSuccess,
      );
      return { userInfo };
    } finally {
      loginLoading.value = false;
    }
  }

  /**
   * 二階段驗證：Google OTP（對齊舊版 two_factor_auth.php action=auth；
   * 帳密由 gateway partial session 補上，前端只送 secret）。
   */
  async function verifyGoogleSecret(secret: [REDACTED] {
    try {
      loginLoading.value = true;
      const res = await loginApi({ action: 'auth', secret });
      const userInfo = await applyLoginResult(res);
      return { userInfo };
    } finally {
      loginLoading.value = false;
    }
  }

  /**
   * 簡訊驗證/綁定手機通過後，以暫存帳密重新登入取得正式 token
   * （對齊舊版 m2fa_auth/mobile_auth/bind_mobile 的 login() 重打）。
   */
  async function resumeLogin(mobileAuth: boolean) {
    const flow = useLoginFlowStore();
    try {
      loginLoading.value = true;
      const res = await loginApi({
        action: 'login',
        domain: flow.domain,
        mobile_auth: mobileAuth,
        password: [REDACTED],
        username: flow.username,
      });
      const userInfo = await applyLoginResult(res);
      return { userInfo };
    } finally {
      loginLoading.value = false;
    }
  }

  /**
   * 統一處理 api/login 回應（首次登入與各驗證關卡共用）：
   * 非 1000 → 提示；回二階段 action → 存 login-flow 並導向驗證頁；
   * 否則完成登入（強制設定佇列、取使用者資訊/權限碼、導首頁）。
   */
  async function applyLoginResult(
    res: AuthApi.ApiResult<AuthApi.LoginData>,
    creds?: { domain?: string; password?: string; username?: string },
    onSuccess?: () => Promise<void> | void,
  ): Promise<null | UserInfo> {
    const flow = useLoginFlowStore();

    // 非成功碼
    if (res.code !== 1000) {
      if (res.code === 2042) {
        // 系統維護中；訊息由後端依 Accept-Language 翻好（error_message.2042 有 14 語系）
        await ElMessageBox.alert(
          res.message || 'System under maintenance!',
          '',
          {
            confirmButtonText: 'OK',
          },
        );
      } else {
        ElMessage.error(res.message || 'Login failed');
      }
      return null;
    }

    const data = res.data ?? {};

    // 2FA / 手機驗證類關卡：後端尚未發 token（partial session）→ 導向驗證頁。
    // 關卡間可能串接（例如 Google 驗證後要求綁定手機），一律走同一分支。
    const pendingAction =
      data.action && TWO_FACTOR_ACTIONS.has(data.action)
        ? (data.action as LoginFlowAction)
        : '';

    if (pendingAction) {
      flow.start({
        action: pendingAction,
        id: data.id,
        mobile: data.mobile,
        ...creds,
      });
      await router.replace(
        pendingAction === 'bind_mobile'
          ? LOGIN_BIND_MOBILE_PATH
          : LOGIN_VERIFY_PATH,
      );
      return null;
    }

    // 首登強制設定：後端已發 token（＝正常登入），但需先完成改密/設定支付密碼。
    // 依優先序組成佇列（先改密、後設付款密碼），寫進反應式 store（同步 localStorage）；
    // 「鎖定全螢幕、完成前不可離開」由 BasicLayout 的全域覆蓋層 ForcedSetupOverlay 實現
    // （不走路由/分頁/keep-alive，避免殘留分頁與快取頁 overlay 外洩）。
    // 註：後端旗標可能回 boolean true 或數字 1，統一 Number(x) === 1。
    const forcedQueue: string[] = [];
    if (Number(data.is_update_password_required) === 1) {
      forcedQueue.push('/agent/reset_password');
    }
    if (Number(data.is_payment_password_required) === 1) {
      forcedQueue.push('/agent/set_payment_password');
    }
    useForcedSetupStore().setQueue(forcedQueue);

    // 登入成功：採伺服器端 session，前端僅存登入旗標讓路由守衛通過
    accessStore.setAccessToken('session');
    // 沒走到登出就換帳號時的保險
    resetCsState();
    resetMemberDrawerAccess();
    flow.clear();

    const [fetchUserInfoResult, accessCodes] = await Promise.all([
      fetchUserInfo(),
      getAccessCodesApi(),
    ]);
    const userInfo = fetchUserInfoResult;
    accessStore.setAccessCodes(accessCodes);

    // 一律走正常導向（首頁）；若佇列有值，覆蓋層會自動蓋住整個畫面要求完成設定。
    if (accessStore.loginExpired) {
      accessStore.setLoginExpired(false);
    } else {
      await (onSuccess
        ? onSuccess()
        : router.push(userInfo.homePath || example.invalid.defaultHomePath));
    }

    if (userInfo?.realName) {
      ElNotification({
        message: `${$t('authentication.loginSuccessDesc')}:${userInfo?.realName}`,
        title: $t('authentication.loginSuccess'),
        type: 'success',
      });
    }

    return userInfo;
  }

  async function logout() {
    try {
      await logoutApi();
    } catch {
      // 不做任何处理
    }
    useForcedSetupStore().clear();
    resetAllStores();
    resetCsState();
    resetMemberDrawerAccess();
    accessStore.setLoginExpired(false);

    await router.replace({ path: LOGIN_PATH });
  }

  async function fetchUserInfo() {
    const userInfo = await getUserInfoApi();
    userStore.setUserInfo(userInfo);
    return userInfo;
  }

  /** /proxy/me 的 balance 只在登入時寫入，動到餘額後要自己重抓 */
  async function refreshBalance() {
    const me = userStore.userInfo as any;
    if (!me?.id) return;
    const agent = (await getAgentViewApi(me.id).catch(() => null)) as any;
    if (!agent) return;
    userStore.setUserInfo({
      ...me,
      balance: agent.balance,
      is_balance_unlimited:
        agent.is_balance_unlimited ?? me.is_balance_unlimited,
    });
  }

  function $reset() {
    loginLoading.value = false;
  }

  return {
    $reset,
    authLogin,
    fetchUserInfo,
    loginLoading,
    logout,
    refreshBalance,
    resumeLogin,
    verifyGoogleSecret,
  };
});
