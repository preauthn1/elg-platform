<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';

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
  checkValidateCodeApi,
  getCountryDropdownByDomainApi,
  sendValidateCodeApi,
} from '#/api';
import { tl } from '#/locales';
import { useAuthStore } from '#/store';
import { useLoginFlowStore } from '#/store/login-flow';

/**
 * 登入流程的綁定手機關卡（partial session，token 尚未核發）。
 * 對齊舊版 agent/bind_mobile.php：國碼下拉＋phone_rule 驗證，
 * 驗證碼通過後以暫存帳密重新登入（可能再串 mobile_auth 關卡）。
 */
defineOptions({ name: 'LoginBindMobile' });

interface CountryOpt {
  country_code?: string;
  id: number | string;
  name?: string;
  rule?: string;
}

const router = useRouter();
const authStore = useAuthStore();
const flow = useLoginFlowStore();

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
});

const selectedCountry = computed(() =>
  countries.value.find((c) => c.id === form.countryId),
);
const mobileFull = computed(
  () => `${selectedCountry.value?.country_code ?? ''}${form.mobile}`,
);

/** 手機需符合該國 phone_rule 才可發送驗證碼（對齊舊版格式檢查） */
const ruleOk = computed(() => {
  if (!form.mobile) return false;
  const rule = selectedCountry.value?.rule;
  if (!rule) return true;
  try {
    return new RegExp(rule).test(form.mobile);
  } catch {
    return true;
  }
});

async function loadCountries() {
  const list = await getCountryDropdownByDomainApi(currentDomain).catch(
    () => [],
  );
  countries.value = (list as any) ?? [];
  if (countries.value.length > 0)
    form.countryId = countries.value[0]?.id ?? form.countryId;
}

async function sendSms() {
  if (!ruleOk.value) {
    ElMessage.warning(tl('mobile_error'));
    return;
  }
  sending.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: flow.id,
      mobile: mobileFull.value,
      type: 1,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
  } finally {
    sending.value = false;
  }
}

/** 驗證通過後重新登入（不帶 mobile_auth 旗標，後端可能接著要求 mobile_auth） */
async function handleSubmit() {
  if (!form.authCode.trim()) {
    ElMessage.warning(tl('auth_code'));
    return;
  }
  submitting.value = true;
  try {
    const res = await checkValidateCodeApi({
      mobile: mobileFull.value,
      token: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      await authStore.resumeLogin(false);
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

function cancel() {
  flow.clear();
  router.replace(LOGIN_PATH);
}

onMounted(() => {
  // 未經登入流程直接進入（或重新整理）→ 回登入頁
  if (flow.action !== 'bind_mobile') {
    router.replace(LOGIN_PATH);
    return;
  }
  loadCountries();
});
</script>

<template>
  <div>
    <h2 class="mb-6 text-center text-2xl font-bold">
      {{ tl('bind_mobile') }}
    </h2>
    <ElForm label-position="top" @submit.prevent>
      <ElFormItem :label="tl('member_account')">
        <ElInput :model-value="flow.username" disabled />
      </ElFormItem>
      <ElFormItem :label="tl('mobile')">
        <div class="flex w-full gap-2">
          <ElSelect v-model="form.countryId" class="!w-44" filterable>
            <ElOption
              v-for="c in countries"
              :key="c.id"
              :label="`${c.name ?? ''}${c.country_code ? ` +${c.country_code}` : ''}`"
              :value="c.id"
            />
          </ElSelect>
          <ElInput
            v-model="form.mobile"
            :placeholder="tl('mobile')"
            class="flex-1"
          />
        </div>
        <span v-if="form.mobile && !ruleOk" class="text-sm text-destructive">
          {{ tl('mobile_error') }}
        </span>
      </ElFormItem>
      <ElFormItem v-if="ruleOk">
        <ElButton :loading="sending" @click="sendSms">
          {{ tl('send_sms') }}
        </ElButton>
      </ElFormItem>
      <ElFormItem :label="tl('auth_code')">
        <ElInput
          v-model="form.authCode"
          :placeholder="tl('auth_code')"
          autocomplete="off"
        />
      </ElFormItem>
      <ElButton
        :loading="submitting"
        class="w-full"
        type="primary"
        @click="handleSubmit"
      >
        {{ tl('submit') }}
      </ElButton>
      <ElButton class="!ml-0 mt-3 w-full" @click="cancel">
        {{ tl('cancel') }}
      </ElButton>
    </ElForm>
  </div>
</template>
