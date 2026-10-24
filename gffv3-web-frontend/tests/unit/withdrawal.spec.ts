import { createRequire } from 'node:module'
import { realpathSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import {
  accountNoLabel,
  amountLabelKey,
  availableForWithdrawal,
  bankInfoFields,
  bankRate,
  banksFor,
  buildBindAccountsPayload,
  buildCreateOrderPayload,
  canEditAccountName,
  canWithdrawal,
  currencyOptions,
  defaultBankInfoData,
  feeAmount,
  feePercentText,
  findSelfWithdrawalType,
  flowRemaining,
  formatDateTime,
  isAmountValid,
  isContactCsBank,
  joinAccountNo,
  netAmount,
  platformPoints,
  prefillFromBoundAccount,
  rateText,
  releaseButtonState,
  resolveAmountLimits,
  resolveVerifyStep,
  safeInternalPath,
  selectedBankFromAccount,
  showAccountNameField,
  showFlowRemaining,
  showOverLimitFee,
  splitAccountNo,
  typeOptions,
  validateBankInfoData,
  type BankLike,
  type SelectedBank,
} from '../../shared/utils/withdrawal'
import {
  decAdd,
  decCmp,
  decDiv,
  decFixed,
  decMul,
  decSub,
} from '../../shared/utils/amount'
import { withdrawalEndpoints } from '../../shared/api/endpoints/withdrawal'
import { endpoints } from '../../shared/api/registry'
import { isApiOk } from '../../app/composables/useApi'
import { useMemberStore } from '../../app/stores/member'
import { useWithdrawalStore } from '../../app/stores/withdrawal'
import { useWithdrawal } from '../../app/composables/useWithdrawal'

interface VueReactivity {
  ref: <T>(value: T) => { value: T }
  computed: <T>(getter: () => T) => { readonly value: T }
}

/** vue 沒有 hoist 到根 node_modules；從 pinia 的實際路徑解析，拿到與 pinia 同一份 vue。 */
async function loadVue(): Promise<VueReactivity> {
  const piniaDir = realpathSync(fileURLToPath(new URL('../../node_modules/pinia', import.meta.url)))
  const entry = createRequire(import.meta.url).resolve('vue', { paths: [piniaDir] })
  const mod = await import(pathToFileURL(entry).href) as VueReactivity & { default?: VueReactivity }
  return mod.default?.ref ? mod.default : mod
}

const vue = await loadVue()
const { ref, computed, reactive } = vue

const bank = (over: Partial<BankLike> = {}): BankLike => ({
  bank_id: 1,
  bank_name: 'Maybank',
  bank_code: 'MBB',
  bank_type: 1,
  bank_info: null,
  rate: '1.0000',
  currency_id: 2,
  currency_name: 'MYR',
  withdrawal_type_id: 1,
  withdrawal_type_name: 'Bank',
  min_amount: '50.00',
  max_amount: '5000.00',
  tax_type: 0,
  tax: '0.00',
  ...over,
})

const selected = (over: Partial<SelectedBank> = {}): SelectedBank => ({
  bank_id: 7,
  bank_name: 'CIMB',
  bank_code: 'CIMB',
  bank_type: 1,
  rate: '1',
  min_amount: '50',
  max_amount: '1000',
  tax_type: 0,
  tax: '0',
  currency_name: 'MYR',
  bank_info: null,
  account_name: 'Tim',
  account_no: '123456',
  bank_info_data: {},
  ...over,
})

describe('十進位運算', () => {
  it('乘減加不吃浮點誤差', () => {
    expect(decMul('0.1', '0.2')).toBe('0.02')
    expect(decSub('1000', '999.99')).toBe('0.01')
    expect(decAdd('0.1', 0.2)).toBe('0.3')
    expect(decMul('3', '1.0000')).toBe('3')
  })

  it('除法無條件捨去到 2 位、除數 0 回 0', () => {
    expect(decDiv('100', '3')).toBe('33.33')
    expect(decDiv('10', '4')).toBe('2.5')
    expect(decDiv('10', '0')).toBe('0')
  })

  it('比較與四捨五入', () => {
    expect(decCmp('50.00', 50)).toBe(0)
    expect(decCmp('49.99', '50')).toBe(-1)
    expect(decCmp('50.01', '50')).toBe(1)
    expect(decFixed('1.005')).toBe('1.01')
    expect(decFixed('1.2')).toBe('1.20')
    expect(decFixed(null)).toBe('0.00')
  })
})

describe('稽核流水', () => {
  const flow = { flow_limit: '1000', reach: '250.5', pass: 0 }

  it('剩餘 = flow_limit - reach，只在開關開且非 0 時顯示', () => {
    expect(flowRemaining(flow)).toBe('749.5')
    expect(showFlowRemaining(flow, true)).toBe(true)
    expect(showFlowRemaining(flow, false)).toBe(false)
    expect(showFlowRemaining({ flow_limit: '10', reach: '10' }, true)).toBe(false)
    expect(flowRemaining(null)).toBe('0')
  })

  it('canWithdrawal：多錢包或扣抵開關直接可提，否則 reach >= flow_limit', () => {
    expect(canWithdrawal(flow, { isMultipleWallet: true, deductFlowLimit: false })).toBe(true)
    expect(canWithdrawal(flow, { isMultipleWallet: false, deductFlowLimit: true })).toBe(true)
    expect(canWithdrawal(flow, { isMultipleWallet: false, deductFlowLimit: false })).toBe(false)
    expect(canWithdrawal({ flow_limit: '100', reach: '100' }, { isMultipleWallet: false, deductFlowLimit: false })).toBe(true)
    expect(canWithdrawal(null, { isMultipleWallet: false, deductFlowLimit: false })).toBe(false)
  })

  it('可提領金額三分支', () => {
    const base = { balance: '2000', flow, rate: '2' }
    expect(availableForWithdrawal({ ...base, isMultipleWallet: true, deductFlowLimit: false })).toBe('1000')
    expect(availableForWithdrawal({ ...base, isMultipleWallet: false, deductFlowLimit: true })).toBe('625.25')
    expect(availableForWithdrawal({ ...base, balance: '100', isMultipleWallet: false, deductFlowLimit: true })).toBe('0')
    expect(availableForWithdrawal({ ...base, isMultipleWallet: false, deductFlowLimit: false })).toBeNull()
  })

  it('rate 取 rate || bank_rate，缺省 1', () => {
    expect(bankRate({ rate: '1.5' })).toBe('1.5')
    expect(bankRate({ bank_rate: '2.0000' })).toBe('2')
    expect(bankRate({ rate: '0' })).toBe('1')
    expect(bankRate(null)).toBe('1')
  })

  it('垃圾桶按鈕狀態', () => {
    expect(releaseButtonState({ flowLimitApplication: 2, balance: '100', flowLimit: '0' })).toBe('pending')
    expect(releaseButtonState({ flowLimitApplication: 0, balance: '0.99', flowLimit: '10' })).toBe('enabled')
    expect(releaseButtonState({ flowLimitApplication: 0, balance: '1', flowLimit: '10' })).toBe('hidden')
    expect(releaseButtonState({ flowLimitApplication: 0, balance: '0', flowLimit: '0' })).toBe('hidden')
  })
})

describe('限額與費用', () => {
  it('上下限：所選優先、0/空退回頂層、min > max 以 max 為 min', () => {
    const top = { min_amount: '10', max_amount: '100' }
    expect(resolveAmountLimits({ min_amount: '20', max_amount: '80' }, top)).toEqual({ min: '20', max: '80' })
    expect(resolveAmountLimits({ min_amount: '0', max_amount: '' }, top)).toEqual({ min: '10', max: '100' })
    expect(resolveAmountLimits({ min_amount: '500', max_amount: '100' }, top)).toEqual({ min: '100', max: '100' })
    expect(resolveAmountLimits(null, null)).toEqual({ min: '0', max: '0' })
  })

  it('金額驗證', () => {
    const limits = { min: '50', max: '1000' }
    expect(isAmountValid('50', limits)).toBe(true)
    expect(isAmountValid('1000', limits)).toBe(true)
    expect(isAmountValid('49.99', limits)).toBe(false)
    expect(isAmountValid('1000.01', limits)).toBe(false)
    expect(isAmountValid('abc', limits)).toBe(false)
    expect(isAmountValid('', limits)).toBe(false)
    expect(isAmountValid('-5', limits)).toBe(false)
  })

  it('超次手續費顯示條件', () => {
    expect(showOverLimitFee({ withdrawal_times: 3, withdrawal_times_limit: 2, vip_tax: '0.01' })).toBe(true)
    expect(showOverLimitFee({ withdrawal_times: 2, withdrawal_times_limit: 2, vip_tax: '0.01' })).toBe(false)
    expect(showOverLimitFee({ withdrawal_times: 3, withdrawal_times_limit: -1, vip_tax: '0.01' })).toBe(false)
    expect(showOverLimitFee({ withdrawal_times: 3, withdrawal_times_limit: null, vip_tax: '0.01' })).toBe(false)
    expect(showOverLimitFee({ withdrawal_times: 3, withdrawal_times_limit: 2, vip_tax: null })).toBe(false)
    expect(showOverLimitFee(null)).toBe(false)
  })

  it('手續費與實得', () => {
    expect(feeAmount('100', 0, '5')).toBe('5')
    expect(feeAmount('100', 1, '0.025')).toBe('2.5')
    expect(feePercentText('0.025')).toBe('2.5')
    expect(netAmount({ amount: '100', taxType: 0, tax: '5' })).toBe('95')
    expect(netAmount({ amount: '100', taxType: 1, tax: '0.025' })).toBe('97.5')
    expect(netAmount({ amount: '100', taxType: 1, tax: '0.025', vipTax: '0.01', applyOverLimitFee: true })).toBe('96.5')
  })

  it('點數換算與匯率文字', () => {
    expect(platformPoints('100', '4.5')).toBe('450')
    expect(platformPoints('100', null)).toBe('100')
    expect(rateText('1.0000', 'Points', 'MYR')).toBeNull()
    expect(rateText('4.5', 'Points', 'MYR')).toBe('4.5 Points = 1 MYR')
  })
})

describe('銀行清單與欄位', () => {
  const banks = [
    bank({ bank_id: 1, currency_id: 2, currency_name: 'MYR', withdrawal_type_id: 1, withdrawal_type_name: 'Bank' }),
    bank({ bank_id: 2, currency_id: 2, currency_name: 'MYR', withdrawal_type_id: 3, withdrawal_type_name: 'USDT', bank_type: 3 }),
    bank({ bank_id: 3, currency_id: 5, currency_name: 'SGD', withdrawal_type_id: 1, withdrawal_type_name: 'Bank' }),
    bank({ bank_id: 4, currency_id: 0, currency_name: '', withdrawal_type_id: 5, bank_type: 5 }),
  ]

  it('幣別/代付方式去重、依類型過濾', () => {
    expect(currencyOptions(banks)).toEqual([{ id: 2, name: 'MYR' }, { id: 5, name: 'SGD' }])
    expect(typeOptions(banks, 2)).toEqual([{ id: 1, name: 'Bank' }, { id: 3, name: 'USDT' }])
    expect(banksFor(banks, 2, 1).map(b => b.bank_id)).toEqual([1])
    expect(banksFor(banks, null, null)).toHaveLength(4)
  })

  it('bank_type 5 / withdrawal_type_id 5 導客服', () => {
    expect(isContactCsBank(banks[3])).toBe(true)
    expect(isContactCsBank({ bank_type: 1, withdrawal_type_id: 5 })).toBe(true)
    expect(isContactCsBank(banks[0])).toBe(false)
    expect(isContactCsBank(null)).toBe(false)
  })

  const bankInfo = {
    accountNo: { placeholder: 'UPI ID' },
    mobile: { type: 'text', display: 'Mobile', regex: '^\\d{10}$' },
    ifsc: { type: 'select', display: 'IFSC', options: [{ text: 'A', value: 'A1' }, { text: 'B', value: 'B2' }] },
  }

  it('bank_info 動態欄位排除 accountNo、select 預設第一個、regex 檢查', () => {
    expect(bankInfoFields(bankInfo).map(f => f.name)).toEqual(['mobile', 'ifsc'])
    expect(bankInfoFields([])).toEqual([])
    expect(defaultBankInfoData(bankInfo)).toEqual({ mobile: '', ifsc: 'A1' })
    expect(validateBankInfoData(bankInfo, { mobile: '123', ifsc: 'A1' })).toEqual(['mobile'])
    expect(validateBankInfoData(bankInfo, { mobile: '0123456789', ifsc: '' })).toEqual(['ifsc'])
    expect(validateBankInfoData(bankInfo, { mobile: '0123456789', ifsc: 'B2' })).toEqual([])
  })

  it('帳號欄位名稱依 placeholder 與 bank_type', () => {
    expect(accountNoLabel({ bank_type: 1, bank_info: bankInfo })).toEqual({ text: 'UPI ID' })
    expect(accountNoLabel({ bank_type: 2 })).toEqual({ key: 'auth.common.label.phoneNumber' })
    expect(accountNoLabel({ bank_type: 3 })).toEqual({ key: 'common.bank.label.walletAddress' })
    expect(accountNoLabel({ bank_type: 7 })).toEqual({ text: 'Pay_Id' })
    expect(accountNoLabel({ bank_type: 1 })).toEqual({ key: 'common.bank.label.accountNo' })
    expect(showAccountNameField(2)).toBe(false)
    expect(showAccountNameField(3)).toBe(false)
    expect(showAccountNameField(1)).toBe(true)
    expect(amountLabelKey(3)).toBe('wallet.exchange.label.amount')
    expect(amountLabelKey(1)).toBe('common.amount.label.amount')
  })

  it('BSB 帳號拆合與已綁卡帶入', () => {
    expect(joinAccountNo('012', ' 3456 ')).toBe('012__3456')
    expect(joinAccountNo('', '3456')).toBe('3456')
    expect(splitAccountNo('012__3456', 6)).toEqual({ bsbCode: '012', accountNo: '3456' })
    expect(splitAccountNo('012__3456', 1)).toEqual({ bsbCode: '', accountNo: '012__3456' })
    const accounts = [{ bank_id: 9, bank_name: 'x', bank_code: 'x', bank_type: 6, account_name: 'Amy', account_no: '111__222' }]
    expect(prefillFromBoundAccount(accounts, 9, 6)).toEqual({ account_name: 'Amy', accountNo: '222', bsbCode: '111' })
    expect(prefillFromBoundAccount(accounts, 1, 6)).toBeNull()
  })

  it('帳戶名可編輯規則', () => {
    expect(canEditAccountName({ realNameVerifiedVisible: false, is_first: 0, account_name_modify: 0 })).toBe(true)
    expect(canEditAccountName({ realNameVerifiedVisible: true, is_first: 1, account_name_modify: 0 })).toBe(true)
    expect(canEditAccountName({ realNameVerifiedVisible: true, is_first: 0, account_name_modify: 1 })).toBe(true)
    expect(canEditAccountName({ realNameVerifiedVisible: true, is_first: 0, account_name_modify: 0 })).toBe(false)
  })

  it('已綁卡 → 選定銀行，缺欄位從銀行清單補', () => {
    const picked = selectedBankFromAccount(
      { bank_id: 2, bank_name: 'USDT', bank_code: 'TRC', bank_type: 3, account_no: 'T123', bank_rate: '4.5', tax_type: 1, bank_tax: '0.01' },
      banks,
    )
    expect(picked.rate).toBe('4.5')
    expect(picked.currency_name).toBe('MYR')
    expect(picked.account_name).toBe('')
    expect(picked.min_amount).toBe('50.00')
  })
})

describe('payload 組法', () => {
  it('create-order：帳戶欄位 + bank_info_data spread，amount 轉數字', () => {
    const payload = buildCreateOrderPayload(selected({ bank_info_data: { mobile: '0123', ifsc: 'X' } }), '100.50')
    expect(payload).toEqual({
      mobile: '0123',
      ifsc: 'X',
      amount: 100.5,
      account_name: 'Tim',
      account_no: '123456',
      bank_id: 7,
      bank_name: 'CIMB',
      bank_code: 'CIMB',
    })
    expect(withdrawalEndpoints['withdrawal/create-order'].request.parse(payload)).toMatchObject({ mobile: '0123', ifsc: 'X', amount: 100.5 })
  })

  it('多組綁卡：只送完整的組、不完整擋下、全空擋下', () => {
    expect(buildBindAccountsPayload([
      { bank_id: 1, account_name: 'A', account_no: '111', bsb_code: '' },
      { bank_id: null, account_name: '', account_no: '' },
      { bank_id: 2, account_name: 'B', account_no: '222', bsb_code: '033' },
    ])).toEqual({
      payload: [
        { bank_id: 1, account_name: 'A', account_no: '111' },
        { bank_id: 2, account_name: 'B', account_no: '033__222' },
      ],
      error: null,
    })
    expect(buildBindAccountsPayload([{ bank_id: 1, account_name: '', account_no: '111' }]).error).toBe('incomplete')
    expect(buildBindAccountsPayload([{ bank_id: null, account_name: '', account_no: '' }]).error).toBe('empty')
  })
})

describe('驗證流程與導頁', () => {
  it('支付密碼優先於簡訊', () => {
    expect(resolveVerifyStep({ paymentPasswordVisible: true, mobileValidate: true })).toBe('payment-password')
    expect(resolveVerifyStep({ paymentPasswordVisible: false, mobileValidate: true })).toBe('sms')
    expect(resolveVerifyStep({ paymentPasswordVisible: false, mobileValidate: false })).toBe('none')
  })

  it('自助洗分 gate 找 cf_id 6', () => {
    expect(findSelfWithdrawalType([{ cf_id: 1 }, { cf_id: '6', popup_text: 'hi' }])).toEqual({ cf_id: '6', popup_text: 'hi' })
    expect(findSelfWithdrawalType([{ cf_id: 1 }])).toBeNull()
    expect(findSelfWithdrawalType(null)).toBeNull()
  })

  it('redirect query 只接受站內路徑；時間格式 yyyy-MM-dd HH:mm:ss', () => {
    expect(safeInternalPath('/my')).toBe('/my')
    expect(safeInternalPath('//example.invalid')).toBe('')
    expect(safeInternalPath('https://example.invalid')).toBe('')
    expect(safeInternalPath(undefined)).toBe('')
    expect(formatDateTime(new Date(2026, 0, 5, 9, 7, 3))).toBe('2026-01-05 09:07:03')
  })
})

describe('endpoint 契約', () => {
  it('7 條端點登記、withdrawal/orders 不在本領域', () => {
    const keys = Object.keys(withdrawalEndpoints).sort()
    expect(keys).toEqual([
      'audit/release-application',
      'withdrawal/banks',
      'withdrawal/bind-bank-account',
      'withdrawal/check-bank-account',
      'withdrawal/confirm-payment-password',
      'withdrawal/create-order',
      'withdrawal/flow-limit',
    ])
    expect(withdrawalEndpoints).not.toHaveProperty('withdrawal/orders')
    for (const def of Object.values(withdrawalEndpoints)) {
      expect(def.auth).toBe('member')
      expect(def.withDomain).toBe(false)
    }
    expect(endpoints).toHaveProperty('withdrawal/create-order')
  })

  it('request：bind-bank-account 帳號不可含空白、type 可 coerce、支付密碼 6-12 碼', () => {
    const bind = withdrawalEndpoints['withdrawal/bind-bank-account'].request
    expect(bind.safeParse({ account: [{ bank_id: '3', account_name: null, account_no: '123' }] }).success).toBe(true)
    expect(bind.safeParse({ account: [{ bank_id: 3, account_no: '12 3' }] }).success).toBe(false)
    expect(bind.safeParse({ account: [] }).success).toBe(false)

    const check = withdrawalEndpoints['withdrawal/check-bank-account'].request
    expect(check.parse({ type: '-1' })).toEqual({ type: -1 })
    expect(check.parse({})).toEqual({})

    const pw = withdrawalEndpoints['withdrawal/confirm-payment-password'].request
    expect(pw.safeParse({ payment_password: [REDACTED]' }).success).toBe(false)
    expect(pw.safeParse({ payment_password: [REDACTED]' }).success).toBe(true)

    const order = withdrawalEndpoints['withdrawal/create-order'].request
    expect(order.safeParse({ amount: 0.5, account_no: 'x', bank_id: 1, bank_name: 'n', bank_code: 'c' }).success).toBe(false)
    expect(order.parse({ amount: '20', account_no: ' x ', bank_id: '1', bank_name: 'n', bank_code: 'c', extra: 'kept' }))
      .toEqual({ amount: 20, account_no: 'x', bank_id: 1, bank_name: 'n', bank_code: 'c', extra: 'kept' })
  })

  it('response：check-bank-account 與 banks 照後端原樣', () => {
    const check = withdrawalEndpoints['withdrawal/check-bank-account'].response.parse({
      has_bank_card_setting: 1,
      has_bank_card: 0,
      bank_card_amount: 3,
      country_code: null,
      mobile: null,
      account_name: 'Tim',
      account: null,
      min_amount: '50.00',
      max_amount: '5000.00',
      is_first: 1,
      account_name_modify: 0,
      has_login_event_reward: 0,
      withdrawal_times: 1,
      withdrawal_times_limit: null,
      over_times: 0,
      vip_tax: '0.00',
    })
    expect(check.has_bank_card_setting).toBe(1)
    expect(check.account).toBeNull()
    expect(check.withdrawal_times_limit).toBeNull()

    const banks = withdrawalEndpoints['withdrawal/banks'].response.parse([{
      bank_id: 1,
      bank_name: 'Maybank',
      bank_icon: null,
      bank_info: { accountNo: { placeholder: 'No' } },
      bank_code: 'MBB',
      bank_type: 1,
      rate: '1.0000',
      currency_id: 2,
      currency_name: 'MYR',
      withdrawal_type_id: 1,
      withdrawal_type_name: 'Bank',
      min_amount: '50.00',
      max_amount: '5000.00',
      tax_type: 0,
      tax: '0.00',
      unknown_key: 'kept',
    }])
    expect(banks[0]?.bank_id).toBe(1)
    expect((banks[0] as Record<string, unknown>).unknown_key).toBe('kept')

    const flow = withdrawalEndpoints['withdrawal/flow-limit'].response.parse({ flow_limit: '100', reach: '20', pass: '0', message: 'x' })
    expect(flow.pass).toBe(0)
  })
})

describe('useWithdrawal composable', () => {
  const post = vi.fn()
  const navigateTo = vi.fn()
  const clearSession = vi.fn()
  // 站台旗標要是響應式的，測試中途改值時 composable 的 computed 才會重算
  const flags = reactive<Record<string, boolean>>({})
  function setFlags(next: Record<string, boolean> = {}) {
    // 用 false 覆寫而不是 delete：flag() 讀不到就回 false，效果相同且不動到 key
    for (const key of Object.keys(flags)) flags[key] = false
    Object.assign(flags, next)
  }
  let settings: Record<string, unknown> = {}

  const ok = <T>(data: T) => ({ code: 200, message: 'ok', data })
  const fail = (code: number, message = 'err') => ({ code, message, data: null })

  function useMock(responses: Record<string, unknown>) {
    post.mockImplementation(async (route: string) => responses[route] ?? ok([]))
  }

  function callsTo(route: string) {
    return post.mock.calls.filter(c => c[0] === route)
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    post.mockReset()
    navigateTo.mockReset()
    clearSession.mockReset()
    setFlags()
    settings = {}
    vi.stubGlobal('ref', ref)
    vi.stubGlobal('computed', computed)
    vi.stubGlobal('useApi', () => ({ post }))
    vi.stubGlobal('isApiOk', isApiOk)
    vi.stubGlobal('navigateTo', navigateTo)
    vi.stubGlobal('useUserSession', () => ({ clear: clearSession }))
    vi.stubGlobal('useSite', () => ({
      flag: (key: string) => flags[key] ?? false,
      settings: computed(() => settings),
    }))
    vi.stubGlobal('useWithdrawalStore', useWithdrawalStore)
    vi.stubGlobal('useMemberStore', useMemberStore)
  })

  it('fetchFlowLimit 成功寫進 store', async () => {
    useMock({ 'withdrawal/flow-limit': ok({ flow_limit: '100', reach: '10', pass: 0 }) })
    const w = useWithdrawal()
    const res = await w.fetchFlowLimit()
    expect(res.code).toBe(200)
    expect(w.store.flowLimit?.flow_limit).toBe('100')
    expect(w.remainingFlow.value).toBe('90')
    expect(callsTo('withdrawal/flow-limit')[0]?.[1]).toEqual({})
  })

  it('fetchCheckBankAccount：不帶/帶 type；錯誤碼不自己導頁（324、321 交給 useApi 全域彈窗關掉後導）', async () => {
    useMock({ 'withdrawal/check-bank-account': ok({ has_bank_card_setting: 0, account: null, account_name: 'Tim' }) })
    const w = useWithdrawal()
    await w.fetchCheckBankAccount()
    await w.fetchCheckBankAccount(3)
    expect(callsTo('withdrawal/check-bank-account').map(c => c[1])).toEqual([{}, { type: 3 }])
    expect(w.store.checkInfo?.account_name).toBe('Tim')

    for (const code of [324, 321]) {
      useMock({ 'withdrawal/check-bank-account': fail(code) })
      await w.fetchCheckBankAccount()
    }
    expect(navigateTo).not.toHaveBeenCalled()
  })

  it('confirmPaymentPassword：送 payment_password，錯誤碼不自己導頁（334、319 由 useApi 全域處理）', async () => {
    const w = useWithdrawal()
    useMock({ 'withdrawal/confirm-payment-password': fail(334) })
    await w.confirmPaymentPassword('123456')
    expect(callsTo('withdrawal/confirm-payment-password')[0]?.[1]).toEqual({ payment_password: [REDACTED]' })

    useMock({ 'withdrawal/confirm-payment-password': fail(400, 'wrong') })
    const res = await w.confirmPaymentPassword('123456')
    expect(res.message).toBe('wrong')
    expect(navigateTo).not.toHaveBeenCalled()
    expect(clearSession).not.toHaveBeenCalled()
  })

  it('createOrder：沒選銀行回 422；成功重抓 member/detail 與 flow-limit；324 不自己導頁', async () => {
    const w = useWithdrawal()
    expect((await w.createOrder('100')).code).toBe(422)

    w.store.setSelectedBank(selected({ bank_info_data: { ifsc: 'IF' } }))
    useMock({
      'withdrawal/create-order': ok([]),
      'member/detail': ok({ balance: '900', mobile_validate: 1 }),
      'withdrawal/flow-limit': ok({ flow_limit: '1', reach: '1', pass: 1 }),
    })
    const res = await w.createOrder('100')
    expect(res.code).toBe(200)
    expect(callsTo('withdrawal/create-order')[0]?.[1]).toEqual({
      ifsc: 'IF',
      amount: 100,
      account_name: 'Tim',
      account_no: '123456',
      bank_id: 7,
      bank_name: 'CIMB',
      bank_code: 'CIMB',
    })
    expect(callsTo('member/detail')).toHaveLength(1)
    expect(callsTo('withdrawal/flow-limit')).toHaveLength(1)
    expect(w.memberBalance.value).toBe('900')

    useMock({ 'withdrawal/create-order': fail(324) })
    await w.createOrder('100')
    expect(navigateTo).not.toHaveBeenCalled()

    useMock({ 'withdrawal/create-order': fail(400, '1980') })
    expect((await w.createOrder('100')).message).toBe('1980')
    expect(navigateTo).not.toHaveBeenCalled()
  })

  it('applyReleaseApplication：成功才重抓 member/detail 與 flow-limit', async () => {
    const w = useWithdrawal()
    useMock({ 'audit/release-application': fail(400, 'already_apply') })
    expect((await w.applyReleaseApplication()).code).toBe(400)
    expect(callsTo('member/detail')).toHaveLength(0)

    useMock({
      'audit/release-application': ok([]),
      'member/detail': ok({ flow_limit_application: 2, balance: '0.5' }),
      'withdrawal/flow-limit': ok({ flow_limit: '10', reach: '0', pass: 0 }),
    })
    expect((await w.applyReleaseApplication()).code).toBe(200)
    expect(callsTo('member/detail')).toHaveLength(1)
    expect(callsTo('withdrawal/flow-limit')).toHaveLength(1)
    expect(w.releaseState.value).toBe('pending')
  })

  it('checkEntryGate：無 cf_id 6 不開放、有 popup_text 帶回、呼叫失敗不阻擋', async () => {
    const w = useWithdrawal()
    useMock({ 'deposit/top-up-types': ok([{ cf_id: 1 }]) })
    expect(await w.checkEntryGate()).toEqual({ allowed: false, popupText: null })

    useMock({ 'deposit/top-up-types': ok([{ cf_id: 6, popup_text: 'notice' }]) })
    expect(await w.checkEntryGate()).toEqual({ allowed: true, popupText: 'notice' })

    useMock({ 'deposit/top-up-types': fail(404) })
    expect(await w.checkEntryGate()).toEqual({ allowed: true, popupText: null })
  })

  it('簡訊驗證：type 7、verify_by 1、mobile 取自會員資料', async () => {
    const w = useWithdrawal()
    useMock({ 'member/detail': ok({ mobile: '60123456789', mobile_validate: 1, payment_password: 0 }) })
    await w.fetchMemberProfile()
    await w.sendSmsCode()
    await w.checkSmsCode('123456')
    expect(callsTo('member/send-validation-code')[0]?.[1]).toEqual({ type: 7, verify_by: 1, mobile: '60123456789' })
    expect(callsTo('guest/check-validation-code')[0]?.[1]).toEqual({ type: 7, mobile: '60123456789', token: [REDACTED]' })
  })

  it('verifyStep / needSetPaymentPassword 依站台旗標與會員資料', async () => {
    const w = useWithdrawal()
    useMock({ 'member/detail': ok({ mobile_validate: 1, payment_password: 0 }) })
    await w.fetchMemberProfile()
    expect(w.verifyStep.value).toBe('sms')
    expect(w.needSetPaymentPassword.value).toBe(false)

    setFlags({ payment_password_visible: true })
    expect(w.verifyStep.value).toBe('payment-password')
    expect(w.needSetPaymentPassword.value).toBe(true)

    useMock({ 'member/detail': ok({ mobile_validate: 0, payment_password: 1 }) })
    await w.fetchMemberProfile()
    expect(w.needSetPaymentPassword.value).toBe(false)
    setFlags()
    expect(w.verifyStep.value).toBe('none')
  })

  it('availableAmount 走 is_multiple_wallet / withdrawal_deduct_flow_limit 旗標', async () => {
    const w = useWithdrawal()
    useMock({
      'member/detail': ok({ balance: '2000' }),
      'withdrawal/flow-limit': ok({ flow_limit: '1000', reach: '250', pass: 0 }),
    })
    await w.fetchMemberProfile()
    await w.fetchFlowLimit()
    expect(w.availableAmount('2')).toBeNull()
    setFlags({ withdrawal_deduct_flow_limit: true })
    expect(w.availableAmount('2')).toBe('625')
    setFlags({ is_multiple_wallet: true })
    expect(w.availableAmount('2')).toBe('1000')
    expect(w.canWithdraw.value).toBe(true)
  })
})
