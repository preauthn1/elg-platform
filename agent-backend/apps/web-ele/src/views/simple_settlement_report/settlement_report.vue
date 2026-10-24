<script lang="ts" setup>
import type {
  SimpleSettlementAgentInfo,
  SimpleSettlementReportRow,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElLink,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getSimpleSettlementReportApi,
  setSimpleSettlementAgentPercentageApi,
  setSimpleSettlementStatusApi,
} from '#/api';
import CopyIcon from '#/components/copy-icon.vue';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

defineOptions({ name: 'SimpleSettlementSettlementReport' });

const userStore = useUserStore();
/** 對齊舊版 $_SESSION['host_id']：子帳號要解析回主帳號 ID 才能正確比對 hierarchy */
const myId = userStore.userInfo?.userId ?? '';

/** 最近 4 個週一 */
const periodOptions = computed(() => {
  const now = dayjs();
  const dow = now.day();
  const daysBack = dow === 1 ? 7 : (dow + 6) % 7;
  const lastMonday = now.subtract(daysBack, 'day');
  return [1, 2, 3, 4].map((w) => {
    const d = lastMonday.subtract(w * 7, 'day');
    const value = d.format('YYYY-MM-DD');
    return {
      label: `${value} ~ ${d.add(6, 'day').format('YYYY-MM-DD')}`,
      value,
    };
  });
});

const filters = reactive({
  period: periodOptions.value[0]?.value ?? '',
  username: '',
});

const loading = ref(false);
const errorMsg = ref('');
const list = ref<SimpleSettlementReportRow[]>([]);
const agentHost = ref<null | SimpleSettlementAgentInfo>(null);
const agentParent = ref<null | SimpleSettlementAgentInfo>(null);

const title = computed(
  () =>
    `${tl('menu_simple_settlement_report')} > ${tl('menu_simple_settlement_report_settlement_report')}`,
);

/** parent.hierarchy 含本帳號 id → 可點父代理 */
const parentAccess = computed(() => {
  const h = agentParent.value?.hierarchy ?? '';
  return myId !== '' && h.includes(`,${myId},`);
});

/** newThousandFormat：捨去 2 位 + 千分位，負紅正藍 */
function amtClass(v: number | string) {
  return Number(v) < 0 ? 'text-red-500' : 'text-sky-600';
}

async function loadData() {
  loading.value = true;
  errorMsg.value = '';
  try {
    const params: { period_start: string; username?: string } = {
      period_start: filters.period
        ? `${filters.period} 00:00:00`
        : dayjs().format('YYYY-MM-DD 00:00:00'),
    };
    if (filters.username) params.username = filters.username;
    const res = await getSimpleSettlementReportApi(params);
    if (Number(res.code) === 1000) {
      list.value = res.data ?? [];
      agentHost.value = res.agent_host ?? null;
      agentParent.value = res.agent_parent ?? null;
    } else {
      list.value = [];
      agentHost.value = null;
      agentParent.value = null;
      errorMsg.value = res.message ?? '';
    }
  } catch {
    list.value = [];
    errorMsg.value = '';
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  loadData();
}

/** 點帳號連結 → 帶入該帳號重查 */
function searchUsername(username: string) {
  filters.username = username;
  loadData();
}

/** 編輯佔成（顯示為 value*100 %，送出整數） */
async function editPercentage(row: SimpleSettlementReportRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      tl('percentage'),
      tl('percentage'),
      {
        inputValue: String(Number(row.percentage) * 100),
        inputPattern: /^\d+$/,
      },
    );
    const res = await setSimpleSettlementAgentPercentageApi(
      row.agent_id,
      value,
    );
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message ?? '');
    }
  } catch {
    // 取消
  }
}

