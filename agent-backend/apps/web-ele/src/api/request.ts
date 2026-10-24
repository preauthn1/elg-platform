/** 本專案採「伺服器端 session」：登入後 token 存在 Nitro gateway 的 session，瀏覽器只持有加密 cookie。 */
import type { RequestClientOptions } from '@vben/request';

import { preferences } from '@vben/preferences';
import {
  defaultResponseInterceptor,
  errorMessageResponseInterceptor,
  RequestClient,
} from '@vben/request';

import { ElMessage, ElMessageBox } from 'element-plus';

import { getBackendLang, tl } from '#/locales';
import { useAuthStore } from '#/store';

const apiURL = '/proxy';

/** token 錯誤碼：回應此碼時清除登入狀態並導回登入頁 */
const TOKEN_ERROR_CODE = 9998;

/**
 * 強制登出碼：後端在每次授權請求上判定此 token 已不得再使用（上級代理停用、
 * 帳號自身停用等），登入中的人要即時被踢回登入頁並看到後端給的原因。
 * 90063 來自舊 API、319 來自新 API（兩邊各自的「回到登入頁」碼）。
 */
const FORCED_LOGOUT_CODES = new Set([319, 90_063]);

/** 登入本身失敗（含被停用）由登入頁自行提示，不走強制登出 */
const LOGIN_URL = '/api/login';

/** 去抖：避免多個並發請求同時 9998 時重複導頁/洗版 */
let redirecting9998 = false;

/** 去抖：避免多個並發請求同時被踢時重複登出/彈窗 */
let forcedLoggingOut = false;

/**
 * 帳號安全設定異動後要求本人重新登入（target/tickets#4518）。
 *
 * 舊 API 在本人開啟／關閉 Google 2FA、修改自己的登入 IP 白名單成功後，回應 data 帶 relogin_required=true，
 * 且存檔當下就把本人的 token 作廢。這裡統一攔：跳「設定已更新，請重新登入」，按確定或關閉都登出並導回登入頁，
 * 各頁面不必各自處理。提示期間背景請求（通知輪詢每 10 秒一次）會拿到 token 失效回應，
 * 一律吞掉不走登出，否則提示還沒按就被導走、看不到這段文字。
 */
let reloginPrompting = false;

function isReloginRequired(body: any) {
  return (
    (Number(body?.code) === 1000 || Number(body?.code) === 200) &&
    body?.data?.relogin_required === true
  );
}

function promptRelogin(logout: () => Promise<void>) {
  if (reloginPrompting) return;
  reloginPrompting = true;
  ElMessageBox.alert(tl('security_setting_relogin'), '', {
    closeOnClickModal: false,
    confirmButtonText: tl('confirm'),
  })
    .catch(() => {})
    .finally(() => {
      logout()
        .catch(() => {})
        .finally(() => {
          reloginPrompting = false;
        });
    });
}

/** 此回應是否屬於「後端要求強制登出」（CS 與登入本身各自處理，不算） */
function isForcedLogout(code: unknown, url: string) {
  return (
    FORCED_LOGOUT_CODES.has(Number(code)) &&
    !url.startsWith('/cs') &&
    url !== LOGIN_URL
  );
}

/**
 * CS API（/cs/*）回 9998 時不走全域登出，但要讓 ensureCsLogin() 知道現有的
 * CS session 已經失效，下次呼叫要重新走一次登入流程，不能一直沿用已經
 * resolve 的快取 promise（否則永遠卡在 token error，重進頁面也不會重登）。
 */
let csTokenInvalid = false;
export function isCsTokenInvalid() {
  return csTokenInvalid;
}
export function markCsTokenValid() {
  csTokenInvalid = false;
}

