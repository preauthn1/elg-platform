<script lang="ts" setup>
import type { AgentDetailCount, AgentView } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElSkeleton,
  ElTag,
} from 'element-plus';

import { getAgentDetailApi, getAgentViewApi } from '#/api';
import { tl } from '#/locales';

const props = defineProps<{
  /** 代理自訂設定（setting_key → value）：3099 公告 / 95004 占成顯示開關 / 95001(SHARE_POINT) 自身占成 */
  settings: Map<number, any>;
}>();

const userStore = useUserStore();

const loading = ref(true);
const agent = ref<AgentView | null>(null);

/** 手機/Email 顯示切換（預設遮罩，show/hide 切換） */
const mobileRevealed = ref(false);
const emailRevealed = ref(false);

/** 公告（3099）、占成顯示開關（95004 預設顯示）、自身占成（95001=SHARE_POINT） */
const notice = computed(() => props.settings.get(3099) ?? '');
const shareVisible = computed(
  () => Number(props.settings.get(95_004) ?? 1) === 1,
);
const sharePoint = computed(() => {
  if (!shareVisible.value) return '--';
  const v = props.settings.get(95_001);
  return v === undefined || v === '' ? '--' : v;
});

/** substr_replace(mobile,'***',len-6,3) */
function maskMobile(m: string) {
  if (!m) return '--';
  const i = m.length - 6;
  if (i < 0) return m;
  return `${m.slice(0, i)}***${m.slice(i + 3)}`;
}
/** substr_replace(local,'***',len-3,3) + '@' + domain */
function maskEmail(e: string) {
  if (!e) return '--';
  const [name = '', domain] = e.split('@');
  if (!domain) return e;
  const i = name.length - 3;
  if (i < 0) return `***@${domain}`;
  return `${name.slice(0, i)}***${name.slice(i + 3)}@${domain}`;
}

const mobileText = computed(() => {
  const m = agent.value?.mobile ?? '';
  if (!m) return '--';
  return mobileRevealed.value ? m : maskMobile(m);
});
const emailText = computed(() => {
  const e = agent.value?.email ?? '';
  if (!e) return '--';
  return emailRevealed.value ? e : maskEmail(e);
});

/** 統計（團隊人數）彈窗（api/agent/detail） */
const statsVisible = ref(false);
const statsLoading = ref(false);
const stats = ref<AgentDetailCount>({});
async function openStats() {
  const id = (userStore.userInfo as any)?.id;
  if (!id) return;
  statsVisible.value = true;
  statsLoading.value = true;
  try {
    stats.value = (await getAgentDetailApi(id)) ?? {};
  } finally {
    statsLoading.value = false;
  }
}

onMounted(async () => {
  const id = (userStore.userInfo as any)?.id;
  if (!id) {
    loading.value = false;
    return;
  }
  try {
    agent.value = await getAgentViewApi(id);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <ElCard shadow="never">
    <template #header>
      <span class="text-base font-medium">{{
        tl('shareholder_personal_info')
      }}</span>
    </template>

    <ElSkeleton v-if="loading" :rows="6" animated />

    <template v-else-if="agent">
      <ElDescriptions :column="1" border>
        <ElDescriptionsItem :label="tl('login_account')">
          {{ agent.username || '--' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('name')">
          {{ agent.nickname || '--' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('telephone_number')">
          <div class="flex items-center gap-2">
            <span>{{ mobileText }}</span>
            <ElButton
              v-if="agent.mobile"
              link
              type="primary"
              @click="mobileRevealed = !mobileRevealed"
            >
              {{ mobileRevealed ? tl('hidden') : tl('show') }}
            </ElButton>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('email')">
          <div class="flex items-center gap-2">
            <span>{{ emailText }}</span>
            <ElButton
              v-if="agent.email"
              link
              type="primary"
              @click="emailRevealed = !emailRevealed"
            >
              {{ emailRevealed ? tl('hidden') : tl('show') }}
            </ElButton>
          </div>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('status')">
          <ElTag :type="agent.status === 1 ? 'success' : 'danger'" size="small">
            {{ agent.status === 1 ? tl('enable') : tl('disable') }}
          </ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('last_login_time')">
          {{ agent.last_login || '--' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('statistics')">
          <ElButton size="small" type="primary" @click="openStats">
            {{ tl('watch_data') }}
          </ElButton>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('cash_balance')">
          {{ agent.balance ?? '--' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('rolling_balance')">
          0.0000
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="tl('share')">
          {{ sharePoint }}
        </ElDescriptionsItem>
      </ElDescriptions>

      <div v-if="notice" class="mt-4">
        <span class="font-medium text-red-500">{{ tl('notice') }}：</span>
        <span class="whitespace-pre-line">{{ notice }}</span>
      </div>
    </template>

    <!-- 統計：團隊人數 -->
    <ElDialog
      v-model="statsVisible"
      :title="tl('team_headcount')"
      width="380"
      align-center
    >
      <div v-loading="statsLoading" class="space-y-2">
        <div>{{ tl('shareholder') }}：{{ stats.agent_count ?? 0 }}</div>
        <div>{{ tl('player') }}：{{ stats.member_count ?? 0 }}</div>
        <div>
          {{ tl('active_player') }}：{{ stats.active_member_count ?? 0 }}
        </div>
        <div>
          {{ tl('new_registered_player') }}：{{
            stats.registered_member_count ?? 0
          }}
        </div>
      </div>
    </ElDialog>
  </ElCard>
</template>
