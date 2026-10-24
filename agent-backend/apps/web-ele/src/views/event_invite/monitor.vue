<script lang="ts" setup>
import type { EventInviteCloseUserRow, EventInviteMonitorData } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getEventInviteCloseUserListApi,
  getEventInviteMonitorApi,
  openEventInviteJoinUserApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import FixedTimezoneHint from '#/components/fixed-timezone-hint.vue';
import { tl } from '#/locales';
import { amountThousands, numberFormat } from '#/utils/format';
import { nowInUserTz } from '#/utils/timezone-shift';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import { eventInviteStatusTagType, eventInviteStatusText } from './status-map';

defineOptions({ name: 'EventInviteMonitor' });

/** 頁面口徑固定 GMT+8，日期原樣送出不平移 */
const FIXED_OFFSET = 8;

const monitor = ref<EventInviteMonitorData>({});
const monitorLoading = ref(false);

const stats = computed(() => [
  {
    label: `${tl('in_progress')}${tl('reward')}${tl('quantity')}`,
    value: numberFormat(monitor.value.processing_count ?? 0, 0),
  },
  {
    label: `${tl('completed')}${tl('quantity')}`,
    value: numberFormat(monitor.value.completed_count ?? 0, 0),
  },
  {
    label: `${tl('accumulated')}${tl('unlocked')}${tl('amount')}`,
    value: `RM ${amountThousands(monitor.value.total_unlock_point ?? 0)}`,
  },
  {
    label: `${tl('accumulated')}${tl('received')}${tl('amount')}`,
    value: `RM ${amountThousands(monitor.value.total_receive ?? 0)}`,
  },
  {
    label: `${tl('today')}${tl('newly_added')}${tl('invite_bind')}`,
    value: numberFormat(monitor.value.today_new_join_count ?? 0, 0),
  },
]);

const filters = reactive({
  date_range: [
    nowInUserTz(FIXED_OFFSET).subtract(1, 'month').format('YYYY-MM-DD'),
    nowInUserTz(FIXED_OFFSET).format('YYYY-MM-DD'),
  ] as string[],
  invitee: '',
  inviter: '',
});

const loading = ref(false);
const restoring = ref<number | string>('');
const list = ref<EventInviteCloseUserRow[]>([]);

async function loadMonitor() {
  monitorLoading.value = true;
  try {
    const res = await getEventInviteMonitorApi();
    monitor.value = Number(res.code) === 1000 ? (res.data ?? {}) : {};
    if (Number(res.code) !== 1000) ElMessage.error(res.message ?? '');
  } finally {
    monitorLoading.value = false;
  }
}

async function loadData() {
  const [start, end] = filters.date_range ?? [];
  loading.value = true;
  try {
    const res = await getEventInviteCloseUserListApi({
      end_date: end ?? '',
      invitee: filters.invitee,
      inviter: filters.inviter,
      start_date: start ?? '',
    });
    if (Number(res.code) === 1000) {
      list.value = res.data?.list ?? [];
    } else {
      list.value = [];
      ElMessage.error(res.message ?? '');
    }
  } finally {
    loading.value = false;
  }
}

async function handleRestore(row: EventInviteCloseUserRow) {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('submit'),
      type: 'warning',
    });
  } catch {
    return;
  }
  restoring.value = row.id;
  try {
    const res = await openEventInviteJoinUserApi(row.id);
    const code = String(res.code);
    if (code === '1000') {
      list.value = list.value.filter((r) => r.id !== row.id);
      await loadMonitor();
      return;
    }
    ElMessage.error(
      code === '2002' ? String(res.data ?? '') : (res.message ?? ''),
    );
  } finally {
    restoring.value = '';
  }
}

const title = computed(
  () =>
    `${tl('menu_event')} > ${tl('menu_event_invite')} > ${tl('menu_event_invite_monitor')}`,
);

onMounted(() => {
  loadMonitor();
  loadData();
});
</script>

<template>
  <Page :title="title">
    <div
      v-loading="monitorLoading"
      class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5"
    >
      <ElCard v-for="s in stats" :key="s.label" shadow="never">
        <div class="text-muted-foreground text-xs">{{ s.label }}</div>
        <div class="mt-1 text-2xl font-bold">{{ s.value }}</div>
      </ElCard>
    </div>

    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="loadData">
        <div
          class="grid grid-cols-1 items-start gap-x-4 md:grid-cols-2 lg:grid-cols-4"
        >
          <ElFormItem :label="`${tl('inviter')}${tl('account')}`">
            <ElInput
              v-model="filters.inviter"
              :placeholder="`${tl('example')}：U100234`"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="`${tl('invitee')}${tl('account')}`">
            <ElInput
              v-model="filters.invitee"
              :placeholder="`${tl('example')}：U100901`"
              clearable
            />
          </ElFormItem>
          <ElFormItem :label="tl('between')" class="lg:col-span-2">
            <DateRangeFields
              v-model="filters.date_range"
              :fixed-offset="FIXED_OFFSET"
            />
            <FixedTimezoneHint text-key="fixed_tz_hint_no_shift" />
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <div class="mb-3 font-medium">
        {{ tl('closed') }}{{ tl('reward') }}{{ tl('review') }}
      </div>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <!-- 工單4342：會員帳號欄改掛 MemberAccountLink（可點開會員詳情＋複製 icon） -->
        <ElTableColumn :label="tl('inviter')" prop="inviter" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.inviter" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('invitee')" prop="invitee" min-width="120">
          <template #default="{ row }">
            <MemberAccountLink :username="row.invitee" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('event_repeat')}IP`"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <span v-if="row.duplicate_ip" class="text-red-500">
              {{ row.duplicate_ip }}
            </span>
            <span v-else class="text-muted-foreground">✕</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('event_repeat')}${tl('mobile')}`"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <span v-if="row.duplicate_mobile" class="text-red-500">
              {{ row.duplicate_mobile }}
            </span>
            <span v-else class="text-muted-foreground">✕</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('event_repeat')}${tl('realname')}`"
          align="center"
          min-width="140"
        >
          <template #default="{ row }">
            <span v-if="row.duplicate_realname" class="text-red-500">
              {{ row.duplicate_realname }}
            </span>
            <span v-else class="text-muted-foreground">✕</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">
            <ElTag :type="eventInviteStatusTagType(row.status)" size="small">
              {{ eventInviteStatusText(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          fixed="right"
          width="140"
        >
          <template #default="{ row }">
            <ElButton
              :loading="restoring === row.id"
              size="small"
              type="primary"
              @click="handleRestore(row as EventInviteCloseUserRow)"
            >
              {{ tl('restore') }}{{ tl('in_progress') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>
  </Page>
</template>
