<script lang="ts" setup>
import type { ShareholderAgentDetail } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getShareholderAgentDetailApi,
  saveShareholderNicknameAndPointApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';

defineOptions({ name: 'AgentStockAdjustment' });

const filters = reactive({ username: '' });
const loading = ref(false);
const searched = ref(false);
const list = ref<ShareholderAgentDetail[]>([]);

const title = computed(
  () => `${tl('menu_cj7_user_management')} > ${tl('menu_stock_adjustment')}`,
);

const dialogVisible = ref(false);
const saving = ref(false);
const current = ref<ShareholderAgentDetail>({});
const targetShares = ref(0);

async function search() {
  if (!filters.username) return;
  loading.value = true;
  searched.value = true;
  try {
    const res = await getShareholderAgentDetailApi(filters.username);
    list.value =
      Number(res.code) === 1000 && res.data?.data ? [res.data.data] : [];
  } finally {
    loading.value = false;
  }
}

function openEdit(row: ShareholderAgentDetail) {
  current.value = row;
  targetShares.value = Math.trunc(Number(row.share_point) || 0);
  dialogVisible.value = true;
}

async function save() {
  const val = Number(targetShares.value);
  if (Number.isNaN(val) || val < 0) {
    ElMessage.error(tl('please_enter_valid_shares'));
    return;
  }
  saving.value = true;
  try {
    const res = await saveShareholderNicknameAndPointApi({
      id: current.value.agent_id ?? current.value.id ?? '',
      lower_share_point: val,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialogVisible.value = false;
      search();
    } else if (String(res.code) === '2002') {
      ElMessage.error(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search_criteria') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('account')">
            <ElInput v-model="filters.username" clearable />
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
      <template #header>
        <span class="font-medium">{{ tl('search_results') }}</span>
      </template>
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn
          :label="tl('superior_account')"
          prop="parent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('superior_shares')"
          prop="parent_share_point"
          align="right"
          min-width="110"
        />
        <ElTableColumn :label="tl('account')" prop="username" min-width="120">
          <template #default="{ row }">
            <AgentAccount :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('shares')" align="right" min-width="120">
          <template #default="{ row }">
            {{ row.share_point }} / {{ row.parent_share_point }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          min-width="100"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="openEdit(row as ShareholderAgentDetail)"
            >
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <ElDialog
      v-model="dialogVisible"
      :title="tl('stock_adjustment')"
      width="600px"
    >
      <div class="grid grid-cols-2 gap-4">
        <div>
          <div class="text-sm font-bold">{{ tl('superior_account') }}</div>
          <p class="border-b py-1">{{ current.parent_username ?? 'N/A' }}</p>
        </div>
        <div>
          <div class="text-sm font-bold">{{ tl('superior_shares') }}</div>
          <p class="border-b py-1 font-bold text-primary">
            {{ current.parent_share_point }}
          </p>
        </div>
        <div>
          <div class="text-sm font-bold">{{ tl('account') }}</div>
          <p class="border-b py-1">{{ current.username }}</p>
        </div>
        <div>
          <div class="text-sm font-bold">{{ tl('current_shares') }}</div>
          <p class="border-b py-1 font-bold text-blue-500">
            {{ current.share_point }}
          </p>
        </div>
      </div>
      <hr class="my-4" />
      <div>
        <label class="font-bold">{{ tl('target_shares') }}</label>
        <ElInputNumber
          v-model="targetShares"
          :max="Math.trunc(Number(current.parent_share_point) || 0)"
          :min="0"
          :step="1"
          :precision="0"
          class="!w-full"
        />
        <small class="text-gray-400"
          >* {{ tl('adjustment_notice_integer') }}</small
        >
      </div>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="save">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
