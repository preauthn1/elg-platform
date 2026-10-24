import type { RealnameValidationInput } from '#shared/api/endpoints/member'
import {
  MEMBER_PATH,
  buildBankAccount,
  resolveRealnameForm,
  type RealnameForm,
} from '#shared/utils/member'

/**
 * 實名認證申請的表單與送出，所有皮共用，畫面在 components/<皮>/Page/MemberRealNameVerification.vue。
 *待審中／已通過在 middleware/realname.global.ts 就擋掉了，進得來就是可以申請；
 * fields 決定要多收生日／證件號／證件照。
 */
export function useRealnameForm() {
  const realname = useRealnameValidation()
  const { flag, settings } = useSite()

  const loading = ref(true)
  const form = ref<RealnameForm>(resolveRealnameForm({}))
  const submitting = ref(false)

  const name = ref('')
  const bankId = ref('')
  const bankName = ref('')
  const bankAccount = ref('')
  const bsbCode = ref('')
  const birthday = ref('')
  const idNumber = ref('')
  const idFrontImage = ref('')
  const idBackImage = ref('')

  const enableBsb = computed(() => flag('enable_bsb_code'))
  const bsbAlias = computed(() => settings.value?.bsb_code_alias || 'BSB')

  const canSubmit = computed(() => {
    const n = name.value.trim()
    if (n.length < 2 || n.length > 60) return false
    if (form.value.bank.required && (!bankId.value || !bankName.value.trim() || !bankAccount.value.trim())) return false
    if (form.value.birthday.required && !birthday.value) return false
    if (form.value.id_number.required && !idNumber.value.trim()) return false
    if (form.value.id_photo.required && (!idFrontImage.value || !idBackImage.value)) return false
    return true
  })

  function readAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = () => resolve(String(reader.result))
      reader.onerror = () => reject(reader.error)
      reader.readAsDataURL(file)
    })
  }

  async function onPhoto(event: Event, side: 'front' | 'back') {
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) return
    const data = await readAsDataUrl(file)
    if (side === 'front') idFrontImage.value = data
    else idBackImage.value = data
  }

  onMounted(async () => {
    const result = await realname.check()
    loading.value = false
    form.value = result.form
  })

  async function submit() {
    if (!canSubmit.value || submitting.value) return
    const payload: RealnameValidationInput = { realname: name.value.trim() }
    if (form.value.bank.show) {
      payload.bank_id = Number(bankId.value)
      payload.bank_name = bankName.value.trim()
      payload.bank_account = buildBankAccount(bankAccount.value, bsbCode.value, enableBsb.value)
    }
    if (form.value.birthday.show && birthday.value) payload.birthday = birthday.value
    if (form.value.id_number.show && idNumber.value.trim()) payload.id_number = idNumber.value.trim()
    if (form.value.id_photo.show) {
      if (idFrontImage.value) payload.id_front_image = idFrontImage.value
      if (idBackImage.value) payload.id_back_image = idBackImage.value
    }
    submitting.value = true
    const res = await realname.submit(payload)
    submitting.value = false
    if (!isApiOk(res)) return
    useAlert().showResponse(res)
    await navigateTo(MEMBER_PATH.home, { replace: true })
  }

  return {
    loading,
    form,
    submitting,
    name,
    bankId,
    bankName,
    bankAccount,
    bsbCode,
    birthday,
    idNumber,
    enableBsb,
    bsbAlias,
    canSubmit,
    onPhoto,
    submit,
  }
}
