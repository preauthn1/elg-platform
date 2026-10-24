<script lang="ts" setup>
import type { SmsGatewayRow } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElMessage,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getSmsGatewayListApi, toggleSmsGatewayStatusApi } from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import SmsGatewayEditDialog from './components/sms-gateway-edit-dialog.vue';

defineOptions({ name: 'SmsGatewayList' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof SmsGatewayEditDialog>>();
const loading = ref(false);
const allRows = ref<SmsGatewayRow[]>([]);

// 後端 sms/gateway/list 不支援真正分頁（永遠回傳全部，page/per_page 沒有作用），
// 改用前端分頁，避免切頁看起來「資料都一樣」
const {
  handlePageChange,
  page,
  pagedRows: list,
  total,
} = useClientTable(allRows, { perPage: PER_PAGE });

async function loadData() {
  loading.value = true;
  try {
    const res = await getSmsGatewayListApi({
      page: 1,
      per_page: 100_000,
      status: 1,
    });
    allRows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function countryNames(row: SmsGatewayRow): string[] {
  if (!row.country_name) return [];
  return row.country_name.split(',');
}

async function onToggle(row: SmsGatewayRow, next: boolean) {
  const res = await toggleSmsGatewayStatusApi(row.id, next).catch(() => null);
  if (res && Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res?.message);
  }
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('sms_management')} > ${tl('sms_gateway_list')}`">
    <ElCard class="mb-3" shadow="never">
      <ElButton type="success" @click="editRef?.open()">
        {{ tl('add') }}
      </ElButton>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('sms_gateway_name')"
          min-width="120"
          prop="name"
        />
        <ElTableColumn
          :label="tl('sms_gateway_code')"
          min-width="100"
          prop="code"
        />
        <ElTableColumn :label="tl('api_url')" min-width="200" prop="url" />
        <ElTableColumn :label="tl('support_country')" min-width="140">
          <template #default="{ row }">
            <span v-if="!row.country_code" class="text-red-500">
              {{ tl('not_set') }}
            </span>
            <div
              v-for="(n, i) in countryNames(row as SmsGatewayRow)"
              v-else
              :key="i"
            >
              {{ n }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="140" prop="memo" />
        <ElTableColumn :label="tl('enable_status')" min-width="140">
          <template #default="{ row }">
            <div
              :class="Number(row.status) === 1 ? '' : 'text-red-500 font-bold'"
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </div>
            <ElSwitch
              :active-text="tl('enable')"
              :inactive-text="tl('disable')"
              :model-value="Number(row.status) === 1"
              class="mt-1"
              @change="(v) => onToggle(row as SmsGatewayRow, Boolean(v))"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('edit')"
          align="center"
          width="90"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              @click="editRef?.open((row as SmsGatewayRow).id)"
            >
              {{ tl('edit') }}
            </ElButton>
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

    <SmsGatewayEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
