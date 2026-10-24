import { describe, expect, it } from 'vitest'
import type { MemberFieldSetting } from '../../shared/utils/auth'
import {
  enabledPasswordRules,
  groupRegisterFields,
  isAlphanumericOnlyField,
  isMobileAsUsername,
  isMobileLengthValid,
  isRenderedRegisterField,
  mobileLengthRange,
  normalizePhoneInput,
  registerLengthConfig,
  validateRegisterFields,
} from '../../shared/utils/auth'

/**
 * 註冊欄位的規則全部由後端 member_field_settings.register 決定，
 * 這裡鎖住「前端不自己發明規則、也不漏掉後端設的規則」這件事。
 * 對照來源是舊皮 _source2/src/mixins/registerFields.js。
 */

const field = (key: string, extra: Partial<MemberFieldSetting> = {}): MemberFieldSetting => ({
  field_key: key,
  is_required: 0,
  field_group: 1,
  ...extra,
})

const emptyForm = { values: {} as Record<string, string>, phone: '', invitationCode: '' }

describe('註冊欄位：順序與顯示', () => {
  it('依 field_group 分步，組內依 sort', () => {
    const fields = [
      field('password', { field_group: 2, sort: 2 }),
      field('username', { field_group: 1, sort: 2 }),
      field('referral_code', { field_group: 1, sort: 1 }),
      field('mobile', { field_group: 2, sort: 1 }),
    ]
    const steps = groupRegisterFields(fields)
    expect(steps.map(s => s.group)).toEqual([1, 2])
    expect(steps[0]?.fields.map(f => f.field_key)).toEqual(['referral_code', 'username'])
    expect(steps[1]?.fields.map(f => f.field_key)).toEqual(['mobile', 'password'])
  })

  it('驗證碼欄位不單獨佔位（併在手機／信箱區塊裡）', () => {
    expect(isRenderedRegisterField('sms_verify_code')).toBe(false)
    expect(isRenderedRegisterField('email_verify_code')).toBe(false)
    expect(isRenderedRegisterField('referral_code')).toBe(true)
    expect(isRenderedRegisterField('mobile')).toBe(true)
  })
})

describe('註冊欄位：字數設定', () => {
  it('確認密碼沒設字數時沿用密碼的設定', () => {
    const fields = [
      field('password', { extra_config: { min_length: 6, max_length: 12 } }),
      field('confirm_password'),
    ]
    expect(registerLengthConfig(fields, fields[1]!)).toEqual({ min_length: 6, max_length: 12 })
  })

  it('確認密碼自己有設就用自己的', () => {
    const fields = [
      field('password', { extra_config: { min_length: 6, max_length: 12 } }),
      field('confirm_password', { extra_config: { min_length: 8, max_length: 20 } }),
    ]
    expect(registerLengthConfig(fields, fields[1]!)).toEqual({ min_length: 8, max_length: 20 })
  })

  it('手機長度後台有設優先，沒設沿用 7-13', () => {
    expect(mobileLengthRange([field('mobile')])).toEqual({ min: 7, max: 13, fromConfig: false })
    expect(mobileLengthRange([field('mobile', { extra_config: { min_length: 8, max_length: 11 } })]))
      .toEqual({ min: 8, max: 11, fromConfig: true })
  })

  it('手機長度判斷只看數字，且與發碼鈕同一把尺', () => {
    const fields = [field('mobile', { extra_config: { min_length: 8, max_length: 11 } })]
    expect(isMobileLengthValid(fields, '1234567')).toBe(false)
    expect(isMobileLengthValid(fields, '12345678')).toBe(true)
    expect(isMobileLengthValid(fields, '123456789012')).toBe(false)
    expect(isMobileLengthValid(fields, '')).toBe(false)
  })

  it('手機輸入去掉非數字與開頭的 0', () => {
    expect(normalizePhoneInput('012-345 678')).toBe('12345678')
    expect(normalizePhoneInput('0012')).toBe('12')
  })
})

