<script lang="ts" setup>
import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
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
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentBindMobile' });

interface CountryOpt {
  country_code?: string;
  id: number | string;
  name?: string;
  rule?: string;
}

const leaveTo = useLeavePage();
const userStore = useUserStore();
const me = computed(() => userStore.userInfo as any);

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

/** 手機需符合該國 phone_rule 才能送驗證碼 */
function ruleOk(): boolean {
  const rule = selectedCountry.value?.rule;
  if (!rule) return Boolean(form.mobile);
  try {
    return new RegExp(rule).test(form.mobile);
  } catch {
    return Boolean(form.mobile);
  }
}

async function loadData() {
  const list = await getCountryDropdownByDomainApi(
    window.location.hostname,
  ).catch(() => []);
  countries.value = (list as any) ?? [];
  if (countries.value.length > 0)
    form.countryId = countries.value[0]?.id ?? form.countryId;
}

async function sendSms() {
  if (!ruleOk()) {
    ElMessage.warning(tl('mobile_error'));
    return;
  }
  sending.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: me.value?.id,
      mobile: mobileFull.value,
      type: 1,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
  } finally {
    sending.value = false;
  }
}

async function handleSubmit() {
  submitting.value = true;
  try {
    const res = await checkValidateCodeApi({
      agent_group: form.countryId,
      id: me.value?.id,
      mobile: mobileFull.value,
      token: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      await leaveTo('/home');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="tl('bind_mobile')">
    <ElCard class="mx-auto max-w-xl" shadow="never">
      <ElForm label-position="top">
        <ElFormItem :label="tl('member_account')">
          <ElInput :model-value="me?.username" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('mobile')">
          <div class="flex w-full gap-2">
            <ElSelect v-model="form.countryId" class="!w-40" filterable>
              <ElOption
                v-for="c in countries"
                :key="c.id"
                :label="`${c.name ?? ''} +${c.country_code ?? ''}`"
                :value="c.id"
              />
            </ElSelect>
            <ElInput v-model="form.mobile" class="flex-1" />
            <ElButton :loading="sending" type="primary" @click="sendSms">
              {{ tl('send_sms') }}
            </ElButton>
          </div>
        </ElFormItem>
        <ElFormItem :label="tl('auth_code')">
          <ElInput v-model="form.authCode" />
        </ElFormItem>
        <div class="flex justify-end">
          <ElButton :loading="submitting" type="success" @click="handleSubmit">
            {{ tl('submit') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>
  </Page>
</template>