/** 狀態派發 / 取消派發 */
async function setStatus(row: SimpleSettlementReportRow, value: number) {
  try {
    const res = await setSimpleSettlementStatusApi(row.id, value);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message ?? '');
    }
  } catch {
    // 失敗忽略
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('between')">
            <ElSelect v-model="filters.period" class="!w-full">
              <ElOption
                v-for="opt in periodOptions"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-red-500 text-sm">
            * {{ tl('simple_settlement_report_settlement_help_text') }}
          </span>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-loading="loading" shadow="never">
      <div v-if="errorMsg" class="text-muted-foreground py-4 text-center">
        {{ errorMsg }}
      </div>
      <template v-else>
        <h4 v-if="agentHost" class="mb-3 font-medium">
          {{ tl('agent') }}:
          <ElLink
            v-if="agentParent && parentAccess"
            type="primary"
            underline="never"
            @click="searchUsername(agentParent.username)"
          >
            {{ agentParent.username }}
          </ElLink>
          <span v-if="agentParent && parentAccess"> / </span>
          {{ agentHost.username }}, {{ tl('percentage_limit') }}:
          {{ Math.trunc(Number(agentHost.percentage) * 100) }}%
        </h4>

        <ElTable :data="list" border size="small" stripe>
          <ElTableColumn :label="tl('id')" prop="agent_id" width="80" />
          <ElTableColumn :label="tl('agent_account')" min-width="120">
            <template #default="{ row }">
              <span>
                <ElLink
                  type="primary"
                  underline="never"
                  @click="
                    searchUsername((row as SimpleSettlementReportRow).username)
                  "
                >
                  {{ row.username }}
                </ElLink>
                <CopyIcon :text="row.username" />
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('percentage')" min-width="100">
            <template #default="{ row }">
              <ElLink
                underline="never"
                @click="editPercentage(row as SimpleSettlementReportRow)"
              >
                {{ Number(row.percentage) * 100 }} %
              </ElLink>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('bet')" align="right" min-width="110">
            <template #default="{ row }">
              <span :class="amtClass(row.bet)">{{
                amountThousands(row.bet, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('gross_pnl')" align="right" min-width="110">
            <template #default="{ row }">
              <span :class="amtClass(row.gross_pnl)">{{
                amountThousands(row.gross_pnl, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('bonus')" align="right" min-width="110">
            <template #default="{ row }">
              <span :class="amtClass(row.bonus)">{{
                amountThousands(row.bonus, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('net_pnl')" align="right" min-width="110">
            <template #default="{ row }">
              <span :class="amtClass(row.net_pnl)">{{
                amountThousands(row.net_pnl, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('total_commission')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              <span :class="amtClass(row.total_commission)">{{
                amountThousands(row.total_commission, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('team_commission')"
            align="right"
            min-width="120"
          >
            <template #default="{ row }">
              <span :class="amtClass(row.team_commission)">{{
                amountThousands(row.team_commission, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('commission')"
            align="right"
            min-width="110"
          >
            <template #default="{ row }">
              <span :class="amtClass(row.commission)">{{
                amountThousands(row.commission, 2)
              }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('status')" min-width="130">
            <template #default="{ row }">
              <ElButton
                v-if="Number(row.status) === 0"
                size="small"
                type="success"
                @click="setStatus(row as SimpleSettlementReportRow, -1)"
              >
                {{ tl('commission_pay_status_op_0') }}
              </ElButton>
              <span v-else-if="Number(row.status) === 1" class="text-primary">
                <span class="text-blue-500">{{
                  tl('commission_pay_status_1')
                }}</span>
                <br />
                {{ row.pay_time }}
              </span>
              <ElButton
                v-else-if="Number(row.status) === -1"
                size="small"
                type="danger"
                @click="setStatus(row as SimpleSettlementReportRow, 0)"
              >
                {{ tl('commission_pay_status_op_-1') }}
              </ElButton>
            </template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('updated_at')"
            prop="updated_at"
            min-width="150"
          />
          <template #empty>{{ tl('no_data') }}</template>
        </ElTable>
      </template>
    </ElCard>
  </Page>
</template>
