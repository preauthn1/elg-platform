<script lang="ts" setup>
import type { FlowLimitRow } from '#/api';

import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { cancelMemberFlowLimitApi, getMemberFlowLimitApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';
import { fromApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'MemberFlowLimitDialog' });

/** closed：彈窗關閉（可能已刪除／取消稽核），讓開啟方（如會員詳情抽屜）重抓差額 */
const emit = defineEmits<{ closed: [] }>();

const PER_PAGE = 10;

const visible = ref(false);
const loading = ref(false);
const memberId = ref(0);
const list = ref<FlowLimitRow[]>([]);
const page = ref(1);
const total = ref(0);
const statusZeroExists = ref(false);

async function load() {
  loading.value = true;
  try {
    const res = await getMemberFlowLimitApi({
      member_id: memberId.value,
      page: page.value,
      per_page: PER_PAGE,
    });
    list.value = res.items ?? [];
    total.value = res.pagination?.total ?? 0;
    statusZeroExists.value = Boolean(res.status_zero_exists);
  } catch {
    // 錯誤訊息由攔截器顯示
    list.value = [];
    total.value = 0;
    statusZeroExists.value = false;
  } finally {
    loading.value = false;
  }
}

function open(id: number) {
  memberId.value = id;
  page.value = 1;
  visible.value = true;
  load();
}

function handlePage(p: number) {
  page.value = p;
  load();
}

/** 種類顯示 */
function kindText(row: FlowLimitRow) {
  if (row.event_id && row.event_id !== 0 && row.type === 105) {
    return tl('event_mode');
  }
  return tl(`transaction_type.${row.type}`, String(row.type));
}

function statusClass(status: number) {
  if (status === 0) return 'text-red-500';
  if (status === 1) return 'text-green-600';
  if (status === 9) return 'text-purple-500';
  return '';
}

/** 取消單筆流水限制（需填備註） */
async function cancelOne(row: FlowLimitRow) {
  try {
    const { value: remark } = await ElMessageBox.prompt(
      tl('please_write_memo_info'),
      '',
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
      },
    );
    const res = await cancelMemberFlowLimitApi({
      id: row.id,
      remark: remark ?? '',
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      load();
    } else {
      // gateway 一律回 HTTP 200，body 模式下攔截器不會跳錯誤，要自己顯示
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消輸入
  }
}

/** 取消該會員全部未通過 */
async function cancelAll() {
  try {
    const { value: remark } = await ElMessageBox.prompt(
      tl('flow_limit_prompt_message'),
      '',
      {
        cancelButtonText: tl('cancel'),
        confirmButtonText: tl('submit'),
      },
    );
    const res = await cancelMemberFlowLimitApi({
      member_id: memberId.value,
      remark: remark ?? '',
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      load();
    } else {
      // gateway 一律回 HTTP 200，body 模式下攔截器不會跳錯誤，要自己顯示
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消輸入
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('flow_limit')"
    width="900"
    align-center
    @closed="emit('closed')"
  >
    <div class="mb-2 flex justify-end">
      <ElButton
        v-if="statusZeroExists"
        size="small"
        type="primary"
        @click="cancelAll"
      >
        {{ tl('all_delete') }}
      </ElButton>
    </div>
    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('kind')" width="110">
        <template #default="{ row }">
          {{ kindText(row as FlowLimitRow) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('event_name')" min-width="120">
        <template #default="{ row }">
          <div v-if="Number(row.event_id) < 0">
            <div>{{ tl('transaction_type.107') }}</div>
            <div>{{ row.title }}</div>
          </div>
          <template v-else>{{ row.title }}</template>
        </template>
      </ElTableColumn>
      <ElTableColumn
        :label="tl('unlimit_bonus')"
        prop="bonus"
        align="right"
        width="110"
      />
      <ElTableColumn
        :label="tl('flow_limit')"
        prop="flow_limit"
        align="right"
        width="110"
      />
      <ElTableColumn :label="tl('completed_amount')" align="right" width="120">
        <template #default="{ row }">
          {{ row.status === 9 ? '-' : row.valid_flow_limit }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('status')" width="90">
        <template #default="{ row }">
          <span :class="statusClass(row.status)">{{ row.status_text }}</span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('memo')" prop="remark" min-width="120" />
      <ElTableColumn :label="tl('created_time')" width="170">
        <template #default="{ row }">
          <div>{{ fromApiTime(row.created_at) }}</div>
          <div class="text-muted-foreground">
            {{ fromApiTime(row.updated_at) }}
          </div>
        </template>
      </ElTableColumn>
      <ElTableColumn align="center" width="80">
        <template #default="{ row }">
          <ElButton
            v-if="row.status === 0"
            size="small"
            type="danger"
            @click="cancelOne(row as FlowLimitRow)"
          >
            {{ tl('delete') }}
          </ElButton>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePage"
    />
  </ElDialog>
</template>
