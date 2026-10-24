<script setup lang="ts">
/**
 * C17 的登入是浮在目前畫面上的彈窗（設計稿 Login），不是獨立整頁。
 * 路由層（已登入導走、版面設定）在 pages/login.vue，邏輯在 useLoginForm。
 */
const {
  form,
  loading,
  showPassword,
  customerService,
  canSubmit,
  canRegister,
  passwordReset,
  showForgetPassword,
  telegramTo,
  submit,
  close,
} = useLoginForm()
</script>

<template>
  <UiModal
    fill
    :title="$t('auth.login.title.welcomeBack')"

    @close="close"
  >
    <!--
      WELCOME BONUS 橫幅：設計稿 [36,107 318×100] 圓角 8，圖是 700×282（uid cb11fac8，
      從設計檔抓下來的）。**寫死在皮裡的素材**，不是 advertise——`BANNER_PATH` 沒有
      登入頁的 path_id，跟遊戲大廳的類型橫幅同一個結論：寫死。

      **不加陰影。** 向量資料那行寫了 `shadow 3,3 blur 2 #7a8599/26%`，但算圖裡沒有：
      取樣橫幅底下 1／3／5px 與右邊 1／3px 全是純白 rgb(255,255,255)。
      跟欄位圖示同一件事：向量資料寫了陰影，不代表算圖看得到，以算圖為準。
    -->
    <img
      src="/skins/c17/login/welcome-bonus.webp"
      alt=""
      class="mb-(--c17-gap-5) block aspect-[318/100] w-full rounded-(--c17-field-radius) object-cover"
    >

    <form
      class="space-y-(--c17-gap-3)"
      @submit.prevent="submit"
    >
      <p
        v-if="passwordReset"
        class="rounded-(--c17-radius-sm) bg-success-soft px-3 py-2 text-sm text-success"
      >
        {{ $t('member.password.msg.updated') }}
      </p>

      <!-- 登入這兩欄一定必填，所以 required-mark 直接給 true，不像註冊那樣看 is_required -->
      <UiTextField
        v-model="form.username"
        :placeholder="$t('auth.login.placeholder.account')"
        autocomplete="username"
        required
        required-mark
      >
        <template #prefix>
          <UiFieldIcon name="account" />
        </template>
      </UiTextField>

      <UiTextField
        v-model="form.password"
        :type="showPassword ? 'text' : 'password'"
        :placeholder="$t('auth.login.placeholder.password')"
        autocomplete="current-password"
        required
        required-mark
      >
        <template #prefix>
          <UiFieldIcon name="password" />
        </template>
        <!-- 顯示／隱藏密碼：設計稿 Form components 的 "Eye hide" / "Eye Unhide" 兩張圖 -->
        <template #suffix>
          <button
            type="button"
            class="flex items-center"
            :aria-label="$t('auth.login.label.originalPassword')"
            @click="showPassword = [REDACTED]"
          >
            <UiFieldIcon :name="showPassword ? 'eye-show' : 'eye-hide'" />
          </button>
        </template>
      </UiTextField>

      <NuxtLink
        v-if="showForgetPassword"
        to="/forget-password"
        class="mt-(--c17-gap-8)! block text-(length:--c17-text-link) font-bold text-accent underline"
      >
        {{ $t('auth.password.action.forget') }}
      </NuxtLink>

      <p
        v-if="customerService"
        class="text-sm text-warning"
      >
        客服帳號登入尚未接上客服後台（id: {{ customerService.id }}）。
      </p>

      <UiButton
        type="submit"
        :disabled="!canSubmit"
        :loading="loading"
      >
        {{ $t('auth.login.action.login') }}
      </UiButton>
    </form>

    <!-- OR／Telegram／客服／頁尾與註冊頁同一份（設計稿兩張畫板這段一模一樣） -->
    <AuthAltActions
      :telegram-to="telegramTo"
      :footer-hint="canRegister ? $t('auth.login.hint.noAccount') : ''"
      :footer-action="canRegister ? $t('auth.signup.action.signup') : ''"
      footer-to="/signup"
    />
  </UiModal>
</template>
