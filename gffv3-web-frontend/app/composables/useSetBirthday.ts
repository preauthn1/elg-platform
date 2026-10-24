import {
  MEMBER_PATH,
  hasValue,
  isAdultBirthday,
  maxAdultBirthday,
} from '#shared/utils/member'

/**
 * 設定生日：只能一次、要成年。
 * 所有皮共用，畫面在 components/<皮>/Page/MemberSetBirthday.vue。
 */
export function useSetBirthday() {
  const member = useMember()

  const birthday = ref('')
  const submitting = ref(false)

  const alreadySet = computed(() => hasValue(member.profile.value?.birthday))
  const maxDate = computed(() => maxAdultBirthday())
  const valid = computed(() => isAdultBirthday(birthday.value))

  onMounted(() => {
    void member.ensureLoaded()
  })

  async function submit() {
    if (!valid.value || submitting.value) return
    submitting.value = true
    const res = await member.setBirthday(birthday.value)
    submitting.value = false
    if (!isApiOk(res)) return
    await navigateTo(MEMBER_PATH.setting, { replace: true })
  }

  return {
    member,
    birthday,
    submitting,
    alreadySet,
    maxDate,
    valid,
    submit,
  }
}
