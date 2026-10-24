import { bankCardMenuEnabled, buildSettingMenu } from '#shared/utils/member'

/**
 * 設定選單：依會員資料與站台旗標組出要顯示的設定項目。
 * 所有皮共用，畫面在 components/<皮>/Page/MemberSetting.vue。
 */
export function useSettingMenu() {
  const member = useMember()
  const { flag } = useSite()

  const bankCardSettingEnabled = ref(false)

  /** 外觀設定入口：只有這個皮真的有第二套外觀才出現（C17 目前沒有）。 */
  const { hasAlt } = useAppearance()

  const items = computed(() =>
    buildSettingMenu(member.profile.value, {
      modifyMobileVisible: flag('modify_mobile_visible'),
      modifyEmailVisible: flag('modify_email_visible'),
      bindingEmailVisible: flag('binding_email_visible'),
      paymentPasswordVisible: flag('payment_password_visible'),
      bankCardSettingEnabled: bankCardSettingEnabled.value,
      hasGoogleAuth: flag('has_google_auth'),
      appearanceVisible: hasAlt.value,
    }),
  )

  onMounted(async () => {
    const [, bankCardInfo] = await Promise.all([member.ensureLoaded(), member.loadBankCardInfo()])
    bankCardSettingEnabled.value = bankCardMenuEnabled(bankCardInfo)
  })

  return {
    items,
  }
}
