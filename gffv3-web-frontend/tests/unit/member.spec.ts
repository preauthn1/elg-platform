import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  memberDetailSchema,
  memberEndpoints,
  sendCodeRequestSchema,
  updateProfileRequestSchema,
} from '../../shared/api/endpoints/member'
import {
  MEMBER_PATH,
  buildBankAccount,
  buildSettingMenu,
  hasValue,
  isAdultBirthday,
  maxAdultBirthday,
  nextToggleValue,
  normalizeRealnameFields,
  profilePatchFromUpdate,
  profileRedirectFor,
  realnameRouteBlock,
  realnameRouteKind,
  resolveRealnameForm,
  resolveSetFullnameRedirect,
} from '../../shared/utils/member'
import {
  ILLEGAL_LOGIN_STATUS_CODE,
  codeCountdownSeconds,
  codeTimerKey,
  formatCountdown,
  isIllegalLoginStatus,
  joinMobile,
  normalizeCode,
  resolveCodeUserType,
  scaleSliderX,
} from '../../shared/utils/validation-code'
import { useMember } from '../../app/composables/useMember'
import { useValidationCode } from '../../app/composables/useValidationCode'

const OK = 200

describe('memberEndpoints 登記', () => {
  it('spec 裡屬於 member 領域的路由都有登記、auth 照 routes.json', () => {
    const memberAuth = [
      'member/detail',
      'member/update-profile',
      'member/set-birthday',
      'member/set-realname',
      'member/change-password',
      'member/change-payment-password',
      'member/send-validation-code',
      'member/captcha',
      'member/captcha/verify',
      'member/reset-mobile',
      'member/reset-email',
      'realname-validation',
      'realname-validation/check',
      'member/tracking-events',
      'google-auth/image',
    ]
    for (const key of memberAuth) {
      expect(memberEndpoints[key as keyof typeof memberEndpoints]?.auth, key).toBe('member')
      expect(memberEndpoints[key as keyof typeof memberEndpoints]?.path).toBe(key)
    }
    expect(memberEndpoints['google-auth/auth'].auth).toBe('optional')
  })

  it('別的領域的路由不在這裡', () => {
    const keys = Object.keys(memberEndpoints)
    for (const foreign of ['guest/check-validation-code', 'member/check-profile', 'tgbot/complete-profile', 'member/check-transfer-status', 'agent/template']) {
      expect(keys).not.toContain(foreign)
    }
  })

  it('send-validation-code 只收會員端型別與 1/2 管道', () => {
    expect(sendCodeRequestSchema.safeParse({ type: 1, verify_by: 1, mobile: '60123456789' }).success).toBe(true)
    expect(sendCodeRequestSchema.safeParse({ type: 6, verify_by: 2, email: 'user@example.invalid', payment_password: [REDACTED]', payment_password_confirmation: '123456' }).success).toBe(true)
    expect(sendCodeRequestSchema.safeParse({ type: 2, verify_by: 1, mobile: '60123456789' }).success).toBe(false)
    expect(sendCodeRequestSchema.safeParse({ type: 1, verify_by: 3 }).success).toBe(false)
    expect(sendCodeRequestSchema.safeParse({ type: 1, verify_by: 1, mobile: '+60-123' }).success).toBe(false)
  })

  it('update-profile 開關只收 0/1 或 "0"/"1"，不收 boolean', () => {
    expect(updateProfileRequestSchema.safeParse({ has_2fa: '1' }).success).toBe(true)
    expect(updateProfileRequestSchema.safeParse({ ip_change_mobile_verify: 0 }).success).toBe(true)
    expect(updateProfileRequestSchema.safeParse({ has_2fa: false }).success).toBe(false)
    expect(updateProfileRequestSchema.safeParse({ nickname: 'ab cd' }).success).toBe(false)
    expect(updateProfileRequestSchema.safeParse({ nickname: 'ab_cd-1' }).success).toBe(true)
  })

  it('member/detail 回應：0/1 收成 flag、payment_password 只是旗標', () => {
    const parsed = memberDetailSchema.parse({
      id: '7',
      parent: 3,
      username: 'tester',
      balance: '12.30',
      rolling_chip: 0,
      image: null,
      email: null,
      nickname: 'nick',
      first_name: '',
      birthday: null,
      mobile: '60123456789',
      mobile_validate: '1',
      email_validate: 0,
      realname_validate: 0,
      has_2fa: 0,
      payment_password: 1,
      is_tg_user: false,
      ip_change_mobile_verify: 1,
      ip_change_email_verify: 0,
      extra_field: 'kept',
    })
    expect(parsed.id).toBe(7)
    expect(parsed.mobile_validate).toBe(1)
    expect(parsed.payment_password).toBe(1)
    expect(parsed.ip_change_mobile_verify).toBe(1)
    expect((parsed as Record<string, unknown>).extra_field).toBe('kept')
  })

  it('沒資料的回應接受 null 與空陣列（ApiResponse::success 預設 []）', () => {
    const schema = memberEndpoints['member/set-birthday'].response
    expect(schema.safeParse(null).success).toBe(true)
    expect(schema.safeParse([]).success).toBe(true)
    expect(schema.safeParse({ a: 1 }).success).toBe(false)
  })
})

describe('會員規則（純函式）', () => {
  it('hasValue：null / "" / "null" 都算沒值', () => {
    expect(hasValue(null)).toBe(false)
    expect(hasValue('')).toBe(false)
    expect(hasValue('null')).toBe(false)
    expect(hasValue(' ')).toBe(false)
    expect(hasValue('60123')).toBe(true)
    expect(hasValue(0)).toBe(true)
  })

  it.each([
    [324, MEMBER_PATH.phoneVerification],
    [9007, MEMBER_PATH.phoneVerification],
    [326, MEMBER_PATH.bindEmail],
    [9008, MEMBER_PATH.bindEmail],
    [323, MEMBER_PATH.paymentPassword],
    [9014, MEMBER_PATH.paymentPassword],
    [325, MEMBER_PATH.setFullname],
    [1095, MEMBER_PATH.setFullname],
    [1096, MEMBER_PATH.setFullname],
    [332, MEMBER_PATH.realNameVerification],
    [327, `/withdrawal?redirect=${encodeURIComponent('/member')}`],
    [2040, `/withdrawal?redirect=${encodeURIComponent('/member')}`],
    [321, MEMBER_PATH.home],
    [999, MEMBER_PATH.home],
    [1956, MEMBER_PATH.home],
    [320, '/'],
    [1938, '/'],
    [322, '/forget-password'],
    [9006, '/forget-password'],
    [334, MEMBER_PATH.resetPaymentPassword],
  ])('check-profile / 全域碼 %s → %s', (code, path) => {
    expect(profileRedirectFor(code)).toBe(path)
  })

  it('2041 帶目前路徑回跳提款；未知碼與 319/9998 不導頁', () => {
    expect(profileRedirectFor(2041, '/events')).toBe(`${MEMBER_PATH.setFullname}?next=${encodeURIComponent('/withdrawal?redirect=/events')}`)
    expect(profileRedirectFor(331)).toBe(`${MEMBER_PATH.setFullname}?next=${encodeURIComponent('/withdrawal')}`)
    expect(profileRedirectFor(200)).toBeNull()
    expect(profileRedirectFor(9998)).toBeNull()
    expect(profileRedirectFor(319)).toBeNull()
  })

  it('set-fullname 回跳：next 優先、再 redirect、否則會員中心；只接受站內路徑', () => {
    expect(resolveSetFullnameRedirect({ next: '/withdrawal?redirect=/events', redirect: '/x' })).toBe('/withdrawal?redirect=/events')
    expect(resolveSetFullnameRedirect({ redirect: '/events' })).toBe('/events')
    expect(resolveSetFullnameRedirect({})).toBe(MEMBER_PATH.home)
    expect(resolveSetFullnameRedirect({ next: 'https://evil.example' })).toBe(MEMBER_PATH.home)
    expect(resolveSetFullnameRedirect({ next: '//evil.example' })).toBe(MEMBER_PATH.home)
    expect(resolveSetFullnameRedirect({ next: ['/a', '/b'] })).toBe('/a')
  })

  it('成年檢查：生日必須早於今天減 18 年', () => {
    const today = new Date(2026, 8, 10)
    expect(maxAdultBirthday(today)).toBe('2008-09-10')
    expect(isAdultBirthday('2008-09-09', today)).toBe(true)
    expect(isAdultBirthday('2008-09-10', today)).toBe(false)
    expect(isAdultBirthday('2010-01-01', today)).toBe(false)
    expect(isAdultBirthday('2000-02-30', today)).toBe(false)
    expect(isAdultBirthday('not-a-date', today)).toBe(false)
  })

  it('開關切換：開 → 送 0、關 → 送 1，永遠不送 false', () => {
    expect(nextToggleValue(1)).toBe(0)
    expect(nextToggleValue('1')).toBe(0)
    expect(nextToggleValue(0)).toBe(1)
    expect(nextToggleValue(undefined)).toBe(1)
  })

  it('update-profile 成功後寫回 store 的欄位', () => {
    expect(profilePatchFromUpdate({ nickname: 'n', has_2fa: '1', ip_change_email_verify: 0, zip_code: 123 }))
      .toEqual({ nickname: 'n', has_2fa: 1, ip_change_email_verify: 0, zip_code: '123' })
    expect(profilePatchFromUpdate({})).toEqual({})
  })

  describe('設定選單', () => {
    const allOn = {
      modifyMobileVisible: true,
      modifyEmailVisible: true,
      bindingEmailVisible: true,
      paymentPasswordVisible: true,
      hasGoogleAuth: true,
    }
    const keys = (items: ReturnType<typeof buildSettingMenu>) => items.map(i => i.key)

    it('手機信箱都沒綁、支付密碥未設、生日空 → 綁定入口 + 設定支付密碼 + 設定生日', () => {
      const items = buildSettingMenu({ mobile: null, email: '', payment_password: 0, birthday: null }, allOn)
      expect(keys(items)).toEqual([
        'bindPhone', 'bindEmail', 'changePassword', 'paymentPassword', 'googleAuthenticator', 'ipChangeVerification', 'setBirthday',
      ])
      expect(items.find(i => i.key === 'bindPhone')?.to).toBe(MEMBER_PATH.phoneVerification)
      expect(items.find(i => i.key === 'paymentPassword')?.to).toBe(MEMBER_PATH.paymentPassword)
    })

    it('都綁了、支付密碼已設、生日已填 → 變更入口 + 變更支付密碼（沒有獨立的重設入口）、沒有生日', () => {
      const items = buildSettingMenu({ mobile: '60123', email: 'user@example.invalid', payment_password: 1, birthday: '2000-01-01' }, allOn)
      expect(keys(items)).toEqual([
        'changePhone', 'changeEmail', 'changePassword', 'changePaymentPassword', 'googleAuthenticator', 'ipChangeVerification',
      ])
    })

    it('旗標關閉就不出現對應入口；沒 profile 也不炸', () => {
      const items = buildSettingMenu(null, { ...allOn, modifyMobileVisible: false, bindingEmailVisible: false, paymentPasswordVisible: false, hasGoogleAuth: false })
      expect(keys(items)).toEqual(['changePassword', 'ipChangeVerification', 'setBirthday'])
    })
  })

  describe('實名認證路由守衛（舊前台 router beforeEach）', () => {
    const all = { transferRequired: true, eventRequired: true, applyVisible: true }

    it('實名頁一律檢查；提款看 transfer 旗標、活動類看 event 旗標，且都要 applyVisible', () => {
      expect(realnameRouteKind('/member/real-name-verification', { ...all, applyVisible: false })).toBe('page')
      expect(realnameRouteKind('/withdrawal', all)).toBe('entry')
      expect(realnameRouteKind('/events/lucky-wheel', all)).toBe('entry')
      expect(realnameRouteKind('/promotions', all)).toBe('entry')
      expect(realnameRouteKind('/withdrawal', { ...all, transferRequired: false })).toBeNull()
      expect(realnameRouteKind('/promotions', { ...all, eventRequired: false })).toBeNull()
      expect(realnameRouteKind('/withdrawal', { ...all, applyVisible: false })).toBeNull()
      // 儲值、錢包在舊版的 restrictedPaths 裡，但不做實名檢查
      expect(realnameRouteKind('/topup', all)).toBeNull()
      expect(realnameRouteKind('/wallet', all)).toBeNull()
    })

    it('進實名頁：審核中擋、已通過導回會員中心', () => {
      expect(realnameRouteBlock('page', { is_exists: true, is_validated: false })).toBe('reviewing')
      expect(realnameRouteBlock('page', { is_exists: false, is_validated: true })).toBe('approved')
      expect(realnameRouteBlock('page', { is_exists: false, is_validated: false })).toBeNull()
    })

    it('進提款／活動：未申請、審核中都擋（審核中不可放行）', () => {
      expect(realnameRouteBlock('entry', { is_exists: false, is_validated: false })).toBe('required')
      expect(realnameRouteBlock('entry', { is_exists: true, is_validated: false })).toBe('reviewing')
      expect(realnameRouteBlock('entry', { is_exists: false, is_validated: true })).toBeNull()
      expect(realnameRouteBlock('entry', { is_exists: 1, is_validated: 1 })).toBeNull()
    })
  })

  it('實名欄位設定：沒設定 → 姓名+銀行卡必填；有設定 → 逐欄', () => {
    expect(normalizeRealnameFields(undefined)).toEqual({})
    expect(normalizeRealnameFields([])).toEqual({})
    const fields = normalizeRealnameFields({ bank: { show: true, required: false }, birthday: { show: true, required: true }, id_photo: {} })
    expect(fields).toEqual({
      bank: { show: true, required: false },
      birthday: { show: true, required: true },
      id_photo: { show: true, required: false },
    })
    const empty = resolveRealnameForm({})
    expect(empty.bank).toEqual({ show: true, required: true })
    expect(empty.birthday.show).toBe(false)
    const form = resolveRealnameForm(fields)
    expect(form.bank.required).toBe(false)
    expect(form.birthday.required).toBe(true)
    expect(form.id_number.show).toBe(false)
  })

  it('BSB 開啟時帳號前綴 bsb__', () => {
    expect(buildBankAccount(' 123456 ', ' 062 ', true)).toBe('062__123456')
    expect(buildBankAccount('123456', '062', false)).toBe('123456')
    expect(buildBankAccount('123456', '', true)).toBe('123456')
  })
})

describe('驗證碼規則（純函式）', () => {
  it('依型別與登入狀態決定 member / guest', () => {
    expect(resolveCodeUserType(1, true)).toBe('member')
    expect(resolveCodeUserType(11, true)).toBe('member')
    expect(resolveCodeUserType(2, false)).toBe('guest')
    expect(resolveCodeUserType(10, false)).toBe('guest')
    expect(resolveCodeUserType(10, true)).toBe('guest')
    expect(resolveCodeUserType(99, true)).toBe('member')
    expect(resolveCodeUserType(99, false)).toBe('guest')
  })

  it('會員型別未登入、訪客型別已登入 → 狀態不合法', () => {
    expect(isIllegalLoginStatus(1, false)).toBe(true)
    expect(isIllegalLoginStatus(2, true)).toBe(true)
    expect(isIllegalLoginStatus(1, true)).toBe(false)
    expect(isIllegalLoginStatus(2, false)).toBe(false)
  })

  it('倒數秒數與分組鍵', () => {
    expect(codeCountdownSeconds(false)).toBe(180)
    expect(codeCountdownSeconds(true)).toBe(60)
    expect(codeTimerKey({ type: 1, verify_by: 1, mobile: '60123' })).toBe('1:1:60123')
    expect(codeTimerKey({ type: 11, verify_by: 2, email: 'user@example.invalid' })).toBe('11:2:user@example.invalid')
    expect(codeTimerKey({ type: 5, verify_by: 1 })).toBe('5:1:')
  })

  it('倒數顯示 10 秒以下補零', () => {
    expect(formatCountdown(180)).toBe('180')
    expect(formatCountdown(10)).toBe('10')
    expect(formatCountdown(9)).toBe('09')
    expect(formatCountdown(0)).toBe('00')
    expect(formatCountdown(-3)).toBe('00')
  })

  it('滑塊距離換算回 bg_width 邏輯像素並夾在範圍內', () => {
    expect(scaleSliderX(75, 150, 300)).toBe(150)
    expect(scaleSliderX(0, 150, 300)).toBe(0)
    expect(scaleSliderX(400, 150, 300)).toBe(300)
    expect(scaleSliderX(10, 0, 300)).toBe(0)
  })

  it('驗證碼與手機號整理', () => {
    expect(normalizeCode('12 34-567')).toBe('123456')
    expect(normalizeCode(undefined)).toBe('')
    expect(joinMobile('+60', '12-345 6789')).toBe('60123456789')
  })
})

/** 用最小實作取代 Nuxt 自動匯入，只驗 composable 的組法與分流。 */
interface FakeRef<T> { value: T }
function fakeRef<T>(value: T): FakeRef<T> {
  return { value }
}
function fakeComputed<T>(getter: () => T): { readonly value: T } {
  return {
    get value() {
      return getter()
    },
  }
}

function makeStore(profile: Record<string, unknown> | null) {
  const store = {
    profile,
    setProfile: vi.fn((next: Record<string, unknown> | null) => {
      store.profile = next
    }),
    patchProfile: vi.fn((patch: Record<string, unknown>) => {
      if (store.profile) store.profile = { ...store.profile, ...patch }
    }),
    reset: vi.fn(),
  }
  return store
}

function makeTimers() {
  return {
    timers: {} as Record<string, number>,
    start: vi.fn(),
    remaining: vi.fn(() => 0),
    clear: vi.fn(),
    clearTarget: vi.fn(),
  }
}

describe('useMember（stub 自動匯入）', () => {
  const post = vi.fn()
  let store: ReturnType<typeof makeStore>

  beforeEach(() => {
    post.mockReset()
    store = makeStore(null)
    vi.stubGlobal('useApi', () => ({ post }))
    vi.stubGlobal('isApiOk', (res: { code: number }) => res.code === OK)
    vi.stubGlobal('useMemberStore', () => store)
    vi.stubGlobal('computed', fakeComputed)
    vi.stubGlobal('ref', fakeRef)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('load：打 member/detail，成功寫進 store', async () => {
    post.mockResolvedValue({ code: OK, message: 'ok', data: { id: 1, username: 'u', payment_password: 0 } })
    const member = useMember()
    const res = await member.load()
    expect(post).toHaveBeenCalledWith('member/detail', {})
    expect(res.code).toBe(OK)
    expect(store.setProfile).toHaveBeenCalledWith({ id: 1, username: 'u', payment_password: 0 })
  })

  it('load 失敗不動 store；ensureLoaded 已有資料就不打', async () => {
    post.mockResolvedValue({ code: 9998, message: 'relogin', data: null })
    const member = useMember()
    await member.load()
    expect(store.setProfile).not.toHaveBeenCalled()

    store.profile = { id: 1 }
    post.mockClear()
    expect(await member.ensureLoaded()).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('setNickname 只送 nickname（trim），成功寫回 profile', async () => {
    store.profile = { id: 1, nickname: 'old' }
    post.mockResolvedValue({ code: OK, message: 'ok', data: [] })
    await useMember().setNickname('  new_nick ')
    expect(post).toHaveBeenCalledWith('member/update-profile', { nickname: 'new_nick' })
    expect(store.profile).toMatchObject({ nickname: 'new_nick' })
  })

  it('update-profile 失敗（1088）不寫回 store', async () => {
    store.profile = { id: 1, email: null }
    post.mockResolvedValue({ code: 1088, message: 'email limit', data: [] })
    const res = await useMember().updateProfile({ email: 'user@example.invalid' })
    expect(res.code).toBe(1088)
    expect(store.patchProfile).not.toHaveBeenCalled()
  })

  it('setBirthday / setRealName 成功後寫回；失敗碼原樣回傳', async () => {
    store.profile = { id: 1, birthday: null, first_name: '' }
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    const member = useMember()
    await member.setBirthday('2000-01-01')
    expect(post).toHaveBeenLastCalledWith('member/set-birthday', { birthday: '2000-01-01' })
    expect(store.profile).toMatchObject({ birthday: '2000-01-01' })

    post.mockResolvedValueOnce({ code: 1097, message: 'already', data: [] })
    const fail = await member.setRealName(' 王小明 ')
    expect(post).toHaveBeenLastCalledWith('member/set-realname', { real_name: '王小明' })
    expect(fail.code).toBe(1097)
    expect(store.profile).toMatchObject({ first_name: '' })

    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    await member.setRealName('王小明')
    expect(store.profile).toMatchObject({ first_name: '王小明' })
  })

  it('changePassword 原樣送三欄；changePaymentPassword 成功後 payment_password=1', async () => {
    store.profile = { id: 1, payment_password: 0 }
    post.mockResolvedValueOnce({ code: 1018, message: 'wrong', data: [] })
    const member = useMember()
    const res = await member.changePassword({ password: [REDACTED]', new_password: [REDACTED]', new_password_confirmation: 'b12345' })
    expect(post).toHaveBeenLastCalledWith('member/change-password', { password: [REDACTED]', new_password: [REDACTED]', new_password_confirmation: 'b12345' })
    expect(res.code).toBe(1018)

    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    await member.changePaymentPassword({ new_payment_password: [REDACTED]', new_payment_password_confirmation: '123456' })
    expect(post).toHaveBeenLastCalledWith('member/change-payment-password', { new_payment_password: [REDACTED]', new_payment_password_confirmation: '123456' })
    expect(store.profile).toMatchObject({ payment_password: 1 })
  })

  it('checkProfile：走全域錯誤處理（不 silent）；成功不導頁，324 導綁手機，2041 帶目前路徑', async () => {
    const member = useMember()
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    expect((await member.checkProfile('/member')).redirect).toBeNull()
    expect(post).toHaveBeenLastCalledWith('member/check-profile', {})

    post.mockResolvedValueOnce({ code: 324, message: 'mobile', data: [] })
    expect((await member.checkProfile('/member')).redirect).toBe(MEMBER_PATH.phoneVerification)

    post.mockResolvedValueOnce({ code: 2041, message: 'realname', data: [] })
    expect((await member.checkProfile('/events')).redirect).toBe(`${MEMBER_PATH.setFullname}?next=${encodeURIComponent('/withdrawal?redirect=/events')}`)
  })

  it('fetchTrackingEvents 靜默：成功回 items、失敗回空陣列', async () => {
    const member = useMember()
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: { items: [{ type: 'CompleteRegistration', data: { v: 1 } }] } })
    expect(await member.fetchTrackingEvents()).toEqual([{ type: 'CompleteRegistration', data: { v: 1 } }])
    expect(post).toHaveBeenLastCalledWith('member/tracking-events', {}, { silent: true })

    post.mockRejectedValueOnce(new Error('network'))
    expect(await member.fetchTrackingEvents()).toEqual([])
  })

  it('啟用 2FA：驗碼失敗就停在那、不打 update-profile；成功才送 has_2fa "1"', async () => {
    store.profile = { id: 1, username: 'tester', has_2fa: 0 }
    const member = useMember()
    post.mockResolvedValueOnce({ code: 401, message: 'wrong code', data: [] })
    const fail = await member.enableGoogleAuth('000000')
    expect(post).toHaveBeenCalledTimes(1)
    expect(post).toHaveBeenLastCalledWith('google-auth/auth', { username: 'tester', secret: [REDACTED]' })
    expect(fail.code).toBe(401)
    expect(store.profile).toMatchObject({ has_2fa: 0 })

    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    await member.enableGoogleAuth('123456')
    expect(post).toHaveBeenLastCalledWith('member/update-profile', { has_2fa: '1' })
    expect(store.profile).toMatchObject({ has_2fa: 1 })
  })

  it('停用 2FA 直接送 has_2fa "0"（不送 false）', async () => {
    store.profile = { id: 1, has_2fa: 1 }
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: [] })
    await useMember().disableGoogleAuth()
    expect(post).toHaveBeenLastCalledWith('member/update-profile', { has_2fa: '0' })
    expect(store.profile).toMatchObject({ has_2fa: 0 })
  })

  it('IP 變更驗證：目前開 → 送 0；目前關 → 送 1', async () => {
    store.profile = { id: 1, ip_change_mobile_verify: 1, ip_change_email_verify: 0 }
    post.mockResolvedValue({ code: OK, message: 'ok', data: [] })
    const member = useMember()
    await member.toggleIpChangeVerify('mobile')
    expect(post).toHaveBeenLastCalledWith('member/update-profile', { ip_change_mobile_verify: 0 })
    await member.toggleIpChangeVerify('email')
    expect(post).toHaveBeenLastCalledWith('member/update-profile', { ip_change_email_verify: 1 })
    expect(store.profile).toMatchObject({ ip_change_mobile_verify: 0, ip_change_email_verify: 1 })
  })
})

describe('useValidationCode（stub 自動匯入）', () => {
  const post = vi.fn()
  const navigateTo = vi.fn()
  const loadDetail = vi.fn()
  let store: ReturnType<typeof makeStore>
  let timers: ReturnType<typeof makeTimers>
  let loggedIn = true
  let captchaOn = false

  beforeEach(() => {
    post.mockReset()
    navigateTo.mockReset()
    loadDetail.mockReset()
    loggedIn = true
    captchaOn = false
    store = makeStore({ id: 1, payment_password: 0 })
    timers = makeTimers()
    vi.stubGlobal('useApi', () => ({ post }))
    vi.stubGlobal('isApiOk', (res: { code: number }) => res.code === OK)
    vi.stubGlobal('useMemberStore', () => store)
    vi.stubGlobal('useValidationCodeStore', () => timers)
    vi.stubGlobal('useSite', () => ({ flag: (key: string) => key === 'send_code_captcha_enabled' && captchaOn }))
    vi.stubGlobal('useUserSession', () => ({ loggedIn: fakeComputed(() => loggedIn) }))
    vi.stubGlobal('useI18n', () => ({ t: (key: string) => `i18n:${key}` }))
    vi.stubGlobal('useMember', () => ({ load: loadDetail }))
    vi.stubGlobal('navigateTo', navigateTo)
    vi.stubGlobal('computed', fakeComputed)
    vi.stubGlobal('ref', fakeRef)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('sendCode 成功：原樣送參數、依 type:verify_by:目標 起 180 秒倒數', async () => {
    post.mockResolvedValue({ code: OK, message: 'sent', data: [] })
    const codes = useValidationCode()
    const res = await codes.sendCode({ type: 1, verify_by: 1, mobile: '60123456789' })
    expect(res.code).toBe(OK)
    expect(post).toHaveBeenCalledWith('member/send-validation-code', { type: 1, verify_by: 1, mobile: '60123456789' })
    expect(timers.start).toHaveBeenCalledWith('1:1:60123456789', 180)
  })

  it('sendCode 失敗（2081 缺 captcha）不起倒數', async () => {
    post.mockResolvedValue({ code: 2081, message: 'captcha', data: [] })
    const res = await useValidationCode().sendCode({ type: 1, verify_by: 2, email: 'user@example.invalid' })
    expect(res.code).toBe(2081)
    expect(timers.start).not.toHaveBeenCalled()
  })

  it('會員型別卻未登入：不打 API、回自產碼、導回首頁', async () => {
    loggedIn = false
    const res = await useValidationCode().sendCode({ type: 1, verify_by: 1, mobile: '60123456789' })
    expect(post).not.toHaveBeenCalled()
    expect(res.code).toBe(ILLEGAL_LOGIN_STATUS_CODE)
    expect(res.message).toBe('i18n:auth.session.error.illegalLogin')
    expect(navigateTo).toHaveBeenCalledWith('/')
  })

  it('captchaRequired 跟著 template 旗標', () => {
    expect(useValidationCode().captchaRequired.value).toBe(false)
    captchaOn = true
    expect(useValidationCode().captchaRequired.value).toBe(true)
  })

  it('checkCode 型別 1 成功：走 guest/check-validation-code、清該目標倒數、重抓 detail', async () => {
    post.mockResolvedValue({ code: OK, message: 'ok', data: ['tester'] })
    const res = await useValidationCode().checkCode({ token: [REDACTED]', mobile: '60123456789', type: 1 })
    expect(post).toHaveBeenCalledWith('guest/check-validation-code', { token: [REDACTED]', mobile: '60123456789', type: 1 })
    expect(res.code).toBe(OK)
    expect(timers.clearTarget).toHaveBeenCalledWith('60123456789')
    expect(loadDetail).toHaveBeenCalledTimes(1)
  })

  it('checkCode 型別 6 成功：支付密碼旗標變 1、不重抓 detail', async () => {
    post.mockResolvedValue({ code: OK, message: 'ok', data: ['tester'] })
    await useValidationCode().checkCode({ token: [REDACTED]', email: 'user@example.invalid', type: 6 })
    expect(timers.clearTarget).toHaveBeenCalledWith('user@example.invalid')
    expect(store.profile).toMatchObject({ payment_password: 1 })
    expect(loadDetail).not.toHaveBeenCalled()
  })

  it('checkCode 9992 碼錯：不清倒數、不動 store', async () => {
    post.mockResolvedValue({ code: 9992, message: 'wrong', data: [] })
    const res = await useValidationCode().checkCode({ token: [REDACTED]', mobile: '60123456789', type: 11 })
    expect(res.code).toBe(9992)
    expect(timers.clearTarget).not.toHaveBeenCalled()
    expect(store.patchProfile).not.toHaveBeenCalled()
  })

  it('captcha 出題與判定都走 silent，2080 原樣回給元件處理', async () => {
    post.mockResolvedValueOnce({ code: OK, message: 'ok', data: { captcha_id: 'id', bg_width: 300 } })
    const codes = useValidationCode()
    await codes.getCaptcha()
    expect(post).toHaveBeenLastCalledWith('member/captcha', {}, { silent: true })

    post.mockResolvedValueOnce({ code: 2080, message: 'fail', data: [] })
    const res = await codes.verifyCaptcha('id', 191)
    expect(post).toHaveBeenLastCalledWith('member/captcha/verify', { captcha_id: 'id', x: 191 }, { silent: true })
    expect(res.code).toBe(2080)
  })

  it('resetMobile / resetEmail 成功：寫回新值與驗證旗標、清倒數', async () => {
    post.mockResolvedValue({ code: OK, message: 'ok', data: [] })
    const codes = useValidationCode()
    await codes.resetMobile({ token: [REDACTED]', new_mobile: '60199999999' })
    expect(post).toHaveBeenLastCalledWith('member/reset-mobile', { token: [REDACTED]', new_mobile: '60199999999' })
    expect(store.profile).toMatchObject({ mobile: '60199999999', mobile_validate: 1 })
    expect(timers.clearTarget).toHaveBeenCalledWith('60199999999')

    await codes.resetEmail({ token: [REDACTED]', new_email: 'user@example.invalid' })
    expect(post).toHaveBeenLastCalledWith('member/reset-email', { token: [REDACTED]', new_email: 'user@example.invalid' })
    expect(store.profile).toMatchObject({ email: 'user@example.invalid', email_validate: 1 })
  })

  it('resetMobile 9992 不寫回', async () => {
    post.mockResolvedValue({ code: 9992, message: 'bad token', data: [] })
    await useValidationCode().resetMobile({ token: [REDACTED]', new_mobile: '60199999999' })
    expect(store.patchProfile).not.toHaveBeenCalled()
    expect(timers.clearTarget).not.toHaveBeenCalled()
  })
})