function createRequestClient(baseURL: string, options?: RequestClientOptions) {
  const client = new RequestClient({
    ...options,
    baseURL,
    withCredentials: true,
  });

  /**
   * 重新認證邏輯：session 失效時導回登入頁
   */
  async function doReAuthenticate() {
    const authStore = useAuthStore();
    await authStore.logout();
  }

  // 請求頭處理：token 由 gateway 從 session 注入，前端只帶語系。
  // Accept-Language 優先用使用者在 header 選的後端語言代碼（api/languages 的 code，
  // 涵蓋 14 語系），未選過才退回前端 chrome 語系。
  client.addRequestInterceptor({
    fulfilled: async (config) => {
      config.headers['Accept-Language'] =
        getBackendLang() || example.invalid.locale;
      return config;
    },
  });

  // token 錯誤(9998) → 清除登入狀態並導回登入頁（須在 defaultResponseInterceptor
  // 之前，先檢查原始回應碼，才能同時涵蓋 responseReturn 'data' 與 'body' 兩種模式）。
  // 用 redirecting9998 去抖，避免並發請求同時 9998 時重複登出/導頁。
  client.addResponseInterceptor({
    fulfilled: (response: any) => {
      const url: string = response?.config?.url ?? '';
      const isCs = url.startsWith('/cs');
      const code = response?.data?.code;

      // 本人改了安全設定、正在等使用者按確定（#4518）：背景請求的 token 失效一律不處理
      if (
        reloginPrompting &&
        !isCs &&
        (code === TOKEN_ERROR_CODE || isForcedLogout(code, url))
      ) {
        return response;
      }
      if (isReloginRequired(response?.data)) {
        promptRelogin(doReAuthenticate);
        return response;
      }

      if (code === TOKEN_ERROR_CODE) {
        if (isCs) {
          // CS session 失效：交給 ensureCsLogin() 下次呼叫時重新登入，不導頁
          csTokenInvalid = true;
        } else if (!redirecting9998 && !forcedLoggingOut) {
          redirecting9998 = true;
          // session/token 失效 → 走登出流程（清狀態 + 導回 LOGIN_PATH），再提示後端給的訊息
          // （#4518：兩邊後端的 9998 文案已改為「登入狀態已失效，請重新登入」，被別人改了 2FA／白名單、
          // token 自然到期、被別處登入擠掉都走這裡）
          const notice: string = response?.data?.message ?? '';
          doReAuthenticate()
            .catch(() => {})
            .finally(() => {
              if (notice) {
                ElMessageBox.alert(notice, '', {
                  confirmButtonText: 'OK',
                }).catch(() => {});
              }
              // 下一輪事件循環再解鎖，吸收同批並發的 9998
              setTimeout(() => {
                redirecting9998 = false;
              }, 1000);
            });
        }
      } else if (
        isForcedLogout(code, url) &&
        !forcedLoggingOut &&
        !redirecting9998
      ) {
        forcedLoggingOut = true;
        // 先登出導回登入頁，再把後端給的原因提示一次（訊息已由後端依語系翻好）
        const notice: string = response?.data?.message ?? '';
        doReAuthenticate()
          .catch(() => {})
          .finally(() => {
            if (notice) {
              ElMessageBox.alert(notice, '', {
                confirmButtonText: 'OK',
              }).catch(() => {});
            }
            setTimeout(() => {
              forcedLoggingOut = false;
            }, 1000);
          });
      }
      return response;
    },
  });

  // 處理返回的響應數據格式：
  //  - 舊 API(api/)  成功碼為 1000
  //  - 新 API(apis, agent/api/) 成功碼為 200
  client.addResponseInterceptor(
    defaultResponseInterceptor({
      codeField: 'code',
      dataField: 'data',
      // 注意：部分舊 API 回傳的 code 是字串（如 "1000"），需轉數值比較
      successCode: (code: any) => Number(code) === 1000 || Number(code) === 200,
    }),
  );

  // 401（session 過期）導回登入頁
  client.addResponseInterceptor({
    rejected: async (error: any) => {
      if (
        error?.response?.status === 401 &&
        !error?.config?.url?.startsWith('/cs')
      ) {
        await doReAuthenticate();
      }
      throw error;
    },
  });

  // 通用錯誤處理
  client.addResponseInterceptor(
    errorMessageResponseInterceptor((msg: string, error) => {
      const responseData = error?.response?.data ?? {};
      if (
        responseData?.code === TOKEN_ERROR_CODE ||
        isForcedLogout(responseData?.code, error?.config?.url ?? '')
      ) {
        return;
      }
      const errorMessage = responseData?.message ?? responseData?.error ?? '';
      ElMessage.error(errorMessage || msg);
    }),
  );

  return client;
}

/** 全域請求逾時：預設 10 秒對報表/多表 join 端點太短，統一放寬到 60 秒 */
const REQUEST_TIMEOUT = 60_000;

export const requestClient = createRequestClient(apiURL, {
  responseReturn: 'data',
  timeout: REQUEST_TIMEOUT,
});

export const baseRequestClient = new RequestClient({
  baseURL: apiURL,
  timeout: REQUEST_TIMEOUT,
  withCredentials: true,
});
