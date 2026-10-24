/**
 * apis 回應碼（frontend/api/*）。
 *
 * **後端同一件事常常有兩個碼**：一個是 `HttpCode` 的 3xx 系列（中介層與 controller 直接回的），
 * 一個是 `error_message.*` 的 9xxx／4 位數系列。舊前台 `C10/Page/Modal.vue` 的處理表就是
 * 成對列的（`[319, 9998]`、`[324, 9007]`、`[322, 9006]`…），搬過來時要兩個都收，
 * 只認其中一個會漏掉實際會發生的那一半。
 */
export const API_CODE = {
  OK: 200,
  /** 代理維護中：導 /maintain */
  MAINTENANCE: 317,

  /**
   * 登出並導回登入頁。**這一組一定要兩個碼都認**：
   *   319  HttpCode::HTTP_REDIRECT_LOGOUT —— `AuthMember` 中介層 token 失效時回的就是這個，
   *        但它帶的訊息是 `error_message.9998`（「Token錯誤」），所以畫面上看到 9998 的字、
   *        實際 code 卻是 319。只比對 9998 會完全不生效。
   *   9998 error_message.9998 本身，其他地方直接用這個碼。
   */
  RELOGIN: 9998,
  RELOGIN_LOGOUT: 319,

  /** 需重新整理整頁 */
  RELOAD: 423,

  /** 需先重設密碼：關掉彈窗後導 /forget-password（舊前台是 /retrieve-password，見 profileRedirectFor） */
  RESET_PASSWORD_REQUIRED: 9006,
  RESET_PASSWORD_REQUIRED_ALT: 322,
  // 其餘「關掉彈窗後導頁」的碼（324／9007 綁手機、326／9008 綁信箱…）全部在 shared/utils/member.ts 的 profileRedirectFor
} as const

/** 是否代表「清掉登入狀態並回登入頁」。319 與 9998 是同一件事，見上面的說明。 */
export function isReloginCode(code: number): boolean {
  return code === API_CODE.RELOGIN || code === API_CODE.RELOGIN_LOGOUT
}

/** 是否代表「需先重設密碼」。 */
export function isResetPasswordCode(code: number): boolean {
  return code === API_CODE.RESET_PASSWORD_REQUIRED || code === API_CODE.RESET_PASSWORD_REQUIRED_ALT
}

/** BFF 自己產生的碼（不會與 apis 撞號）。 */
export const BFF_CODE = {
  UNKNOWN_ENDPOINT: 404,
  INVALID_REQUEST: 422,
  UPSTREAM_FAILED: -1,
  NOT_CONFIGURED: -2,
} as const

export type ApiCode = (typeof API_CODE)[keyof typeof API_CODE]
