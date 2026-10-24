<script setup lang="ts">
/**
 * 忘記密碼（type=2）。設計稿 Login-Reset pw_Mobile / _Email 是**同一個彈窗的兩個分頁**
 * （上方 Mobile No. / E-mail 分段切換），不是兩張獨立畫面，所以這裡改成分段鈕，
 * 不是原本的 radio。
 *
 * 後端流程不變：選手機／信箱 → 發碼 → 驗碼 → get-member-accounts
 * → /select-account（一支號碼多個帳號）或 /set-password。
 *
 * **與畫板刻意不同的一點**：畫板把新密碼與確認密碼畫在同一個彈窗裡，
 * 但後端要先驗碼取回該號碼底下的帳號、選定帳號之後才能設密碼，中間那一步省不掉。
 * 要照畫板一頁完成，得請後端提供單步重設的端點。
 */
const {
  form,
  mobile,
  sent,
  message,
  countries,
  canSubmit,
  submit,
  onIllegal,
  TABS,
} = useForgetPasswordPage()
</script>

<template>
  <UiModal
    fill
    :title="$t('auth.password.action.forget')"

    @close="navigateTo('/login')"
  >
    <form
      class="space-y-(--c17-gap-3)"
      @submit.prevent="submit"
    >
      <!--
        分段切換：手機 / 信箱。設計稿量到的是**一個 318×32 的底槽**
        （r=8、fill #f1f4f8、1px #b4b4b4 內框），作用中那半是 159×32 的主色塊，
        字 Plus Jakarta Sans Bold 12，作用 #f1f4f8、非作用 #7a8599。
        不是兩顆有間隙的膠囊，外面也不包 padding。
      -->
      <div class="grid h-(--c17-field-h) grid-cols-2 overflow-hidden rounded-(--c17-field-radius) border border-field-line bg-surface-2">
        <button
          v-for="tab in TABS"
          :key="tab.value"
          type="button"
          class="rounded-(--c17-field-radius) font-bold transition-colors"
          :style="{ fontSize: 'var(--c17-text-link)' }"
          :class="form.verify_by === tab.value ? 'bg-accent text-inverse' : 'text-muted'"
          @click="form.verify_by = tab.value"
        >
          {{ $t(tab.labelKey) }}
        </button>
      </div>

      <!-- 手機：國碼與號碼同一列 -->
      <div
        v-if="form.verify_by === 1"
        class="flex gap-2"
      >
        <UiCountryCodeSelect
          v-model="form.country_code"
          :countries="countries"
        />
        <UiTextField
          v-model="form.phone"
          class="flex-1"
          type="tel"
          inputmode="tel"
          :placeholder="$t('auth.common.label.phoneNumber')"
        />
      </div>

      <UiTextField
        v-else
        v-model="form.email"
        type="email"
        :placeholder="$t('member.bindEmail.placeholder.email')"
      >
        <template #prefix>
          <UiFieldIcon name="account" />
        </template>
      </UiTextField>

      <!-- 驗證碼與發送鈕同一列（畫板上發送鈕貼在右邊） -->
      <div class="flex items-start gap-2">
        <UiTextField
          v-model="form.token"
          class="flex-1"
          inputmode="numeric"
          :maxlength="6"
          :placeholder="$t('auth.otp.placeholder.code')"
        >
          <template #prefix>
            <UiFieldIcon name="verify-code" />
          </template>
        </UiTextField>
        <AuthSendCodeButton
          flush
          :type="2"
          :verify-by="form.verify_by"
          :mobile="mobile"
          :email="form.email"
          :username="form.username"
          @sent="sent = true"
          @illegal="onIllegal"
        />
      </div>

      <!-- 帳號選填：一支號碼綁多個帳號時用來指定 -->
      <UiTextField
        v-model="form.username"
        autocomplete="username"
        :placeholder="$t('auth.common.label.username')"
      >
        <template #prefix>
          <UiFieldIcon name="account" />
        </template>
      </UiTextField>

      <p
        v-if="message"
        class="text-sm text-danger"
      >
        {{ message }}
      </p>

      <UiButton
        type="submit"
        :disabled="!canSubmit"
      >
        {{ $t('common.ui.action.next') }}
      </UiButton>
    </form>

    <!-- 畫板底部：需要協助就找客服。設計稿 [195,722] + [36,737 318x32]，貼卡片底 -->
    <div class="mt-auto space-y-(--c17-gap-2) pt-(--c17-gap-6) text-center">
      <p class="text-(length:--c17-text-label) text-muted">
        {{ $t('site.cs.hint.contactUs') }}
      </p>
      <UiButton
        variant="secondary"
        @click="navigateTo('/cs')"
      >
        {{ $t('site.cs.nav.customerService') }}
      </UiButton>
    </div>
  </UiModal>
</template>
