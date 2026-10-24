import { createRequire } from 'node:module'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { authEndpoints, emptyDataSchema } from '../../shared/api/endpoints/auth'
import {
  AUTH_CODE,
  buildTelegramBotUrl,
  buildTelegramOAuthUrl,
  detectPlatformName,
  groupRegisterFields,
  isAlphaNumeric,
  isLoginOk,
  isMemberLoginData,
  needsInviteCode,
  normalizeMobile,
  normalizeOtp,
  parseMemberFieldSettings,
  parseTelegramAuthMessage,
  parseTelegramCallbackQuery,
  resolveCheckCodeOutcome,
  resolveCheckCodeRoute,
  resolveInvitationCodeState,
  resolveLoginOutcome,
  resolveRedirectPath,
  resolveRegisterErrorField,
  resolveRegisterMode,
  resolveSendCodeScope,
  resolveTwoFactorOptions,
  telegramLoginRoute,
  validateUsername,
} from '../../shared/utils/auth'
import { isApiOk } from '../../app/composables/useApi'
import { useAuthStore } from '../../app/stores/auth'
import { useAuth } from '../../app/composables/useAuth'
import { useGuestValidationCode } from '../../app/composables/useGuestValidationCode'
import { MEMBER_PATH } from '../../shared/utils/member'

/**
 * pnpm 沒把 vue hoist 到根 node_modules，測試直接 import 'vue' 會找不到；
 * 從 pinia 的真實路徑借同一份 vue（setup store 的 ref 必須與 pinia 同一份），當成 Nuxt 自動匯入的 ref / computed。
 */
interface VueLite {
  ref: <T>(value: T) => { value: T }
  computed: <T>(getter: () => T) => { readonly value: T }
}
const nodeRequire = createRequire(import.meta.url)
const { ref, computed } = nodeRequire(nodeRequire.resolve('vue', { paths: [nodeRequire.resolve('pinia')] })) as VueLite

const ok = <T>(data: T) => ({ code: 200, message: 'ok', data })
const err = (code: number, message = 'err', data: unknown = []) => ({ code, message, data })

