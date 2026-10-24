<script setup lang="ts">
/** C17 的註冊表單畫面。邏輯（欄位、驗證、送出）在 useSignupForm，各皮共用。 */
const props = defineProps<{
  /** 路由 :shareCode 或 ?a= 帶進來的邀請碼，有就鎖定 */
  lockedCode?: string | null
}>()

const {
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
} = useSignupForm(props.lockedCode)

/**
 * 目前的推薦碼回拋給外層（註冊彈窗的 Telegram 按鈕要帶著它）。
 * 碼可能來自網址、也可能是使用者從代理下拉選的，所以不能只看 props。
 */
const referralCode = defineModel<string>('referralCode', { default: '' })
watchEffect(() => {
  referralCode.value = invitation.value.code || ''
})
</script>

<template>
  <div class="space-y-4">
    <!-- 靜態表單的邀請碼（v2 的由 AuthRegisterFields 依後端順序渲染） -->
    <div
      v-if="registerMode === 'static'"
      class="space-y-1"
    >
      <UiTextField
        v-model="invitation.code"
        :label="$t('member.referralCode.label.code')"
        :placeholder="$t('auth.signup.placeholder.referralCode')"
        :disabled="invitation.locked"
      />
    </div>

    <!-- 靜態表單 -->
    <form
      v-if="registerMode === 'static'"
      class="space-y-3"
      @submit.prevent="submitStatic"
    >
      <label class="block text-sm">
        {{ $t('auth.common.label.username') }}
        <input
          v-model="staticForm.username"
          type="text"
          class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
          :placeholder="$t('validation.account.placeholder.formatRange', { min: 4, max: 10 })"
          autocomplete="username"
        >
      </label>
      <!--
        密碼欄改用 UiTextField：設計稿每個密碼欄都有前置圖示與顯示／隱藏的眼睛
        （form-components 那張畫了 Eye hide / Eye Unhide 兩態），
        而且欄位的四種狀態樣式只該有一份，不要在這裡再抄一次 class。
      -->
      <label class="block text-sm">
        {{ $t('auth.common.label.password') }}
        <UiTextField
          v-model="staticForm.password"
          class="mt-1"
          :type="staticShown.pw ? 'text' : 'password'"
          :placeholder="$t('validation.password.placeholder.charsetRange', { min: 6, max: 12 })"
          autocomplete="new-password"
        >
          <template #prefix>
            <UiFieldIcon name="password" />
          </template>
          <template #suffix>
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="staticShown.pw = !staticShown.pw"
            >
              <UiFieldIcon :name="staticShown.pw ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>
      </label>
      <label class="block text-sm">
        {{ $t('auth.common.label.confirmPassword') }}
        <UiTextField
          v-model="staticForm.password_confirmation"
          class="mt-1"
          :type="staticShown.confirm ? 'text' : 'password'"
          autocomplete="new-password"
        >
          <template #prefix>
            <UiFieldIcon name="password-confirm" />
          </template>
          <template #suffix>
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="staticShown.confirm = !staticShown.confirm"
            >
              <UiFieldIcon :name="staticShown.confirm ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>
      </label>
      <label class="block text-sm">
        {{ $t('auth.common.label.email') }}
        <input
          v-model="staticForm.email"
          type="email"
          class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
        >
      </label>
      <label class="block text-sm">
        {{ $t('member.profile.label.nickname') }}
        <input
          v-model="staticForm.nickname"
          type="text"
          class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
        >
      </label>
      <label class="block text-sm">
        {{ $t('member.profile.label.birthday') }}
        <input
          v-model="staticForm.birthday"
          type="date"
          class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
        >
      </label>
      <div class="flex gap-2">
        <label class="block w-24 text-sm">
          {{ $t('auth.common.label.countryCode') }}
          <input
            v-model="staticForm.country_code"
            type="text"
            inputmode="numeric"
            class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
          >
        </label>
        <label class="block flex-1 text-sm">
          {{ $t('auth.common.label.phoneNumber') }}
          <input
            v-model="staticForm.phone"
            type="tel"
            class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
          >
        </label>
      </div>
      <div
        v-if="staticMobile"
        class="flex items-end gap-2"
      >
        <label class="block flex-1 text-sm">
          {{ $t('auth.otp.label.smsCode') }}
          <input
            v-model="staticForm.mobile_token"
            type="text"
            inputmode="numeric"
            maxlength="6"
            class="mt-1 h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink placeholder:text-placeholder focus:border-field-line-focus focus:ring-2 focus:ring-accent/25 focus:outline-none"
          >
        </label>
        <AuthSendCodeButton
          :type="8"
          :verify-by="1"
          :mobile="staticMobile"
        />
      </div>
      <p
        v-if="message"
        class="text-sm text-danger"
      >
        {{ message }}
      </p>
      <UiButton
        type="submit"
        :loading="loading"
      >
        {{ $t('auth.signup.action.signUp') }}
      </UiButton>
    </form>

    <!-- v2 動態表單：畫什麼、什麼順序、分幾步都由後端設定決定 -->
    <form
      v-else
      class="space-y-(--c17-gap-3)"
      @submit.prevent="isLastStep ? submitV2() : nextStep()"
    >
      <p
        v-if="steps.length > 1"
        class="text-(length:--c17-text-label) text-muted"
      >
        {{ stepIndex + 1 }} / {{ steps.length }}
      </p>

      <AuthRegisterFields
        v-model:values="v2"
        v-model:phone="v2Phone"
        v-model:country-code="v2CountryCode"
        v-model:invitation-code="invitation.code"
        v-model:invitation-code-type="invitation.codeType"
        :fields="currentStep.fields"
        :all-fields="allFields"
        :issues="allIssues"
        :show-errors="touched"
        :error-field="errorField"
        :countries="countries"
        :agent-list="agentList"
      />

      <UiCheckbox
        v-if="isFirstStep"
        v-model="agreed"
        :error="touched && !agreed ? $t('validation.field.error.required', { field: $t('site.legal.title.userAgreement') }) : ''"
      >
        {{ $t('auth.signup.label.readAndAgree') }}
        <button
          type="button"
          class="font-bold text-link underline"
          @click="showAgreement = true"
        >
          {{ $t('site.legal.title.userAgreement') }}
        </button>
      </UiCheckbox>

      <p
        v-if="message"
        class="text-sm text-danger"
      >
        {{ message }}
      </p>

      <div class="flex gap-2">
        <UiButton
          v-if="stepIndex > 0"
          variant="secondary"
          @click="prevStep"
        >
          {{ $t('common.ui.action.prevStep') }}
        </UiButton>
        <UiButton
          type="submit"
          :loading="loading"
          :disabled="isLastStep ? !canSubmit : !canProceed"
        >
          {{ isLastStep ? $t('auth.signup.action.signUp') : $t('common.ui.action.next') }}
        </UiButton>
      </div>
    </form>

    <UiModal
      v-if="showAgreement"
      :title="$t('site.legal.title.userAgreement')"
      @close="showAgreement = false"
    >
      <!-- 協議全文來自語言檔，含 HTML 標記（i18n 已設 escapeHtml: false） -->
      <!-- eslint-disable vue/no-v-html -->
      <div
        class="whitespace-pre-line text-sm"
        v-html="agreementHtml"
      />
      <!-- eslint-enable vue/no-v-html -->
    </UiModal>
  </div>
</template>
