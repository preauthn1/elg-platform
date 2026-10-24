<script lang="ts" setup>
import type { AgentRow, CountryRow } from '#/api';

import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElSkeleton,
  ElSwitch,
  ElTag,
} from 'element-plus';

import {
  check2faSecretApi,
  disable2faApi,
  editAgentApi,
  editAgentStatusApi,
  getAgentNumberOfPeopleApi,
  getAgentViewByIdApi,
  getCountryDropdownApi,
  getCustomizedSettingApi,
  is2faOk,
} from '#/api';
import { tl } from '#/locales';
import PieCard from '#/views/member/components/pie-card.vue';

import { useSelfHas2fa } from './two-fa';
import TwoFaOtpDialog from './two-fa-otp-dialog.vue';

const userStore = useUserStore();
const { hasAccessByCodes } = useAccess();
const router = useRouter();

const selfId = Number((userStore.userInfo as any)?.id ?? 0);
const roleId = Number((userStore.userInfo as any)?.role_id ?? 0);
const canOtherSetting = hasAccessByCodes(['agent_other_setting']);

const visible = ref(false);
const agentId = ref(0);
const loading = ref(true);
const agent = ref<AgentRow | null>(null);
const countryMap = ref<Map<number, CountryRow>>(new Map());

/**
 * 2FA 卡片（開關＋重置）：設定 5001 開啟，且角色 1/11 才可見，且只給「看別人」用。
 * target/tickets#4521：看自己時不再顯示 QRCode、金鑰與開關，只在基本資料列出「已開啟／未開啟」；
 * 本人的設置與關閉改走頭像選單。
 */
const twoFaSettingOn = ref(true);
const otpDialogRef = ref<InstanceType<typeof TwoFaOtpDialog>>();
const { loadSelfHas2fa, selfHas2fa } = useSelfHas2fa(selfId);
const isSelfView = computed(() => selfId === agentId.value);
const show2fa = computed(
  () => twoFaSettingOn.value && !isSelfView.value && [1, 11].includes(roleId),
);

/** 代理/會員人數圓餅圖 */
const peoplePie = ref<Array<{ name: string; value: number }>>([]);

/** 註冊國家 / 城市 */
const countryCity = computed(() => {
  const a = agent.value as any;
  if (!a) return '-';
  const country = countryMap.value.get(a.country_id)?.name ?? '';
  return `${country} / ${a.city ?? ''}`;
});

/** 地址 */
const fullAddress = computed(() => {
  const a = agent.value as any;
  if (!a) return '-';
  return [a.city, a.district, a.addrress].filter(Boolean).join('') || '-';
});

/** 推廣註冊網址（總代 host_id=0 且有 code/domain 時顯示） */
const promoteUrl = computed(() => {
  const a = agent.value as any;
  if (!a || a.host_id !== 0 || !a.code) return '';
  const domain = a.domain?.domain;
  if (!domain) return '';
  const sep = domain.includes('?') ? '' : '/Signup/';
  return `https://${domain}${sep}${a.code}`;
});

async function copyPromoteUrl() {
  try {
    await navigator.clipboard.writeText(promoteUrl.value);
    ElMessage.success(tl('copy_successfully'));
  } catch {
    ElMessage.warning(promoteUrl.value);
  }
}

/** 編輯基本資料 → agent/edit?id=（對應舊版 edit.php?id=） */
function goEdit() {
  visible.value = false;
  router.push({ path: '/agent/edit', query: { id: agentId.value } });
}
/** 其他設置 → agent/personal_setting?agent_id=（對應舊版 personal_setting.php） */
function goOtherSetting() {
  visible.value = false;
  router.push({
    path: '/agent/personal_setting',
    query: { agent_id: agentId.value },
  });
}

/** 啟用/停用 */
async function toggleStatus(next: boolean | number | string) {
  const res: any = await editAgentStatusApi({
    id: agentId.value,
    remark: '',
    status: next ? 1 : 0,
  });
  if (res.code === 1000) {
    ElMessage.success(tl('success'));
    if (agent.value) agent.value.status = next ? 1 : 0;
  } else {
    ElMessage.error(res.message || tl('failure'));
    agent.value = agent.value ? { ...agent.value } : agent.value;
  }
}

