import { hasValue } from '#shared/utils/member'

/**
 * 修改暱稱：帶入目前的暱稱，送出成功後回上一頁。
 * 所有皮共用，畫面在 components/<皮>/Page/MemberSettingName.vue。
 */
export function useNicknameForm() {
  const member = useMember()
  const router = useRouter()

  const nickname = ref('')
  const submitting = ref(false)

  onMounted(async () => {
    await member.ensureLoaded()
    const current = member.profile.value?.nickname
    if (hasValue(current)) nickname.value = String(current)
  })

  async function submit() {
    if (submitting.value) return
    submitting.value = true
    const res = await member.setNickname(nickname.value)
    submitting.value = false
    if (!isApiOk(res)) return
    router.back()
  }

  return {
    nickname,
    submitting,
    submit,
  }
}
