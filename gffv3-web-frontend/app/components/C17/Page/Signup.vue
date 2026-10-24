<script setup lang="ts">
import { resolveRedirectPath, telegramLoginRoute } from '#shared/utils/auth'

/**
 * C17 的註冊與登入一樣是浮在畫面上的彈窗（設計稿 Register），外殼、OR 分隔、
 * Telegram／客服按鈕都與 Login 同一套。差別只在中間的欄位，
 * 而欄位是後端動態下發的，所以交給 AuthSignupForm，不照設計稿寫死
 * （每個站台要收的欄位不一樣）。路由層（進頁條件、推薦碼來源）在 pages/signup/index.vue。
 */
defineProps<{
  /** 網址 ?a= 帶進來的推薦碼，有就鎖定 */
  lockedCode?: string | null
}>()

const route = useRoute()
const { site, flag } = useSite()

const logo = computed(() => site.value?.raw.logo_mobile || site.value?.raw.logo_pc || '')
const siteTitle = computed(() => site.value?.raw.title || site.value?.domain || '')

/** AuthSignupForm 會把目前的推薦碼回拋，Telegram 註冊要帶著走。 */
const referralCode = ref('')

function close() {
  return navigateTo(resolveRedirectPath(route.query.redirect))
}
</script>

<template>
  <UiModal
    fill
    :title="$t('auth.signup.title.signUp')"

    @close="close"
  >
    <!--
      設計稿 register 在標題與第一個欄位之間放了站台 logo：
      [130,107 131x50] 置中（卡片 16..374，中心 195），下面留 20 才接第一個欄位。
      畫板上是灰色佔位塊寫著「Logo」，實際要放站台自己的 logo。
    -->
    <img
      v-if="logo"
      :src="logo"
      :alt="siteTitle"
      class="mx-auto mb-(--c17-gap-5) block h-[50px] w-auto max-w-[131px] object-contain"
    >

    <AuthSignupForm
      v-model:referral-code="referralCode"
      :locked-code="lockedCode"
    />

    <!-- OR／Telegram／客服／頁尾與登入頁同一份（設計稿兩張畫板這段一模一樣） -->
    <AuthAltActions
      :telegram-to="flag('tgbot_login_visible') ? telegramLoginRoute(route.query.redirect, referralCode) : undefined"
      :footer-hint="$t('auth.signup.hint.alreadyHaveAccount')"
      :footer-action="$t('auth.login.action.login')"
      footer-to="/login"
    />
  </UiModal>
</template>
