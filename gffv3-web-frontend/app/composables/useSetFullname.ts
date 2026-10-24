import { hasValue, resolveSetFullnameRedirect } from '#shared/utils/member'

/**
 * 設定真實姓名的表單與送出（所有皮共用，畫面在 components/<皮>/Page/MemberSetFullname.vue）（member/set-realname，只能一次）。
 * 支援 ?next= / ?redirect= 回跳：全域錯誤碼 2041 會導來這裡再回提款。
 * 登入被 325/1096 擋下的未登入版本屬 auth 領域。
 */
export function useSetFullname() {
  const member = useMember()
  const route = useRoute()

  const fullname = ref('')
  const submitting = ref(false)

  const alreadySet = computed(() => hasValue(member.profile.value?.first_name))
  const valid = computed(() => {
    const v = fullname.value.trim()
    return v.length >= 2 && v.length <= 60 && /^(?!.*\d)[\p{L}\s\-_]+$/u.test(v)
  })

  onMounted(() => {
    void member.ensureLoaded()
  })

  async function submit() {
    if (!valid.value || submitting.value) return
    submitting.value = true
    const res = await member.setRealName(fullname.value)
    submitting.value = false
    if (!isApiOk(res)) return
    await navigateTo(resolveSetFullnameRedirect(route.query), { replace: true })
  }

  return {
    member,
    fullname,
    submitting,
    alreadySet,
    valid,
    submit,
  }
}
