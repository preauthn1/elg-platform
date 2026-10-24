<script lang="ts" setup>
import type { CustomerProportionRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCustomerProportionListApi,
  getVendorFeeDropdownApi,
  saveCustomerVendorFeeApi,
  updateCustomerProportionApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'CustomerProportionIndex' });

const PER_PAGE = 20;

const router = useRouter();

const filters = reactive({ username: '' });
const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<CustomerProportionRow>(
    ({ page, per_page }) =>
      getCustomerProportionListApi({
        page,
        per_page,
        username: filters.username,
      }),
    { perPage: PER_PAGE, showError: true },
  );

function parseJson(s: string): null | Record<string, number | string> {
  if (!s || s === '-') return null;
  try {
    const o = JSON.parse(s);
    return o && typeof o === 'object' ? o : null;
  } catch {
    return null;
  }
}

/** 單值盤費（type!=1）顯示的 vendor_fee[0] */
function subVendorFee(row: CustomerProportionRow): number | string {
  const o = parseJson(row.vendor_fee);
  return o ? Number(o[0] ?? 0) * 100 : '-';
}

// 單值 / 備註 dialog
type SimpleMode = 'bonus' | 'proportion' | 'remark' | 'subvendor';
const simpleVisible = ref(false);
const simpleSaving = ref(false);
const simple = reactive({
  id: '' as number | string,
  type: 1 as number,
  value: '' as number | string,
  title: '',
  isRemark: false,
});

const SIMPLE_META: Record<SimpleMode, { titleKey: string; type: number }> = {
  proportion: { type: 1, titleKey: 'enter_percentage' },
  subvendor: { type: 2, titleKey: 'enter_vendor_fee_percentage' },
  bonus: { type: 3, titleKey: 'enter_bonus_percentage' },
  remark: { type: 4, titleKey: 'enter_memo' },
};

function openSimple(
  mode: SimpleMode,
  row: CustomerProportionRow,
  value: number | string,
) {
  const meta = SIMPLE_META[mode];
  simple.id = row.id;
  simple.type = meta.type;
  simple.title = tl(meta.titleKey);
  simple.isRemark = mode === 'remark';
  simple.value = value;
  simpleVisible.value = true;
}

async function handleSimpleSave() {
  simpleSaving.value = true;
  try {
    const res = await updateCustomerProportionApi(
      simple.id,
      simple.type,
      simple.value,
    );
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      simpleVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    simpleSaving.value = false;
  }
}

// 多遊戲商盤費 dialog
const vendorVisible = ref(false);
const vendorSaving = ref(false);
const vendorEditing = reactive({
  id: '' as number | string,
  fields: [] as Array<{ id: number; name: string; value: number | string }>,
});

async function openVendor(row: CustomerProportionRow) {
  const current = parseJson(row.vendor_fee) ?? {};
  const vendors = await getVendorFeeDropdownApi().catch(() => []);
  vendorEditing.id = row.id;
  vendorEditing.fields = (vendors ?? []).map((v) => ({
    id: v.id,
    name: v.name,
    value: Number(current[v.id] ?? 0) * 100,
  }));
  vendorVisible.value = true;
}

async function handleVendorSave() {
  vendorSaving.value = true;
  try {
    const percents: Record<string, number | string> = {};
    for (const f of vendorEditing.fields) percents[f.id] = f.value;
    const res = await saveCustomerVendorFeeApi(vendorEditing.id, percents);
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      vendorVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    vendorSaving.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_settlement_report')} > ${tl('menu_customer_proportion_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem class="!mb-0">
            <template #label>
              {{ tl('agent_account') }} <span class="text-red-500">*</span>
            </template>
            <ElInput
              v-model="filters.username"
              :placeholder="tl('required_column')"
              clearable
            />
          </ElFormItem>
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
      <div class="mt-3">
        <ElButton type="info" @click="router.push({ path: '/agent/edit' })">
          {{ tl('create_agent_account') }}
        </ElButton>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('sharecode')"
          min-width="110"
          prop="sharecode"
        />
        <ElTableColumn
          :label="`${tl('agent')}${tl('username')}`"
          min-width="140"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('company_proportion')" min-width="150">
          <template #default="{ row }">
            <div>
              {{
                row.customer_proportion === '-'
                  ? '-'
                  : `${Number(row.customer_proportion) * 100} %`
              }}
            </div>
            <ElButton
              v-if="row.customer_proportion !== '-'"
              class="mt-1"
              size="small"
              @click="
                openSimple(
                  'proportion',
                  row as CustomerProportionRow,
                  Number(row.customer_proportion) * 100,
                )
              "
            >
              {{ tl('setting') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('company_vendor_fee')" min-width="150">
          <template #default="{ row }">
            <template v-if="Number(row.type) === 1">
              <ElButton
                size="small"
                @click="openVendor(row as CustomerProportionRow)"
              >
                {{ tl('setting') }}
              </ElButton>
            </template>
            <template v-else>
              <div>{{ subVendorFee(row as CustomerProportionRow) }} %</div>
              <ElButton
                class="mt-1"
                size="small"
                @click="
                  openSimple(
                    'subvendor',
                    row as CustomerProportionRow,
                    subVendorFee(row as CustomerProportionRow),
                  )
                "
              >
                {{ tl('setting') }}
              </ElButton>
            </template>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('bonus_fee')" min-width="130">
          <template #default="{ row }">
            <div>
              {{ row.bonus === '-' ? '-' : `${Number(row.bonus) * 100} %` }}
            </div>
            <ElButton
              v-if="row.bonus !== '-'"
              class="mt-1"
              size="small"
              @click="
                openSimple(
                  'bonus',
                  row as CustomerProportionRow,
                  Number(row.bonus) * 100,
                )
              "
            >
              {{ tl('setting') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('memo')" min-width="160">
          <template #default="{ row }">
            <div class="whitespace-pre-wrap">{{ row.remark }}</div>
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
              @click="
                openSimple('remark', row as CustomerProportionRow, row.remark)
              "
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

    <ElDialog v-model="simpleVisible" :title="simple.title" width="420">
      <ElInput
        v-if="simple.isRemark"
        v-model="simple.value"
        :rows="5"
        type="textarea"
      />
      <ElInput v-else v-model="simple.value">
        <template #append>%</template>
      </ElInput>
      <template #footer>
        <ElButton @click="simpleVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="simpleSaving"
          type="primary"
          @click="handleSimpleSave"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>

    <ElDialog
      v-model="vendorVisible"
      :title="tl('company_vendor_fee')"
      width="640"
    >
      <ElForm label-width="160">
        <ElFormItem
          v-for="f in vendorEditing.fields"
          :key="f.id"
          :label="f.name"
        >
          <ElInput v-model="f.value">
            <template #append>%</template>
          </ElInput>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="vendorVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="vendorSaving"
          type="primary"
          @click="handleVendorSave"
        >
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