/* ------------------------------------------------------------------ 端點契約 */
describe('auth 端點契約', () => {
  it('guest/login request：帳密必填、do_2fa 只收三種', () => {
    const req = authEndpoints['guest/login'].request
    expect(req.safeParse({ username: 'a', password: 'b' }).success).toBe(true)
    expect(req.safeParse({ username: 'a' }).success).toBe(false)
    expect(req.safeParse({ username: 'a', password: 'b', do_2fa: 'sms' }).success).toBe(false)
    expect(req.safeParse({ username: 'a', password: 'b', do_2fa: 'google_auth', secret: [REDACTED]' }).success).toBe(true)
  })

  it('send-validation-code：type 字串會被 coerce、非訪客型別擋下', () => {
    const req = authEndpoints['guest/send-validation-code'].request
    const parsed = req.safeParse({ type: '10', verify_by: '1', mobile: '60123456789' })
    expect(parsed.success).toBe(true)
    expect(parsed.data?.type).toBe(10)
    expect(req.safeParse({ type: 5, verify_by: 1, mobile: '1' }).success).toBe(false)
    expect(req.safeParse({ type: 2, verify_by: 1, mobile: '+60 123' }).success).toBe(false)
  })

  it('check-validation-code：token 必須 6 碼；get-member-accounts 只收 type 2', () => {
    expect(authEndpoints['guest/check-validation-code'].request.safeParse({ token: [REDACTED]', mobile: '1' }).success).toBe(false)
    expect(authEndpoints['guest/check-validation-code'].request.safeParse({ token: [REDACTED]', mobile: '1', type: 3 }).success).toBe(true)
    // zod 4 的 email 規則要求 TLD 至少兩碼，測試信箱不能用 a@b.c
    expect(authEndpoints['guest/get-member-accounts'].request.safeParse({ token: [REDACTED]', email: 'user@example.invalid', type: 3 }).success).toBe(false)
    expect(authEndpoints['guest/get-member-accounts'].request.safeParse({ token: [REDACTED]', email: 'user@example.invalid', type: '2' }).success).toBe(true)
  })

  it('空 data 同時接受 [] 與 null', () => {
    expect(emptyDataSchema.safeParse([]).success).toBe(true)
    expect(emptyDataSchema.safeParse(null).success).toBe(true)
    expect(emptyDataSchema.safeParse({}).success).toBe(false)
  })

  it('downline-code：has_open 收成 0/1、code 收成字串', () => {
    const parsed = authEndpoints['agent/downline-code'].response.parse({ has_open: '1', agent_list: [{ nickname: 'ag', code: 1234 }] })
    expect(parsed.has_open).toBe(1)
    expect(parsed.agent_list[0]?.code).toBe('1234')
  })

  it('member 端點不補 domain、captcha x 限 0-1000 整數', () => {
    expect(authEndpoints['member/logout'].withDomain).toBe(false)
    expect(authEndpoints['member/check-profile'].withDomain).toBe(false)
    expect(authEndpoints['guest/captcha/verify'].request.safeParse({ captcha_id: 'x', x: 1001 }).success).toBe(false)
    expect(authEndpoints['guest/captcha/verify'].request.safeParse({ captcha_id: 'x', x: '150' }).success).toBe(true)
  })
})

/* ------------------------------------------------------------------ 登入分流 */
describe('resolveLoginOutcome', () => {
  it('200/1000 帶 username → success（token 已被 handler 拿掉）', () => {
    expect(resolveLoginOutcome(ok({ id: 1, username: 'u' })).kind).toBe('success')
    expect(resolveLoginOutcome({ code: 1000, message: '', data: { id: 1, username: 'u' } }).kind).toBe('success')
  })

  it('1000 沒有 username → 客服帳號（hasDeviceId）', () => {
    const outcome = resolveLoginOutcome({ code: 1000, message: '', data: { token: 'cs', id: 9 } })
    expect(outcome).toEqual({ kind: 'customerService', token: 'cs', id: 9 })
    expect(resolveLoginOutcome(err(10001, '', { token: 't', id: 3 })).kind).toBe('customerService')
  })

  it('318 google_auth → twoFactor 單一 Google 選項', () => {
    const outcome = resolveLoginOutcome(err(318, 'google_auth', { auth_2fa: 'google_auth' }))
    expect(outcome).toEqual({ kind: 'twoFactor', options: [{ type: 'google_auth' }], memberId: undefined })
  })

  it('318 陣列 → IP 變更 OTP，帶 verify_by 與收件目標', () => {
    const outcome = resolveLoginOutcome(err(318, 'ip_change_verify', { auth_2fa: ['mobile', 'email'], id: 7, mobile: '60111', email: 'a@b.c' }))
    expect(outcome).toEqual({
      kind: 'twoFactor',
      memberId: 7,
      options: [
        { type: 'mobile', verify_by: 1, target: '60111' },
        { type: 'email', verify_by: 2, target: 'a@b.c' },
      ],
    })
  })

  it('318 但目標為空的方式會被略過', () => {
    expect(resolveTwoFactorOptions({ auth_2fa: ['mobile', 'email'], email: 'a@b.c' })).toEqual([{ type: 'email', verify_by: 2, target: 'a@b.c' }])
    expect(resolveTwoFactorOptions(null)).toEqual([])
  })

  it('319 → locked 帶訊息', () => {
    expect(resolveLoginOutcome(err(319, 'locked'))).toEqual({ kind: 'locked', message: 'locked' })
  })

  it('322 → retrievePassword 帶 member_id 與 guest_validate_token', () => {
    expect(resolveLoginOutcome(err(322, '', { member_id: '12', guest_validate_token: [REDACTED]' })))
      .toEqual({ kind: 'retrievePassword', member_id: 12, guest_validate_token: [REDACTED]' })
    expect(resolveLoginOutcome(err(322, 'bad', {})).kind).toBe('error')
  })

  it.each([
    [9007, '/phone-verification?verify_by=1'],
    [324, '/phone-verification?verify_by=1'],
    [9008, '/phone-verification?verify_by=2'],
    [326, '/phone-verification?verify_by=2'],
    [9014, MEMBER_PATH.paymentPassword],
    [323, MEMBER_PATH.paymentPassword],
    [1096, MEMBER_PATH.setFullname],
    [325, MEMBER_PATH.setFullname],
  ])('code %s → redirect %s', (code, path) => {
    expect(resolveLoginOutcome(err(code))).toEqual({ kind: 'redirect', path })
  })

  it('其他錯誤 → error 顯示 message', () => {
    expect(resolveLoginOutcome(err(400, '帳號或密碼錯誤'))).toEqual({ kind: 'error', message: '帳號或密碼錯誤' })
  })

  it('isLoginOk / isMemberLoginData', () => {
    expect(isLoginOk(200)).toBe(true)
    expect(isLoginOk(1000)).toBe(true)
    expect(isLoginOk(318)).toBe(false)
    expect(isMemberLoginData({ token: 't', id: 1, username: 'u' })).toBe(true)
    expect(isMemberLoginData({ token: 't', id: 1 })).toBe(false)
    expect(isMemberLoginData(null)).toBe(false)
  })
})

describe('登入後 redirect 安全', () => {
  it('只接受站內相對路徑', () => {
    expect(resolveRedirectPath('/wallet?x=1')).toBe('/wallet?x=1')
    expect(resolveRedirectPath('https://example.invalid')).toBe('/')
    expect(resolveRedirectPath('//example.invalid')).toBe('/')
    expect(resolveRedirectPath(['/a'])).toBe('/')
    expect(resolveRedirectPath(undefined, '/x')).toBe('/x')
  })

  it('Telegram 登入入口帶回跳頁面，註冊再帶推薦碼', () => {
    expect(telegramLoginRoute('/vip')).toEqual({ path: '/telegram-login-options', query: { redirect: '/vip', code: undefined } })
    expect(telegramLoginRoute('https://example.invalid', 'ABC')).toEqual({ path: '/telegram-login-options', query: { redirect: '/', code: 'ABC' } })
    expect(telegramLoginRoute(undefined, '').query.code).toBeUndefined()
  })
})

/* ------------------------------------------------------------------ 驡證碼規則 */
describe('驗證碼規則', () => {
  it.each([
    [1, false, 'illegal'],
    [1, true, 'member'],
    [2, false, 'guest'],
    [2, true, 'illegal'],
    [10, false, 'guest'],
    [12, false, 'guest'],
    [99, true, 'member'],
    [99, false, 'guest'],
  ])('type %s loggedIn=%s → %s', (type, loggedIn, scope) => {
    expect(resolveSendCodeScope(type, loggedIn)).toBe(scope)
  })

  // 倒數鍵與倒數秒數在 validation-code.ts，測試見 member.spec.ts

  it('手機號 = 國碼 + 去 0 號碼，只留數字', () => {
    expect(normalizeMobile('60', '0123-456 789')).toBe('60123456789')
    expect(normalizeMobile('+60', '123')).toBe('60123')
    expect(normalizeMobile('60', '')).toBe('')
    expect(normalizeOtp('12 34ab567')).toBe('123456')
  })

  it('type 2 打 get-member-accounts，其餘 check-validation-code', () => {
    expect(resolveCheckCodeRoute(2)).toBe('guest/get-member-accounts')
    expect(resolveCheckCodeRoute(3)).toBe('guest/check-validation-code')
  })

  it('驗碼後的下一步', () => {
    expect(resolveCheckCodeOutcome(2, { username_list: ['a', 'b'] }, false)).toEqual({ kind: 'selectAccount', accounts: ['a', 'b'] })
    expect(resolveCheckCodeOutcome(2, { username_list: ['only'] }, false)).toEqual({ kind: 'setPassword', username: 'only' })
    expect(resolveCheckCodeOutcome(2, { username_list: [] }, false)).toEqual({ kind: 'selectAccount', accounts: [] })
    expect(resolveCheckCodeOutcome(3, ['user3'], false)).toEqual({ kind: 'setPassword', username: 'user3' })
    expect(resolveCheckCodeOutcome(1, ['u'], true)).toEqual({ kind: 'refreshMember' })
    expect(resolveCheckCodeOutcome(1, ['u'], false)).toEqual({ kind: 'autoLogin', username: 'u' })
    expect(resolveCheckCodeOutcome(4, [], false)).toEqual({ kind: 'withdrawal' })
    expect(resolveCheckCodeOutcome(7, [], false)).toEqual({ kind: 'withdrawal' })
    expect(resolveCheckCodeOutcome(11, ['u'], true)).toEqual({ kind: 'done', username: 'u' })
  })
})

/* ------------------------------------------------------------------ 註冊規則 */
describe('註冊規則', () => {
  const settings = parseMemberFieldSettings({
    register: [
      { field_key: 'mobile', is_required: 1, field_group: 2, sort: 1 },
      { field_key: 'sms_verify_code', is_required: 1, field_group: 2, sort: 2 },
      { field_key: 'password', is_required: 1, field_group: 1, sort: 2, extra_config: { min_length: 6, max_length: 12 } },
      { field_key: 'username', is_required: '1', field_group: '1', sort: 1, extra_config: { lowercase_username: true, min_length: 4, max_length: 10 } },
      { field_key: 42 },
    ],
    login: ['username', 'mobile', 3],
  })

  it('member_field_settings 收成型別安全形狀並丟掉壞資料', () => {
    expect(settings.register.map(f => f.field_key)).toEqual(['mobile', 'sms_verify_code', 'password', 'username'])
    expect(settings.register[3]?.is_required).toBe(1)
    expect(settings.register[3]?.field_group).toBe(1)
    expect(settings.login).toEqual(['username', 'mobile'])
    expect(parseMemberFieldSettings(null)).toEqual({ register: [], login: [] })
  })

  it('有 register 設定走 v2，否則靜態', () => {
    expect(resolveRegisterMode(settings)).toBe('v2')
    expect(resolveRegisterMode({ register: [], login: [] })).toBe('static')
  })

  it('依 field_group 分步、組內依 sort', () => {
    const steps = groupRegisterFields(settings.register)
    expect(steps.map(s => s.group)).toEqual([1, 2])
    expect(steps[0]?.fields.map(f => f.field_key)).toEqual(['username', 'password'])
    expect(steps[1]?.fields.map(f => f.field_key)).toEqual(['mobile', 'sms_verify_code'])
  })

  it.each([
    [1002, 'username'],
    [2057, 'username'],
    [1021, 'confirm_password'],
    [1050, 'referral_code'],
    [1051, 'referral_code'],
    [2058, 'mobile'],
    [2059, 'email'],
    [2053, null],
    [1090, null],
  ])('錯誤碼 %s → 欄位 %s', (code, field) => {
    expect(resolveRegisterErrorField(code)).toBe(field)
  })

  it('帳號規則：只檢查英數與後台有設的規則，不自己發明限制', () => {
    expect(isAlphaNumeric('abc 1')).toBe(false)
    expect(validateUsername('abc!')).toBe('alnum')
    // 後台沒設字數就不擋字數；純數字帳號舊版也是放行的
    expect(validateUsername('123456')).toBeNull()
    expect(validateUsername('ab1')).toBeNull()
    // 後台有設才擋
    expect(validateUsername('ab1', { min_length: 4, max_length: 10 })).toBe('length')
    expect(validateUsername('abcdefghijk', { min_length: 4, max_length: 10 })).toBe('length')
    expect(validateUsername('abcd', { min_length: 4, max_length: 10 })).toBeNull()
    expect(validateUsername('Abc123', { lowercase_username: true })).toBe('lowercase')
    expect(validateUsername('abc123', { lowercase_username: true })).toBeNull()
    expect(validateUsername('60123456789', { mobile_as_username: true })).toBeNull()
  })

  it('邀請碼呈現：網址帶碼鎖定 > 自動預填 > has_open 下拉 > 手填', () => {
    expect(resolveInvitationCodeState({ urlCode: 'ABCD', hasOpen: true, isAutoAgentCode: true, defaultCode: 'X' })).toEqual({ codeType: 2, code: 'ABCD', locked: true })
    expect(resolveInvitationCodeState({ hasOpen: false, isAutoAgentCode: true, defaultCode: 'SITE' })).toEqual({ codeType: 1, code: 'SITE', locked: false })
    expect(resolveInvitationCodeState({ hasOpen: true, isAutoAgentCode: false })).toEqual({ codeType: 1, code: '', locked: false })
    expect(resolveInvitationCodeState({ hasOpen: false, isAutoAgentCode: false })).toEqual({ codeType: 2, code: '', locked: false })
  })

  it.each([
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'windows'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'ios'],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'ipad'],
    ['Mozilla/5.0 (Linux; Android 14; Pixel 8)', 'android'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)', 'mac'],
    ['', 'unknown'],
  ])('platform %s → %s', (ua, name) => {
    expect(detectPlatformName(ua)).toBe(name)
  })
})

/* ------------------------------------------------------------------ Telegram */
describe('Telegram 登入', () => {
  it('callback query → user 與 bot_username；缺 hash 視為無效', () => {
    const parsed = parseTelegramCallbackQuery({ id: '1', first_name: 'A', auth_date: '2', hash: 'h', bot_username: 'bot', photo_url: '' })
    expect(parsed.bot_username).toBe('bot')
    expect(parsed.user).toEqual({ id: '1', first_name: 'A', last_name: undefined, username: undefined, photo_url: undefined, auth_date: '2', hash: 'h' })
    expect(parseTelegramCallbackQuery({ id: '1', bot_username: 'bot' }).user).toBeNull()
  })

  it('OAuth / bot 連結', () => {
    const url = buildTelegramOAuthUrl({ botId: '123', botUsername: 'my_bot', origin: 'https://example.invalid' })
    expect(url.startsWith('https://example.invalid/auth?bot_id=123&origin=https%3A%2F%example.invalid&request_access=write&return_to=')).toBe(true)
    expect(decodeURIComponent(url.split('return_to=')[1] ?? '')).toBe('https://example.invalid/telegram-callback?bot_username=my_bot')
    expect(buildTelegramBotUrl('my_bot')).toBe('https://example.invalid/my_bot')
    expect(buildTelegramBotUrl('my_bot', 'A B')).toBe('https://example.invalid/my_bot/startapp?startapp=A%20B')
  })

  it('widget postMessage 解析：字串或物件，只認 auth_result', () => {
    expect(parseTelegramAuthMessage(JSON.stringify({ event: 'auth_result', result: { id: 1 } }))).toEqual({ id: 1 })
    expect(parseTelegramAuthMessage({ event: 'auth_result', result: { id: 2 } })).toEqual({ id: 2 })
    expect(parseTelegramAuthMessage({ event: 'other' })).toBeNull()
    expect(parseTelegramAuthMessage('{bad')).toBeNull()
    expect(needsInviteCode(333)).toBe(true)
    expect(needsInviteCode(AUTH_CODE.LOGOUT)).toBe(false)
  })
})

/* ------------------------------------------------------------------ store */
describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    // store 內的 ref 是 Nuxt 自動匯入，測試環境沒有全域就會 ReferenceError
    vi.stubGlobal('ref', ref)
    vi.stubGlobal('computed', computed)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('倒數以到期時間計算、可清除', () => {
    const store = useAuthStore()
    store.startCodeTimer('2:1:60123', 180, 1_000_000)
    expect(store.codeTimerRemaining('2:1:60123', 1_000_000)).toBe(180)
    expect(store.codeTimerRemaining('2:1:60123', 1_000_000 + 179_500)).toBe(1)
    expect(store.codeTimerRemaining('2:1:60123', 1_000_000 + 180_000)).toBe(0)
    expect(store.codeTimerRemaining('other', 1_000_000)).toBe(0)
    store.clearCodeTimer('2:1:60123')
    expect(store.codeTimerRemaining('2:1:60123', 1_000_000)).toBe(0)
  })

  it('select-account 補帳號、reset 清空', () => {
    const store = useAuthStore()
    store.setResetContext({ username: null, token: [REDACTED]', mobile: '60123' })
    store.setResetUsername('picked')
    expect(store.resetContext?.username).toBe('picked')
    store.setPendingRegistration({ username: 'u', password: 'p' })
    store.reset()
    expect(store.resetContext).toBeNull()
    expect(store.pendingRegistration).toBeNull()
  })
})

/* ------------------------------------------------------------------ composables（stub Nuxt 自動匙入） */
describe('composables', () => {
  const fetchMock = vi.fn()
  const postMock = vi.fn()
  const navigateMock = vi.fn()
  const sessionFetch = vi.fn()
  const loggedIn = ref(false)

  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock.mockReset()
    postMock.mockReset()
    navigateMock.mockReset()
    sessionFetch.mockReset()
    loggedIn.value = false
    vi.stubGlobal('ref', ref)
    vi.stubGlobal('computed', computed)
    vi.stubGlobal('$fetch', fetchMock)
    vi.stubGlobal('navigateTo', navigateMock)
    vi.stubGlobal('isApiOk', isApiOk)
    // postRaw 是 useApi 上「走加密路徑的同源 POST」（登入那幾支）。
    // 加密本身由 client-crypto.spec.ts 驗；這裡還原成原本的 $fetch 呼叫形狀，
    // 底下驗 URL 與 body 的斷言就不用改。
    vi.stubGlobal('useApi', () => ({
      post: postMock,
      postRaw: (url: string, body: unknown) => fetchMock(url, { method: 'POST', body }),
    }))
    vi.stubGlobal('useUserSession', () => ({
      loggedIn: computed(() => loggedIn.value),
      user: computed(() => (loggedIn.value ? { id: 1, username: 'me' } : null)),
      fetch: sessionFetch,
      clear: vi.fn(),
    }))
    vi.stubGlobal('useSite', () => ({
      site: ref({ raw: { frontend_register: 1, member_field_settings: { register: [], login: [] }, code: 'SITE' } }),
      flag: (key: string) => key === 'send_code_captcha_enabled',
    }))
    vi.stubGlobal('useAuth', useAuth)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('login：送 /api/auth/login，成功 → session.fetch → redirect；不在登入流程打 check-profile（照舊版，交給首頁／會員中心）', async () => {
    fetchMock.mockResolvedValueOnce(ok({ id: 1, username: 'me', nickname: 'n' }))
    const { login } = useAuth()
    const result = await login({ username: 'me', password: 'pw', platform: 'windows' }, { redirect: '/wallet' })
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/login', { method: 'POST', body: { username: 'me', password: 'pw', platform: 'windows' } })
    expect(sessionFetch).toHaveBeenCalledTimes(1)
    expect(postMock).not.toHaveBeenCalledWith('member/check-profile', expect.anything(), expect.anything())
    expect(navigateMock).toHaveBeenCalledWith('/wallet')
    expect(result.outcome.kind).toBe('success')
  })

  it('login：外部 redirect 被擋回首頁', async () => {
    fetchMock.mockResolvedValueOnce(ok({ id: 1, username: 'me' }))
    await useAuth().login({ username: 'me', password: 'pw' }, { redirect: 'https://example.invalid' })
    expect(navigateMock).toHaveBeenLastCalledWith('/')
  })

  it('login 318：暫存原參數到 store、導二次驗證；loginWithTwoFactor 帶 do_2fa/secret 重送', async () => {
    fetchMock.mockResolvedValueOnce(err(318, 'google_auth', { auth_2fa: 'google_auth' }))
    const auth = useAuth()
    const first = await auth.login({ username: 'me', password: 'pw' })
    expect(first.outcome.kind).toBe('twoFactor')
    expect(navigateMock).toHaveBeenCalledWith('/two-factor-authentication')
    const store = useAuthStore()
    expect(store.pendingLogin?.params).toEqual({ username: 'me', password: 'pw' })
    expect(store.pendingLogin?.options).toEqual([{ type: 'google_auth' }])

    fetchMock.mockResolvedValueOnce(ok({ id: 1, username: 'me' }))
    postMock.mockResolvedValueOnce(ok(null))
    await auth.loginWithTwoFactor('google_auth', '654321')
    expect(fetchMock).toHaveBeenLastCalledWith('/api/auth/login', {
      method: 'POST',
      body: { username: 'me', password: 'pw', do_2fa: 'google_auth', secret: [REDACTED]' },
    })
    expect(store.pendingLogin).toBeNull()
  })

  it('login 322：存 guest_validate_token 導找回密碼；319 不導頁', async () => {
    fetchMock.mockResolvedValueOnce(err(322, '', { member_id: 5, guest_validate_token: [REDACTED]' }))
    const auth = useAuth()
    await auth.login({ username: 'me', password: [REDACTED]' })
    expect(useAuthStore().retrieve).toEqual({ member_id: 5, guest_validate_token: [REDACTED]' })
    expect(navigateMock).toHaveBeenCalledWith('/retrieve-password')

    navigateMock.mockClear()
    fetchMock.mockResolvedValueOnce(err(319, 'locked'))
    const r = await auth.login({ username: 'me', password: [REDACTED]' })
    expect(r.outcome.kind).toBe('locked')
    expect(navigateMock).not.toHaveBeenCalled()
  })

  it('loginWithTwoFactor 沒有暫存回 null；logout 清 store 並回登入頁', async () => {
    const auth = useAuth()
    expect(await auth.loginWithTwoFactor('google_auth', '000000')).toBeNull()
    useAuthStore().setPendingRegistration({ username: 'u', password: 'p' })
    fetchMock.mockResolvedValueOnce(ok(null))
    await auth.logout()
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/logout', { method: 'POST' })
    expect(useAuthStore().pendingRegistration).toBeNull()
    expect(navigateMock).toHaveBeenCalledWith('/login')
  })

  it('register 成功後自動登入並暫存帳密', async () => {
    postMock.mockResolvedValueOnce(ok({ id: 1, username: 'newbie' }))
    fetchMock.mockResolvedValueOnce(ok({ id: 1, username: 'newbie' }))
    postMock.mockResolvedValueOnce(ok(null))
    const { register } = useAuth()
    await register({ username: 'newbie', password: [REDACTED]', password_confirmation: 'pass12', invitation_code: 'ABCD' })
    expect(postMock).toHaveBeenNthCalledWith(1, 'guest/register', { username: 'newbie', password: [REDACTED]', password_confirmation: 'pass12', invitation_code: 'ABCD' })
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/login', { method: 'POST', body: { username: 'newbie', password: [REDACTED]' } })
  })

  it('sendCode：訪客型別未登入走 guest 路徑、成功啟動倒數；會員型別未登入 → illegal 不打 API', async () => {
    postMock.mockResolvedValueOnce(ok([]))
    const vc = useGuestValidationCode()
    const sent = await vc.sendCode({ type: 2, verify_by: 1, mobile: '60123', email: 'ignored@x.y', username: 'me', captcha_token: [REDACTED]' })
    expect(sent.scope).toBe('guest')
    expect(postMock).toHaveBeenCalledWith('guest/send-validation-code', { type: 2, verify_by: 1, mobile: '60123', username: 'me', captcha_token: [REDACTED]' })
    expect(useAuthStore().codeTimerRemaining('2:1:60123')).toBeGreaterThan(0)

    const illegal = await vc.sendCode({ type: 1, verify_by: 1, mobile: '60123' })
    expect(illegal).toEqual({ scope: 'illegal', res: null })
    expect(postMock).toHaveBeenCalledTimes(1)
  })

  it('sendCode：已登入的會員型別走 member/send-validation-code', async () => {
    loggedIn.value = true
    postMock.mockResolvedValueOnce(ok([]))
    await useGuestValidationCode().sendCode({ type: 1, verify_by: 2, email: 'a@b.c' })
    expect(postMock).toHaveBeenCalledWith('member/send-validation-code', { type: 1, verify_by: 2, email: 'a@b.c' })
    expect(useGuestValidationCode().captchaRequired.value).toBe(true)
    expect(useGuestValidationCode().scopeFor(10)).toBe('illegal')
  })

  it('checkCode type 2：多帳號存清單導 /select-account、清倒數', async () => {
    const store = useAuthStore()
    store.startCodeTimer('2:1:60123', 180)
    postMock.mockResolvedValueOnce(ok({ username_list: ['a', 'b'] }))
    const { checkCode } = useGuestValidationCode()
    const result = await checkCode({ type: 2, token: [REDACTED]', mobile: '60123' })
    expect(postMock).toHaveBeenCalledWith('guest/get-member-accounts', { token: [REDACTED]', type: 2, mobile: '60123' })
    expect(result.outcome).toEqual({ kind: 'selectAccount', accounts: ['a', 'b'] })
    expect(store.accountList).toEqual(['a', 'b'])
    expect(store.resetContext).toEqual({ username: null, token: [REDACTED]', mobile: '60123', email: undefined })
    expect(store.codeTimerRemaining('2:1:60123')).toBe(0)
    expect(navigateMock).toHaveBeenCalledWith('/select-account')
  })

  it('checkCode type 3：帶 guest_validate_token，data[0] 為帳號導 /set-password', async () => {
    postMock.mockResolvedValueOnce(ok(['user3']))
    const { checkCode } = useGuestValidationCode()
    await checkCode({ type: 3, token: [REDACTED]', email: 'a@b.c', guest_validate_token: [REDACTED]' })
    expect(postMock).toHaveBeenCalledWith('guest/check-validation-code', { token: [REDACTED]', type: 3, email: 'a@b.c', guest_validate_token: [REDACTED]' })
    expect(useAuthStore().resetContext).toEqual({ username: 'user3', token: [REDACTED]', mobile: undefined, email: 'a@b.c' })
    expect(navigateMock).toHaveBeenCalledWith('/set-password')
  })

  it('checkCode type 1 未登入：用暫存的註冊密碼自動登入；失敗不導頁', async () => {
    useAuthStore().setPendingRegistration({ username: 'newbie', password: [REDACTED]' })
    postMock.mockResolvedValueOnce(ok(['newbie']))
    fetchMock.mockResolvedValueOnce(ok({ id: 1, username: 'newbie' }))
    const { checkCode } = useGuestValidationCode()
    await checkCode({ type: 1, token: [REDACTED]', mobile: '60123' })
    expect(fetchMock).toHaveBeenCalledWith('/api/auth/login', { method: 'POST', body: { username: 'newbie', password: [REDACTED]' } })

    postMock.mockResolvedValueOnce(err(9992, 'wrong code'))
    const failed = await checkCode({ type: 1, token: [REDACTED]', mobile: '60123' })
    expect(failed.outcome).toBeNull()
    expect(failed.res.code).toBe(9992)
  })

  it('resetPassword：用 store 的帳號與驗證碼組請求，成功回 /login?reset=1；缺 context 回 422', async () => {
    const vc = useGuestValidationCode()
    const missing = await vc.resetPassword({ new_password: [REDACTED]', new_password_confirmation: 'abc123' })
    expect(missing.code).toBe(422)
    expect(postMock).not.toHaveBeenCalled()

    useAuthStore().setResetContext({ username: 'picked', token: [REDACTED]' })
    postMock.mockResolvedValueOnce(ok([]))
    await vc.resetPassword({ new_password: [REDACTED]', new_password_confirmation: 'abc123' })
    expect(postMock).toHaveBeenCalledWith('guest/reset-password', { username: 'picked', token: [REDACTED]', new_password: [REDACTED]', new_password_confirmation: 'abc123' })
    expect(useAuthStore().resetContext).toBeNull()
    expect(navigateMock).toHaveBeenCalledWith({ path: '/login', query: { reset: '1' } })
  })

  it('captcha：訪客型別出題/判定走 guest 端點且 silent，x 四捨五入', async () => {
    postMock.mockResolvedValueOnce(ok({ captcha_id: 'c', bg_image: '', piece_image: '', bg_width: 300, bg_height: 150, piece_width: 50, piece_y: 10, expires_in: 60 }))
    postMock.mockResolvedValueOnce(ok({ captcha_token: [REDACTED]', expires_in: 300 }))
    const vc = useGuestValidationCode()
    await vc.fetchCaptcha(2)
    await vc.verifyCaptcha(2, 'c', 123.6)
    expect(postMock).toHaveBeenNthCalledWith(1, 'guest/captcha', {}, { silent: true })
    expect(postMock).toHaveBeenNthCalledWith(2, 'guest/captcha/verify', { captcha_id: 'c', x: 124 }, { silent: true })
  })
})
