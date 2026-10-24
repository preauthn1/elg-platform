<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import {
  ElButton,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getCountryDropdownByDomainApi,
  resetPasswordApi,
  sendValidateCodeApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'ForgetPassword' });

interface CountryOpt {
  country_code?: string;
  id: number | string;
  name?: string;
  rule?: string;
}

const router = useRouter();

/** dev 可用 VITE_GLOB_DOMAIN 覆蓋，正式環境取瀏覽器 Host（與登入頁一致） */
const currentDomain =
  (import.meta.env.VITE_GLOB_DOMAIN as string) || window.location.host;

const countries = ref<CountryOpt[]>([]);
const sending = ref(false);
const submitting = ref(false);

const form = reactive({
  authCode: '',
  countryId: '' as number | string,
  mobile: '',
  newPwd: '',
  newPwdConfirm: '',
  username: '',
});

const selectedCountry = computed(() =>
  countries.value.find((c) => c.id === form.countryId),
);
const mobileFull = computed(
  () => `${selectedCountry.value?.country_code ?? ''}${form.mobile}`,
);

function mobileRuleOk(): boolean {
  const rule = selectedCountry.value?.rule;
  if (!rule) return true;
  try {
    return new RegExp(rule).test(form.mobile);
  } catch {
    return true;
  }
}

async function loadCountries() {
  const list = await getCountryDropdownByDomainApi(currentDomain).catch(
    () => [],
  );
  countries.value = (list as any) ?? [];
  if (countries.value.length > 0)
    form.countryId = countries.value[0]?.id ?? form.countryId;
}

async function sendSms() {
  if (!form.username) {
    ElMessage.warning(tl('enter_member_account'));
    return;
  }
  if (!form.mobile || !mobileRuleOk()) {
    ElMessage.warning(tl('mobile_error'));
    return;
  }
  sending.value = true;
  try {
    const res = await sendValidateCodeApi({
      mobile: mobileFull.value,
      type: 2,
      username: form.username,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
  } finally {
    sending.value = false;
  }
}

async function handleSubmit() {
  if (!form.username) {
    ElMessage.warning(tl('enter_member_account'));
    return;
  }
  if (form.newPwd !== form.newPwdConfirm) {
    ElMessage.error(tl('new_password_confirm_error'));
    return;
  }
  submitting.value = true;
  try {
    const res = await resetPasswordApi({
      mobile: mobileFull.value,
      password: [REDACTED],
      password_confirmation: form.newPwdConfirm,
      token: [REDACTED],
      username: form.username,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      router.push({ path: '/auth/login' });
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

function goToLogin() {
  router.push({ path: '/auth/login' });
}

onMounted(loadCountries);
</script>

<template>
  <div>
    <h2 class="mb-6 text-center text-2xl font-bold">
      {{ tl('sms_text_type.forget') }}
    </h2>
    <ElForm label-position="top" @submit.prevent>
      <ElFormItem :label="tl('account_name')">
        <ElInput
          v-model="form.username"
          :placeholder="tl('account_name')"
          autocomplete="off"
        />
      </ElFormItem>
      <ElFormItem :label="tl('country')">
        <ElSelect v-model="form.countryId" class="!w-full" filterable>
          <ElOption
            v-for="c in countries"
            :key="c.id"
            :label="`${c.name ?? ''}${c.country_code ? ` +${c.country_code}` : ''}`"
            :value="c.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('mobile')">
        <div class="flex w-full gap-2">
          <ElInput
            v-model="form.mobile"
            :placeholder="tl('mobile')"
            class="flex-1"
          />
          <ElButton :loading="sending" type="primary" @click="sendSms">
            {{ tl('send_sms') }}
          </ElButton>
        </div>
      </ElFormItem>
      <ElFormItem :label="tl('auth_code')">
        <ElInput v-model="form.authCode" :placeholder="tl('auth_code')" />
      </ElFormItem>
      <ElFormItem :label="tl('new_password')">
        <ElInput
          v-model="form.newPwd"
          :placeholder="tl('new_password')"
          show-password
          type="password"
        />
      </ElFormItem>
      <ElFormItem :label="tl('new_password_confirm')">
        <ElInput
          v-model="form.newPwdConfirm"
          :placeholder="tl('new_password_confirm')"
          show-password
          type="password"
        />
      </ElFormItem>
      <ElButton
        :loading="submitting"
        class="w-full"
        type="primary"
        @click="handleSubmit"
      >
        {{ tl('save') }}
      </ElButton>
      <!-- !ml-0：Element Plus 對相鄰 ElButton 會加 margin-left，與 w-full 疊加會溢出容器 -->
      <ElButton class="mt-3 !ml-0 w-full" @click="goToLogin">
        {{ tl('cancel') }}
      </ElButton>
    </ElForm>
  </div>
</template>
