import { computed, onMounted, reactive, ref } from 'vue'
import type { RequestOf } from '#shared/api/registry'
import {
  groupRegisterFields,
  isMobileAsUsername,
  type InvitationCodeState,
  type MemberFieldSetting,
  normalizeMobile,
  normalizeOtp,
  type RegisterFieldIssue,
  resolveInvitationCodeState,
  resolveRegisterErrorField,
  validateRegisterFields,
  readDeviceId,
  validateUsername,
} from '#shared/utils/auth'

/**
 * 註冊表單的邏輯，所有皮共用，畫面在各皮的元件。
 * member_field_settings.register 有設定走 v2 動態欄位（可分步），否則靜態表單。
 * 邀請碼：網址帶碼鎖定；agent/downline-code has_open 開下拉；is_auto_agent_code 預填站台分享碼。
 */
export function useSignupForm(lockedCode: string | null | undefined) {
  const { t } = useI18n()
  const { site, flag } = useSite()
  const { memberFieldSettings, registerMode, invitationCodeType, register, registerV2, fetchDownlineCode } = useAuth()

  const loading = ref(false)
  const message = ref('')

  /** 靜態註冊表單那兩個密碼欄的明碼切換（設計稿每個密碼欄都有眼睛）。 */
  const staticShown = reactive({ pw: false, confirm: false })
  const errorField = ref<string | null>(null)

  /* ------------------------------------------------------------ 邀請碼 */
  const agentList = ref<{ nickname: string, code: string }[]>([])
  const invitation = ref<InvitationCodeState>({ codeType: 2, code: lockedCode ?? '', locked: !!lockedCode })

  onMounted(async () => {
    const res = await fetchDownlineCode()
    const hasOpen = isApiOk(res) && res.data.has_open === 1
    if (isApiOk(res)) agentList.value = res.data.agent_list
    invitation.value = resolveInvitationCodeState({
      urlCode: lockedCode,
      hasOpen,
      isAutoAgentCode: flag('is_auto_agent_code'),
      defaultCode: site.value?.raw.code ?? null,
    })
  })

  /* ------------------------------------------------------------ 靜態表單 */
  const staticForm = reactive({
    username: '',
    password: '',
    password_confirmation: '',
    email: '',
    nickname: '',
    country_code: '',
    phone: '',
    mobile_token: '',
    birthday: '',
  })
  const staticMobile = computed(() => normalizeMobile(staticForm.country_code, staticForm.phone))

  async function submitStatic() {
    message.value = ''
    // 靜態表單沒有後端欄位設定，只做英數檢查（字數規則由後端驗）
    if (validateUsername(staticForm.username) === 'alnum') {
      message.value = t('validation.field.error.columnAlphanumeric', { column: t('auth.common.label.username') })
      return
    }
    if (staticForm.password !== staticForm.password_confirmation) {
      message.value = t('validation.password.error.mismatch')
      return
    }
    const payload: RequestOf<'guest/register'> = {
      username: staticForm.username,
      password: [REDACTED],
      password_confirmation: staticForm.password_confirmation,
      invitation_code_type: invitationCodeType.value,
    }
    if (invitation.value.code) payload.invitation_code = invitation.value.code
    if (staticForm.email) payload.email = staticForm.email
    if (staticForm.nickname) payload.nickname = staticForm.nickname
    if (staticForm.birthday) payload.birthday = staticForm.birthday
    if (staticMobile.value) {
      payload.mobile = staticMobile.value
      payload.mobile_token = [REDACTED]
    }
    loading.value = true
    // 失敗訊息由 useApi 的全站彈窗呈現
    await register(payload)
    loading.value = false
  }

  /* ------------------------------------------------------------ v2 動態表單 */
  const allFields = computed(() => memberFieldSettings.value.register)
  const steps = computed(() => groupRegisterFields(allFields.value))
  const stepIndex = ref(0)
  const currentStep = computed(() => steps.value[stepIndex.value] ?? { group: 1, fields: [] as MemberFieldSetting[] })
  const isLastStep = computed(() => stepIndex.value >= steps.value.length - 1)

  const v2 = reactive<Record<string, string>>({})
  const v2Phone = ref('')
  const v2CountryCode = ref('')
  const v2Mobile = computed(() => normalizeMobile(v2CountryCode.value, v2Phone.value))

  /** 國碼清單：預設選第一筆，與舊皮相同（空白手填會送出沒有國碼的號碼）。 */
  const { countries, ensureCountries, defaultCountry } = useCountries()
  onMounted(async () => {
    await ensureCountries()
    if (!v2CountryCode.value) v2CountryCode.value = defaultCountry()?.country_code ?? ''
  })

  const formValues = computed(() => ({
    values: v2,
    phone: v2Phone.value,
    invitationCode: invitation.value.code,
  }))

  /** 當前步的問題（下一步鈕看這個）。 */
  const stepIssues = computed<RegisterFieldIssue[]>(
    () => validateRegisterFields(allFields.value, currentStep.value.fields, formValues.value),
  )
  /** 全部欄位的問題（送出鈕看這個，對齊舊皮 canSubmit）。 */
  const allIssues = computed<RegisterFieldIssue[]>(
    () => validateRegisterFields(allFields.value, allFields.value, formValues.value),
  )
  /**
   * 使用者協議：只在第一步出現，沒勾就不給下一步、也不給送出。
   * 規則沿用舊皮 C13 SignupFormV2 的 canProceedForm / canSubmitForm。
   */
  const agreed = ref(false)
  const showAgreement = ref(false)
  /**
   * 協議全文裡的 {name} 帶入 template 回的站台名稱（原本語言檔寫死 T-POWER，換品牌就錯）。
   *
   * 注意：{name} 要**當成 vue-i18n 的參數傳**，不能先 t() 再自己字串取代——
   * t() 在回傳前就會把 {name} 插值掉（沒給參數就變空字串），後面再取代已經來不及。
   */
  const agreementHtml = computed(
    () => t('site.legal.text.fullUserAgreement', { name: site.value?.raw.title ?? '' }),
  )
  const isFirstStep = computed(() => stepIndex.value === 0)
  const agreementOk = computed(() => !isFirstStep.value || agreed.value)

  const canProceed = computed(() => stepIssues.value.length === 0 && agreementOk.value)
  const canSubmit = computed(() => allIssues.value.length === 0 && agreed.value)

  /** 按過送出／下一步之後才顯示紅字，避免一進來滿江紅（綠框不受影響，填對就變綠）。 */
  const touched = ref(false)

  function nextStep() {
    touched.value = true
    message.value = ''
    if (!canProceed.value) return
    if (!isLastStep.value) {
      stepIndex.value += 1
      touched.value = false
      errorField.value = null
    }
  }

  function prevStep() {
    if (stepIndex.value === 0) return
    stepIndex.value -= 1
    touched.value = false
    message.value = ''
    errorField.value = null
  }

  async function submitV2() {
    touched.value = true
    message.value = ''
    errorField.value = null
    if (!canSubmit.value) return

    const payload: RequestOf<'guest/register-v2'> = { invitation_code_type: invitationCodeType.value }
    // 帳號取自手機號時送手機號，不送使用者手填的值
    if (isMobileAsUsername(allFields.value)) payload.username = v2Mobile.value
    else if (v2.username) payload.username = v2.username
    if (v2.password) payload.password = [REDACTED]
    if (v2.confirm_password) payload.confirm_password = [REDACTED]
    if (v2.last_name) payload.last_name = v2.last_name
    if (v2Mobile.value) {
      payload.mobile = v2Mobile.value
      if (v2.sms_verify_code) payload.mobile_token = [REDACTED]
    }
    // 國碼恆送（沒有手機欄位的站台也要寫入 register_country_id）
    if (v2CountryCode.value) payload.country_code = v2CountryCode.value
    if (v2.email) {
      payload.email = v2.email
      if (v2.email_verify_code) payload.email_token = [REDACTED]
    }
    if (v2.birthday) payload.birthday = v2.birthday
    if (invitation.value.code) {
      payload.invitation_code = invitation.value.code
      payload.referral_code = invitation.value.code
    }
    const deviceId = readDeviceId()
    if (deviceId) payload.device_id = deviceId

    loading.value = true
    try {
      const res = await registerV2(payload)
      if (isApiOk(res)) return
      // 失敗訊息由 useApi 的全站彈窗呈現；後端錯誤碼定位欄位並翻回該欄位所在的步驟；泛用碼停留當前步
      const field = resolveRegisterErrorField(res.code)
      errorField.value = field
      if (field) {
        const idx = steps.value.findIndex(st => st.fields.some(f => f.field_key === field))
        if (idx >= 0) stepIndex.value = idx
      }
    }
    catch (error) {
      message.value = error instanceof Error ? error.message : String(error)
    }
    finally {
      loading.value = false
    }
  }

  return {
    registerMode,
    loading,
    message,
    staticShown,
    errorField,
    agentList,
    invitation,
    staticForm,
    staticMobile,
    submitStatic,
    allFields,
    steps,
    stepIndex,
    currentStep,
    isLastStep,
    v2,
    v2Phone,
    v2CountryCode,
    countries,
    allIssues,
    agreed,
    showAgreement,
    agreementHtml,
    isFirstStep,
    canProceed,
    canSubmit,
    touched,
    nextStep,
    prevStep,
    submitV2,
  }
}