describe('註冊驗證：必填與字數只照後端設定', () => {
  it('後台沒設字數就不擋字數', () => {
    const fields = [field('username', { is_required: 1 })]
    const form = { ...emptyForm, values: { username: 'ab' } }
    expect(validateRegisterFields(fields, fields, form)).toEqual([])
  })

  it('後台有設字數才擋', () => {
    const fields = [field('username', { is_required: 1, extra_config: { min_length: 4, max_length: 10 } })]
    const short = validateRegisterFields(fields, fields, { ...emptyForm, values: { username: 'ab' } })
    expect(short).toEqual([{ field: 'username', kind: 'length', min: 4, max: 10 }])
    expect(validateRegisterFields(fields, fields, { ...emptyForm, values: { username: 'abcd' } })).toEqual([])
  })

  it('必填空值回 required，非必填空值放行', () => {
    const required = [field('last_name', { is_required: 1 })]
    expect(validateRegisterFields(required, required, emptyForm)).toEqual([{ field: 'last_name', kind: 'required' }])
    const optional = [field('last_name')]
    expect(validateRegisterFields(optional, optional, emptyForm)).toEqual([])
  })

  it('帳號／密碼／確認密碼只收英數', () => {
    const fields = [field('password')]
    const issues = validateRegisterFields(fields, fields, { ...emptyForm, values: { password: [REDACTED] def' } })
    expect(issues).toContainEqual({ field: 'password', kind: 'alnum' })
  })

  it('密碼規則只取開著的項目，順序照後端', () => {
    const fields = [field('password', { extra_config: { rules: { uppercase: false, symbol: true, number: true, alphabet: true } } })]
    expect(enabledPasswordRules(fields)).toEqual(['alphabet', 'number', 'symbol'])
    expect(enabledPasswordRules([field('password')])).toEqual([])
  })

  it('後台要求密碼含符號時，密碼與確認密碼不擋符號，帳號照擋', () => {
    const fields = [
      field('username'),
      field('password', { extra_config: { rules: { alphabet: true, number: true, symbol: true } } }),
      field('confirm_password'),
    ]
    const issues = validateRegisterFields(fields, fields, {
      ...emptyForm,
      values: { username: 'ab!c', password: [REDACTED]', confirm_password: [REDACTED]' },
    })
    expect(issues).toEqual([{ field: 'username', kind: 'alnum' }])
    expect(isAlphanumericOnlyField(fields, 'password')).toBe(false)
    expect(isAlphanumericOnlyField(fields, 'confirm_password')).toBe(false)
  })

  it('沒開符號規則時密碼照舊只收英數', () => {
    const fields = [field('password', { extra_config: { rules: { alphabet: true, symbol: false } } })]
    expect(isAlphanumericOnlyField(fields, 'password')).toBe(true)
  })

  it('確認密碼一定要與密碼相同，與 is_required 無關', () => {
    const fields = [field('password'), field('confirm_password')]
    const issues = validateRegisterFields(fields, fields, { ...emptyForm, values: { password: [REDACTED]', confirm_password: '' } })
    expect(issues).toContainEqual({ field: 'confirm_password', kind: 'mismatch' })
  })

  it('lowercase_username 打大寫要擋', () => {
    const fields = [field('username', { extra_config: { lowercase_username: true } })]
    const issues = validateRegisterFields(fields, fields, { ...emptyForm, values: { username: 'Abc123' } })
    expect(issues).toContainEqual({ field: 'username', kind: 'lowercase' })
  })

  it('mobile_as_username 時帳號不用手填', () => {
    const fields = [
      field('username', { is_required: 1, extra_config: { mobile_as_username: true } }),
      field('mobile'),
    ]
    expect(isMobileAsUsername(fields)).toBe(true)
    const issues = validateRegisterFields(fields, fields, { ...emptyForm, phone: '123456789', values: { sms_verify_code: '123456' } })
    expect(issues.some(i => i.field === 'username')).toBe(false)
  })
})

describe('註冊驗證：手機／信箱／邀請碼的專屬規則', () => {
  it('有 mobile 欄位就一定要長度合法＋簡訊驗證碼（不看 is_required）', () => {
    const fields = [field('mobile')]
    const issues = validateRegisterFields(fields, fields, emptyForm)
    expect(issues).toContainEqual({ field: 'mobile', kind: 'mobileLength', min: 7, max: 13 })
    expect(issues).toContainEqual({ field: 'sms_verify_code', kind: 'codeRequired' })

    const filled = validateRegisterFields(fields, fields, { ...emptyForm, phone: '123456789', values: { sms_verify_code: '123456' } })
    expect(filled).toEqual([])
  })

  it('有 email 欄位就一定要填值＋郵件驗證碼', () => {
    const fields = [field('email')]
    const issues = validateRegisterFields(fields, fields, emptyForm)
    expect(issues).toContainEqual({ field: 'email', kind: 'required' })
    expect(issues).toContainEqual({ field: 'email_verify_code', kind: 'codeRequired' })
  })

  it('邀請碼必填時手填或選代理擇一有值即可', () => {
    const fields = [field('referral_code', { is_required: 1 })]
    expect(validateRegisterFields(fields, fields, emptyForm)).toEqual([{ field: 'referral_code', kind: 'required' }])
    expect(validateRegisterFields(fields, fields, { ...emptyForm, invitationCode: 'ABC' })).toEqual([])
  })

  it('邀請碼非必填時空著也能過', () => {
    const fields = [field('referral_code')]
    expect(validateRegisterFields(fields, fields, emptyForm)).toEqual([])
  })
})

describe('註冊驗證：分步只驗當前步，送出驗全部', () => {
  const fields = [
    field('username', { is_required: 1, field_group: 1 }),
    field('password', { is_required: 1, field_group: 2 }),
  ]

  it('只驗第一步時，第二步的必填不算問題', () => {
    const step1 = fields.filter(f => f.field_group === 1)
    const form = { ...emptyForm, values: { username: 'abc' } }
    expect(validateRegisterFields(fields, step1, form)).toEqual([])
  })

  it('驗全部時，第二步的必填會被抓出來', () => {
    const form = { ...emptyForm, values: { username: 'abc' } }
    expect(validateRegisterFields(fields, fields, form)).toEqual([{ field: 'password', kind: 'required' }])
  })
})