/** 前端會員註冊開關（api/agent/edit frontend_register） */
async function toggleRegister(next: boolean | number | string) {
  const res: any = await editAgentApi({
    frontend_register: next ? 1 : 0,
    id: agentId.value,
  });
  if (res.code === 1000) {
    ElMessage.success(tl('success'));
    (agent.value as any).frontend_register = next ? 1 : 0;
  } else {
    ElMessage.error(res.message || tl('failure'));
    agent.value = agent.value ? { ...agent.value } : agent.value;
  }
}

/** 重新指定 agent 以讓開關回到實際狀態（取消或失敗時） */
function revertSwitch() {
  agent.value = agent.value ? { ...agent.value } : agent.value;
}

/**
 * 關閉別人的 2FA：輸入操作者本人當下的驗證碼（target/tickets#4503）。
 * 操作者本人須已開啟 2FA；對方套用 admin 角色由後端擋（1128）。本人關閉走頭像選單。
 */
async function turnOff2fa() {
  await loadSelfHas2fa();
  if (!selfHas2fa.value) {
    ElMessage.warning(tl('tfa_operator_not_enabled'));
    revertSwitch();
    return;
  }
  const ok = await otpDialogRef.value?.open({
    label: tl('tfa_input_own_code'),
    submit: (otp) => disable2faApi({ agent_id: agentId.value, secret: [REDACTED] }),
    title: tl('tfa_disable_other_title').replace(
      '{name}',
      agent.value?.username ?? '',
    ),
  });
  if (ok && agent.value) {
    (agent.value as any).has_2fa = 0;
  }
  revertSwitch();
}

/** 2FA 開關：開啟需先驗證對方的 OTP（api/2fa/auth）；關閉見 turnOff2fa */
async function toggle2fa(next: boolean | number | string) {
  if (!next) {
    await turnOff2fa();
    return;
  }
  try {
    const { value: otp } = await ElMessageBox.prompt(
      tl('please_input_otp'),
      tl('two_factor_authentication_test'),
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('testing'),
      },
    );
    const check: any = await check2faSecretApi({
      agent_id: agentId.value,
      secret: [REDACTED] ?? '',
    });
    if (!is2faOk(check?.code)) {
      ElMessage.error(tl('failure'));
      revertSwitch();
      return;
    }
  } catch {
    revertSwitch();
    return;
  }
  const res: any = await editAgentApi({
    has_2fa: 1,
    id: agentId.value,
  });
  if (res.code === 1000) {
    ElMessage.success(tl('success'));
    (agent.value as any).has_2fa = 1;
  } else {
    ElMessage.error(res.message || tl('failure'));
    revertSwitch();
  }
}

async function loadProfile() {
  if (!agentId.value) {
    loading.value = false;
    return;
  }
  loading.value = true;
  peoplePie.value = [];
  try {
    const [view, settings, countries] = await Promise.all([
      getAgentViewByIdApi(agentId.value),
      getCustomizedSettingApi().catch(() => []),
      getCountryDropdownApi().catch(() => []),
    ]);
    agent.value = view;
    countryMap.value = new Map((countries ?? []).map((c) => [c.id, c]));
    const map = new Map(settings.map((s) => [Number(s.setting_key), s.value]));
    twoFaSettingOn.value = Number(map.get(5001) ?? 1) === 1;
  } finally {
    loading.value = false;
  }
  // 代理/會員人數圓餅圖（人數皆 0 時不顯示）
  try {
    const res: any = await getAgentNumberOfPeopleApi(agentId.value);
    if (res?.code === 1000) {
      const a = Number(res.data?.agent ?? 0);
      const m = Number(res.data?.member ?? 0);
      if (a > 0 || m > 0) {
        peoplePie.value = [
          { name: `${tl('vendor')}：${a} ${tl('people')}`, value: a },
          { name: `${tl('member')}：${m} ${tl('people')}`, value: m },
        ];
      }
    }
  } catch {
    // 略
  }
}

