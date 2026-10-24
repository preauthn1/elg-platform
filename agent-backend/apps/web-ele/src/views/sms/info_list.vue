<script lang="ts" setup>
import type { SmsDropdownRow, SmsInfoRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getSmsDropdownApi,
  getSmsGroupDropdownApi,
  getSmsInfoListApi,
  toggleSmsInfoStatusApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

import SmsInfoEditDialog from './components/sms-info-edit-dialog.vue';

defineOptions({ name: 'SmsInfoList' });

const PER_PAGE = 20;

const editRef = ref<InstanceType<typeof SmsInfoEditDialog>>();
const gateways = ref<SmsDropdownRow[]>([]);
const groups = ref<SmsDropdownRow[]>([]);
const filters = reactive({
  sms_gateway_id: 0 as number | string,
  status: -1 as number | string,
  agent_group_id: 0 as number | string,
});

const searched = ref(false);
const loading = ref(false);
const allRows = ref<SmsInfoRow[]>([]);

// 後端 sms/info/list 不支援真正分頁（永遠回傳全部，page/per_page 沒有作用），
// 改用前端分頁，避免切頁看起來「資料都一樣」
const {
  handlePageChange,
  page,
  pagedRows: list,
  total,
} = useClientTable(allRows, { perPage: PER_PAGE });

async function loadData() {
  loading.value = true;
  searched.value = true;
  try {
    const res = await getSmsInfoListApi({
      agent_group_id: filters.agent_group_id,
      page: 1,
      per_page: 100_000,
      sms_gateway_id: filters.sms_gateway_id,
      status: filters.status,
    });
    allRows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function search() {
  page.value = 1;
  loadData();
}

/** sms 參數 label：有翻譯用翻譯，否則顯示原 key */
function paraLabel(key: string): string {
  const t = tl(`sms_para_${key}`);
  return t === `sms_para_${key}` ? key : t;
}

/** sms_gateway_info(schema) + info(值) → 顯示列 */
function gatewayInfoRows(
  row: SmsInfoRow,
): Array<{ label: string; value: string }> {
  let schema: Record<string, any>;
  let info: Record<string, any>;
  try {
    schema = JSON.parse(row.sms_gateway_info) ?? {};
  } catch {
    schema = {};
  }
  try {
    info = JSON.parse(example.invalid) ?? {};
  } catch {
    info = {};
  }
  const out: Array<{ label: string; value: string }> = [];
  for (const [key, param] of Object.entries(schema)) {
    out.push({
      label: paraLabel(key),
      value: param && (param as any).type ? String(info[key] ?? '') : '',
    });
  }
  return out;
}

/** countries 字串 "code:name:prefix,..." → ["name (prefix)", ...] */
function countryList(row: SmsInfoRow): string[] {
  if (!row.countries) return [];
  return row.countries.split(',').map((c) => {
    const [, name, prefix] = c.split(':');
    return `${name} (${prefix})`;
  });
}

async function onToggle(row: SmsInfoRow, next: boolean) {
  const res = await toggleSmsInfoStatusApi(row.id, next).catch(() => null);
  if (res && Number(res.code) === 1000) {
    ElMessage.success(res.message);
    loadData();
  } else {
    ElMessage.error(res?.message);
  }
}

onMounted(async () => {
  const [gw, gp] = await Promise.all([
    getSmsDropdownApi().catch(() => []),
    getSmsGroupDropdownApi().catch(() => []),
  ]);
  gateways.value = gw ?? [];
  groups.value = gp ?? [];
});
</script>

<template>
  <Page :title="`${tl('sms_management')} > ${tl('sms_info_list')}`">
    <ElCard class="mb-3" shadow="never">
      <ElButton type="success" @click="editRef?.open()">
        {{ tl('add') }}
      </ElButton>
    </ElCard>

    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('sms_gateway_name')">
            <ElSelect v-model="filters.sms_gateway_id" class="!w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="g in gateways"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" :value="-1" />
              <ElOption :label="tl('enable')" :value="1" />
              <ElOption :label="tl('disable')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('agent_group_type_3')">
            <ElSelect v-model="filters.agent_group_id" class="!w-full">
              <ElOption :label="tl('all')" :value="0" />
              <ElOption
                v-for="g in groups"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard v-if="searched" shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('agent_group_type_3')" min-width="140">
          <template #default="{ row }">
            <div class="font-bold">{{ row.agent_group_name }}</div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }} {{ row.id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sms_gateway_name')"
          min-width="120"
          prop="sms_gateway_name"
        />
        <ElTableColumn :label="tl('sms_gateway_info')" min-width="220">
          <template #default="{ row }">
            <div
              v-for="(it, i) in gatewayInfoRows(row as SmsInfoRow)"
              :key="i"
              class="break-all"
            >
              <b>{{ it.label }}</b
              >: {{ it.value }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('sms_fee')" min-width="90" prop="cost" />
        <ElTableColumn :label="tl('field_sort')" min-width="80" prop="sort" />
        <ElTableColumn :label="tl('support_country')" min-width="160">
          <template #default="{ row }">
            <span v-if="!row.countries" class="text-red-500">
              {{ tl('not_set') }}
            </span>
            <div
              v-for="(c, i) in countryList(row as SmsInfoRow)"
              v-else
              :key="i"
            >
              {{ c }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="120">
          <template #default="{ row }">
            <ElSwitch
              :active-text="tl('enable')"
              :inactive-text="tl('disable')"
              :model-value="Number(row.status) === 1"
              @change="(v) => onToggle(row as SmsInfoRow, Boolean(v))"
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
              @click="editRef?.open((row as SmsInfoRow).id)"
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

    <SmsInfoEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
