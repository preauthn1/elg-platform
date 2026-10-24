<script lang="ts" setup>
import type { NewbieChallengeProgressRow } from '#/api';

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
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getNewbieChallengeMonitorApi,
  getNewbieChallengeProgressListApi,
  quitNewbieChallengeApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { amountThousands, numberFormat } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

import BalanceDialog from './components/balance-dialog.vue';
import ProgressLogDialog from './components/progress-log-dialog.vue';
import { NEWBIE_STAGES, stageText } from './stage-map';

defineOptions({ name: 'NewbieChallengeRecord' });

const PER_PAGE = 20;

const filters = reactive({
  member_username: '',
  stage: '' as number | string,
});

const loading = ref(false);
const quitting = ref<number | string>('');
const page = ref(1);
const total = ref(0);
const list = ref<NewbieChallengeProgressRow[]>([]);

const counts = reactive({
  abandoned: 0,
  bonusPending: 0,
  challenging: 0,
  completed: 0,
});

const stats = computed(() => [
  {
    label: tl('newbie_challenge_challenging_members'),
    value: numberFormat(counts.challenging, 0),
  },
  {
    label: tl('newbie_challenge_bonus_pending_members'),
    value: numberFormat(counts.bonusPending, 0),
  },
  {
    label: tl('newbie_challenge_completed_members'),
    value: numberFormat(counts.completed, 0),
  },
  {
    label: tl('newbie_challenge_abandoned_members'),
    value: numberFormat(counts.abandoned, 0),
  },
]);

const logDialog = ref<InstanceType<typeof ProgressLogDialog>>();
const balanceDialog = ref<InstanceType<typeof BalanceDialog>>();

async function loadMonitor() {
  const res = await getNewbieChallengeMonitorApi();
  if (Number(res.code) !== 200) return;
  const by = new Map(
    (res.data ?? []).map((r) => [
      r.status_group ?? '',
      Number(r.member_count ?? 0),
    ]),
  );
  counts.challenging = by.get('challenging') ?? 0;
  counts.bonusPending = by.get('bonus_pending') ?? 0;
  counts.completed = by.get('completed') ?? 0;
  // 已放棄/失效＝會員自己放棄 + 代理後台強制取消 + 彩金視窗過期未領
  counts.abandoned =
    (by.get('quit') ?? 0) +
    (by.get('operator_quit') ?? 0) +
    (by.get('expired') ?? 0);
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getNewbieChallengeProgressListApi({
      member_username: filters.member_username,
      page: page.value,
      per_page: PER_PAGE,
      ...(filters.stage === '' ? {} : { stage: filters.stage }),
    });
    if (Number(res.code) === 200) {
      list.value = res.data?.items ?? [];
      total.value = res.data?.pagination?.total ?? 0;
    } else {
      list.value = [];
      total.value = 0;
      ElMessage.error(res.message ?? '');
    }
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

function handlePageChange(p: number) {
  page.value = p;
  loadData();
}

function clearFilters() {
  filters.member_username = '';
  filters.stage = '';
  search();
}

async function handleQuit(row: NewbieChallengeProgressRow) {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'), {
      cancelButtonText: tl('cancel'),
      confirmButtonText: tl('submit'),
      type: 'warning',
    });
  } catch {
    return;
  }
  quitting.value = row.id;
  try {
    const res = await quitNewbieChallengeApi(row.id);
    if (Number(res.code) === 200) {
      await Promise.all([loadData(), loadMonitor()]);
      return;
    }
    ElMessage.error(res.message ?? '');
  } finally {
    quitting.value = '';
  }
}

async function reload() {
  await Promise.all([loadData(), loadMonitor()]);
}

const title = computed(
  () =>
    `${tl('menu_newbie_challenge')} > ${tl('newbie_challenge_record_page')}`,
);

onMounted(reload);
</script>

<template>
  <Page :title="title">
    <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <ElCard v-for="s in stats" :key="s.label" shadow="never">
        <div class="text-center">
          <div class="text-muted-foreground text-xs">{{ s.label }}</div>
          <div class="mt-1 text-2xl font-bold">{{ s.value }}</div>
        </div>
      </ElCard>
    </div>

    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('newbie_challenge_member_username')">
            <ElInput v-model="filters.member_username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.stage" class="!w-full">
              <ElOption :label="tl('newbie_challenge_all_stages')" value="" />
              <ElOption
                v-for="s in NEWBIE_STAGES"
                :key="s"
                :label="stageText(s)"
                :value="s"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex items-center justify-end gap-2">
          <ElButton @click="clearFilters">{{ tl('clear') }}</ElButton>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('newbie_challenge_member_username')"
          min-width="130"
        >
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="150">
          <template #default="{ row }">
            {{ stageText(row.stage) }}
            <span v-if="row.is_bonus_window_expired" class="text-red-500">
              （{{ tl('newbie_challenge_expired') }}）
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_unlocked_bonus')"
          align="right"
          min-width="120"
        >
          <template #default="{ row }">
            {{ row.unlocked_bonus_amount ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('newbie_challenge_bonus_countdown')"
          min-width="170"
        >
          <template #default="{ row }">
            {{ row.bonus_window_expire_at ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('event_wallet_balance')"
          align="right"
          min-width="130"
        >
          <template #default="{ row }">
            {{ amountThousands(row.event_wallet_balance) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('features')"
          align="left"
          fixed="right"
          width="240"
        >
          <template #default="{ row }">
            <div class="flex flex-wrap items-center gap-2">
              <ElButton
                class="!ml-0"
                size="small"
                @click="logDialog?.open(row.id)"
              >
                {{ tl('log') }}
              </ElButton>
              <ElButton
                class="!ml-0"
                size="small"
                @click="balanceDialog?.open(row.id)"
              >
                {{ tl('event_wallet_balance') }}{{ tl('modify') }}
              </ElButton>
              <ElButton
                v-if="[1, 2, 3].includes(Number(row.stage))"
                :loading="quitting === row.id"
                class="!ml-0"
                size="small"
                type="danger"
                @click="handleQuit(row as NewbieChallengeProgressRow)"
              >
                {{ tl('newbie_challenge_cancel') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>

      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <ProgressLogDialog ref="logDialog" />
    <BalanceDialog ref="balanceDialog" @saved="reload" />
  </Page>
</template>
