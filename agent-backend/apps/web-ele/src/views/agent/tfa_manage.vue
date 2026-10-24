<script lang="ts" setup>
import { computed, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import { ElCard, ElMessage, ElMessageBox, ElSwitch } from 'element-plus';

import {
  check2faSecretApi,
  disable2faApi,
  editAgentApi,
  get2faImageApi,
  getAgentViewByIdApi,
  getCustomizedSettingApi,
  is2faOk,
} from '#/api';
import { tl } from '#/locales';

import TwoFaOtpDialog from './components/two-fa-otp-dialog.vue';

defineOptions({ name: 'AgentTfaManage' });

const userStore = useUserStore();
const selfId = Number((userStore.userInfo as any)?.id ?? 0);

const otpDialogRef = ref<InstanceType<typeof TwoFaOtpDialog>>();
const visible = ref(false);
const has2fa = ref(false);
const image = ref('');
const secret = [REDACTED]'');
const switching = ref(false);

const title = computed(() => tl('menu_cj7_tfa_set'));
/** 說明文字的語系值帶有 <br />，拆成多行顯示，不用 v-html */
const memoLines = computed(() =>
  tl('two_factor_authentication_memo').split(/<br\s*\/?>/i),
);

/** 金鑰只在尚未開啟時給（target/tickets#4503）；開啟後 API 回空字串 */
async function loadImage() {
  const img: any = await get2faImageApi().catch(() => null);
  image.value = img?.image ?? '';
  secret.value = img?.secret ?? '';
}

async function load() {
  const [settings, detail] = await Promise.all([
    getCustomizedSettingApi(),
    getAgentViewByIdApi(selfId),
  ]);
  const v = (settings ?? []).find(
    (s: any) => String(s.setting_key) === '5001',
  )?.value;
  visible.value = Number(v ?? 1) === 1;
  has2fa.value = Number((detail as any)?.has_2fa) === 1;
  if (has2fa.value) {
    image.value = '';
    secret.value = '';
  } else {
    await loadImage();
  }
}

async function turnOn() {
  try {
    const { value: otp } = await ElMessageBox.prompt(
      tl('please_input_otp'),
      tl('two_factor_authentication_test'),
      { confirmButtonText: tl('testing'), cancelButtonText: tl('cancel') },
    );
    const chk: any = await check2faSecretApi({
      agent_id: selfId,
      secret: [REDACTED] ?? '',
    });
    if (!is2faOk(chk?.code)) {
      ElMessage.error(tl('failure'));
      return;
    }
    switching.value = true;
    const res: any = await editAgentApi({ id: selfId, has_2fa: 1 });
    if (Number(res?.code) === 1000) {
      ElMessage.success(tl('success'));
      has2fa.value = true;
      image.value = '';
      secret.value = '';
    } else {
      ElMessage.error(res?.message ?? tl('failure'));
    }
  } catch {
    /* cancelled */
  } finally {
    switching.value = false;
  }
}

/** 關閉也要輸入當下的驗證碼（target/tickets#4503） */
async function turnOff() {
  const ok = await otpDialogRef.value?.open({
    label: tl('tfa_input_code'),
    submit: (otp) => disable2faApi({ secret: [REDACTED] }),
    title: tl('tfa_disable_title'),
  });
  if (ok) {
    has2fa.value = false;
    await loadImage();
  }
}

function onToggle(next: boolean | number | string) {
  return next ? turnOn() : turnOff();
}

load();
</script>

<template>
  <Page :title="title">
    <div class="mx-auto max-w-md">
      <ElCard v-if="visible" shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('two_factor_authentication') }}</span>
        </template>
        <div class="flex items-start justify-between gap-4">
          <p v-if="!has2fa" class="text-sm">
            <template v-for="(line, i) in memoLines" :key="i">
              <br v-if="i > 0" />{{ line }}
            </template>
          </p>
          <div v-else class="space-y-1 text-sm">
            <p>{{ tl('tfa_enabled_secret_hidden') }}</p>
            <p class="text-muted-foreground">
              {{ tl('tfa_change_device_contact_upline') }}
            </p>
          </div>
          <ElSwitch
            :model-value="has2fa"
            :loading="switching"
            @change="onToggle"
          />
        </div>
        <div v-if="!has2fa" class="mt-4 text-center">
          <!-- eslint-disable-next-line vue/no-v-html -->
          <p v-html="image"></p>
          <p>
            <b class="text-danger text-base">{{ secret }}</b>
          </p>
        </div>
      </ElCard>
    </div>
    <TwoFaOtpDialog ref="otpDialogRef" />
  </Page>
</template>