function open(id: number | string) {
  agentId.value = Number(id ?? 0);
  agent.value = null;
  visible.value = true;
  loadProfile();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('agent_account_management')} - ${tl('detailinfo_user')}`"
    top="4vh"
    width="1200"
  >
    <div class="max-h-[78vh] overflow-y-auto pr-1">
      <ElSkeleton v-if="loading" :rows="8" animated />
      <div v-else-if="agent" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <!-- 左欄 -->
        <div class="space-y-4">
          <ElCard shadow="never">
            <div class="text-center">
              <div class="text-lg font-semibold">
                {{ agent.nickname || '-' }}
              </div>
              <div class="text-muted-foreground">{{ agent.username }}</div>
              <div v-if="promoteUrl" class="mt-3 flex flex-col items-center">
                <div class="text-xs break-all">{{ promoteUrl }}</div>
                <ElButton
                  link
                  size="small"
                  type="primary"
                  @click="copyPromoteUrl"
                >
                  {{ tl('copy_promote_url') }}
                </ElButton>
              </div>
              <div class="mt-4 flex flex-wrap justify-center gap-2">
                <ElButton round type="success" @click="visible = false">
                  {{ tl('btn_back') }}
                </ElButton>
                <ElButton round type="danger" @click="goEdit">
                  {{ tl('detailinfo_edit') }}
                </ElButton>
                <ElButton
                  v-if="canOtherSetting"
                  round
                  type="info"
                  @click="goOtherSetting"
                >
                  {{ tl('menu_agent_other_setting') }}
                </ElButton>
              </div>
            </div>
          </ElCard>

          <!-- 2FA -->
          <ElCard v-if="show2fa" shadow="never">
            <template #header>
              <div class="flex items-center justify-between">
                <span class="font-medium">
                  {{ tl('two_factor_authentication') }}
                </span>
                <ElSwitch
                  :model-value="(agent as any).has_2fa === 1"
                  @change="toggle2fa"
                />
              </div>
            </template>
            <p class="text-muted-foreground text-xs">
              {{ tl('tfa_secret_owner_only') }}
            </p>
          </ElCard>

          <!-- 功能開關 -->
          <ElCard shadow="never">
            <template #header>
              <span class="font-medium">{{ tl('features') }}</span>
            </template>
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium">
                    {{ tl('enable_status') }}（{{ tl('user_block') }}）
                  </div>
                  <div class="text-muted-foreground text-xs">
                    {{ tl('notallow_agent_control') }}
                  </div>
                </div>
                <ElSwitch
                  :model-value="agent.status === 1"
                  @change="toggleStatus"
                />
              </div>
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-medium">
                    {{ tl('allow_member_register') }}
                  </div>
                  <div class="text-muted-foreground text-xs">
                    {{ tl('notallow_member_register') }}
                  </div>
                </div>
                <ElSwitch
                  :model-value="(agent as any).frontend_register === 1"
                  @change="toggleRegister"
                />
              </div>
            </div>
          </ElCard>

          <!-- 代理下線/會員 人數圓餅圖 -->
          <PieCard
            v-if="peoplePie.length > 0"
            :data="peoplePie"
            :title="`${tl('agent_lower')}/${tl('member')}`"
          />
        </div>

        <!-- 右欄：基本資料 -->
        <div class="lg:col-span-2">
          <ElCard shadow="never">
            <template #header>
              <span class="font-medium">{{ tl('basic_information') }}</span>
            </template>
            <ElDescriptions :column="2" border>
              <ElDescriptionsItem :label="tl('nickname')">
                {{ agent.nickname || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('username')">
                {{ agent.username }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('real_name')">
                {{ agent.real_name || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('sharecode')">
                {{ agent.code || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('email')">
                {{ agent.email || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="`${tl('country')}/${tl('city')}`">
                {{ countryCity }}
              </ElDescriptionsItem>
              <ElDescriptionsItem
                :label="`${tl('region')} / ${tl('postalcode')}`"
              >
                {{ (agent as any).city || '-' }} /
                {{ (agent as any).zip_code || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('address')">
                {{ fullAddress }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('tel')">
                {{ agent.mobile || '-' }}
              </ElDescriptionsItem>
              <ElDescriptionsItem :label="tl('status')">
                <ElTag
                  :type="agent.status === 1 ? 'success' : 'danger'"
                  size="small"
                >
                  {{ agent.status === 1 ? tl('enable') : tl('disable') }}
                </ElTag>
              </ElDescriptionsItem>
              <!-- target/tickets#4521：看自己時只顯示 2FA 狀態；設置與關閉在頭像選單 -->
              <ElDescriptionsItem v-if="isSelfView" label="Google 2FA">
                <ElTag
                  :type="
                    Number((agent as any).has_2fa) === 1 ? 'success' : 'info'
                  "
                  size="small"
                >
                  {{
                    Number((agent as any).has_2fa) === 1
                      ? tl('tfa_status_enabled')
                      : tl('tfa_status_disabled')
                  }}
                </ElTag>
              </ElDescriptionsItem>
            </ElDescriptions>
          </ElCard>
        </div>
      </div>
    </div>
    <TwoFaOtpDialog ref="otpDialogRef" />
  </ElDialog>
</template>
