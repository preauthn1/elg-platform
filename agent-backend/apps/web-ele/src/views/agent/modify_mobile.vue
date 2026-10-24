<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

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
  getAgentViewApi,
  getCountryDropdownByDomainApi,
  sendValidateCodeApi,
} from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentModifyMobile' });

interface CountryOpt {
  country_code?: string;
  id: number | string;
  name?: string;
  rule?: string;
}

const leaveTo = useLeavePage();
const userStore = useUserStore();
const me = computed(() => userStore.userInfo as any);

const step = ref<'new' | 'old'>('old');
const countries = ref<CountryOpt[]>([]);

const form = reactive({
  countryId: '' as number | string,
  newAuthCode: '',
  newMobile: '',
  oldAuthCode: '',
  oldMobile: '',
});

const sendingOld = ref(false);
const sendingNew = ref(false);
const verifyingOld = ref(false);
const savingNew = ref(false);

// 發送後 180 秒倒數才能重發
const COUNTDOWN_SEC = 180;
const countdownOld = ref(0);
const countdownNew = ref(0);
let countdownTimer: ReturnType<typeof setInterval> | undefined;
function startCountdown(which: 'new' | 'old') {
  const target = which === 'old' ? countdownOld : countdownNew;
  target.value = COUNTDOWN_SEC;
  countdownTimer ??= setInterval(() => {
    if (countdownOld.value > 0) countdownOld.value--;
    if (countdownNew.value > 0) countdownNew.value--;
    if (countdownOld.value === 0 && countdownNew.value === 0) {
      clearInterval(countdownTimer);
      countdownTimer = undefined;
    }
  }, 1000);
}
function sendButtonText(remain: number) {
  return remain > 0 ? `${remain}${tl('sec')}${tl('resend')}` : tl('send_sms');
}
onBeforeUnmount(() => {
  if (countdownTimer) clearInterval(countdownTimer);
});

const selectedCountry = computed(() =>
  countries.value.find((c) => c.id === form.countryId),
);
const newMobileFull = computed(
  () => `${selectedCountry.value?.country_code ?? ''}${form.newMobile}`,
);

async function loadData() {
  const [view, list] = await Promise.all([
    getAgentViewApi(me.value?.id).catch(() => null),
    getCountryDropdownByDomainApi(window.location.hostname).catch(() => []),
  ]);
  form.oldMobile = (view as any)?.mobile ?? '';
  countries.value = (list as any) ?? [];
  if (countries.value.length > 0)
    form.countryId = countries.value[0]?.id ?? form.countryId;
}

async function sendOldSms() {
  startCountdown('old');
  sendingOld.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: me.value?.id,
      mobile: form.oldMobile,
      type: 1,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
  } finally {
    sendingOld.value = false;
  }
}

async function verifyOld() {
  verifyingOld.value = true;
  try {
    const res = await checkValidateCodeApi({
      id: me.value?.id,
      mobile: form.oldMobile,
      token: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      step.value = 'new';
      ElMessage.success(res.message || tl('success'));
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    verifyingOld.value = false;
  }
}

function ruleOk(): boolean {
  const rule = selectedCountry.value?.rule;
  if (!rule) return true;
  try {
    return new RegExp(rule).test(form.newMobile);
  } catch {
    return true;
  }
}

async function sendNewSms() {
  if (!ruleOk()) {
    ElMessage.warning(tl('mobile_error'));
    return;
  }
  startCountdown('new');
  sendingNew.value = true;
  try {
    const res = await sendValidateCodeApi({
      ID: me.value?.id,
      mobile: newMobileFull.value,
      type: 1,
      verify_by: 1,
    });
    ElMessage[Number(res.code) === 1000 ? 'success' : 'error'](res.message);
  } finally {
    sendingNew.value = false;
  }
}

async function saveNew() {
  savingNew.value = true;
  try {
    const res = await checkValidateCodeApi({
      agent_group: me.value?.agent_group,
      id: me.value?.id,
      mobile: newMobileFull.value,
      token: [REDACTED],
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message || tl('success'));
      await leaveTo('/home');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    savingNew.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="tl('modify_mobile')">
    <ElCard class="mx-auto max-w-xl" shadow="never">
      <ElForm label-position="top">
        <ElFormItem :label="tl('member_account')">
          <ElInput :model-value="me?.username" disabled />
        </ElFormItem>

        <!-- Step 1: 驗證原手機 -->
        <template v-if="step === 'old'">
          <ElFormItem :label="tl('old_mobile')">
            <div class="flex w-full gap-2">
              <ElInput v-model="form.oldMobile" class="flex-1" disabled />
              <ElButton
                :disabled="countdownOld > 0"
                :loading="sendingOld"
                type="primary"
                @click="sendOldSms"
              >
                {{ sendButtonText(countdownOld) }}
              </ElButton>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('auth_code')">
            <ElInput v-model="form.oldAuthCode" />
          </ElFormItem>
          <div class="flex justify-end">
            <ElButton :loading="verifyingOld" type="info" @click="verifyOld">
              {{ tl('auth') }}
            </ElButton>
          </div>
        </template>

        <!-- Step 2: 設定新手機 -->
        <template v-else>
          <ElFormItem :label="tl('country')">
            <ElSelect v-model="form.countryId" class="!w-full" filterable>
              <ElOption
                v-for="c in countries"
                :key="c.id"
                :label="`${c.name ?? ''} ${c.country_code ?? ''}`"
                :value="c.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('new_mobile')">
            <div class="flex w-full gap-2">
              <ElInput v-model="form.newMobile" class="flex-1" />
              <ElButton
                :disabled="countdownNew > 0"
                :loading="sendingNew"
                type="primary"
                @click="sendNewSms"
              >
                {{ sendButtonText(countdownNew) }}
              </ElButton>
            </div>
          </ElFormItem>
          <ElFormItem :label="tl('auth_code')">
            <ElInput v-model="form.newAuthCode" />
          </ElFormItem>
          <div class="flex justify-end">
            <ElButton :loading="savingNew" type="success" @click="saveNew">
              {{ tl('save') }}
            </ElButton>
          </div>
        </template>
      </ElForm>
    </ElCard>
  </Page>
</template>
