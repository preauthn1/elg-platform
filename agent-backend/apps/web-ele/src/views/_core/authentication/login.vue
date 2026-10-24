<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';
import type { Recordable } from '@vben/types';

import { computed, onMounted, ref } from 'vue';

import { AuthenticationLogin, z } from '@vben/common-ui';
import { $t } from '@vben/locales';

import { domainInfoApi } from '#/api';
import { tl } from '#/locales';
import { useAuthStore } from '#/store';
import { applyDomainBranding } from '#/utils/domain-branding';

defineOptions({ name: 'Login' });

const authStore = useAuthStore();

/** dev 可用 VITE_GLOB_DOMAIN 覆蓋，正式環境取瀏覽器 Host */
const currentDomain =
  (import.meta.env.VITE_GLOB_DOMAIN as string) || window.location.host;

/** 忘記密碼開關，預設關閉，登入頁載入時向 API 取得 */
const showForgetPassword = [REDACTED];

const formSchema = computed((): VbenFormSchema[] => {
  return [
    {
      component: 'VbenInput',
      componentProps: {
        placeholder: tl('username'),
      },
      fieldName: 'username',
      label: tl('username'),
      rules: z.string().min(1, { message: $t('authentication.usernameTip') }),
    },
    {
      component: 'VbenInputPassword',
      componentProps: {
        placeholder: tl('password'),
      },
      fieldName: 'password',
      label: tl('password'),
      rules: z.string().min(1, { message: $t('authentication.passwordTip') }),
    },
  ];
});

/** 送出登入：附帶 domain 與 action */
async function handleLogin(values: Recordable<any>) {
  await authStore.authLogin({
    action: 'login',
    domain: currentDomain,
    mobile_auth: false,
    password: [REDACTED],
    username: values.username,
  });
}

onMounted(async () => {
  // 取得 domain 設定，決定是否顯示「忘記密碼」
  try {
    const res: any = await domainInfoApi(currentDomain);
    if (res?.code === 1000) {
      const settings: Array<{ setting_key: number; value: any }> =
        res.data?.setting ?? [];
      const forget = settings.find((s) => Number(s.setting_key) === 5302);
      showForgetPassword.value = Number(forget?.value) === 1;

      // 4035：套用「系統管理 → 域名管理」維護的本站品牌設定
      applyDomainBranding(res.data?.info ?? {});
    }
  } catch {
    // 取設定失敗不阻擋登入頁顯示
  }
});
</script>

<template>
  <AuthenticationLogin
    :form-schema="formSchema"
    :loading="authStore.loginLoading"
    :sub-title="tl('login_subtitle')"
    :submit-button-text="tl('login')"
    :title="tl('login')"
    :show-code-login="false"
    :show-forget-password=[REDACTED]"
    :show-qrcode-login="false"
    :show-register="false"
    :show-remember-me="false"
    :show-third-party-login="false"
    @submit="handleLogin"
  />
</template>
