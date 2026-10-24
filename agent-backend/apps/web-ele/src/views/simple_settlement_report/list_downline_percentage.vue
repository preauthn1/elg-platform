<script lang="ts" setup>
import type {
  SimpleSettlementAgentInfo,
  SimpleSettlementDownlineRow,
} from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElInput,
  ElLink,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getSimpleSettlementDownlineListApi,
  setSimpleSettlementAgentPercentageApi,
} from '#/api';
import CopyIcon from '#/components/copy-icon.vue';
import { tl } from '#/locales';

defineOptions({ name: 'SimpleSettlementListDownlinePercentage' });

const userStore = useUserStore();
/** 對齊舊版 $_SESSION['host_id']：子帳號要解析回主帳號 ID 才能正確比對 hierarchy */
const myId = userStore.userInfo?.userId ?? '';

const filters = reactive({ username: '' });

const loading = ref(false);
const errorMsg = ref('');
const list = ref<SimpleSettlementDownlineRow[]>([]);
const agentHost = ref<null | SimpleSettlementAgentInfo>(null);
const agentParent = ref<null | SimpleSettlementAgentInfo>(null);
const percentageLimit = ref<number | string>(0);

const title = computed(
  () =>
    `${tl('menu_simple_settlement_report')} > ${tl('menu_simple_settlement_report_list_downline_percentage')}`,
);

const parentAccess = computed(() => {
  const h = agentParent.value?.hierarchy ?? '';
  return myId !== '' && h.includes(`,${myId},`);
});

async function loadData() {
  loading.value = true;
  errorMsg.value = '';
  try {
    const res = await getSimpleSettlementDownlineListApi(
      filters.username || undefined,
    );
    if (Number(res.code) === 1000) {
      list.value = res.data?.agent_list ?? [];
      agentHost.value = res.data?.agent_host ?? null;
      agentParent.value = res.data?.agent_parent ?? null;
      percentageLimit.value = res.data?.percentage_limit ?? 0;
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

function searchUsername(username: string) {
  filters.username = username;
  loadData();
}

/** 編輯佔成（值需 <= percentage_limit） */
async function editPercentage(row: SimpleSettlementDownlineRow) {
  const limit = Number.parseInt(String(percentageLimit.value), 10);
  try {
    const { value } = await ElMessageBox.prompt(
      tl('percentage'),
      tl('percentage'),
      {
        inputValue: String(Number.parseInt(String(row.percentage), 10)),
        inputValidator: (val: string) => {
          if (!/^\d+$/.test(val)) return tl('percentage');
          if (Number.parseInt(val, 10) > limit) {
            return `Percentage must be less than ${limit}`;
          }
          return true;
        },
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

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="flex flex-wrap items-end gap-4">
          <ElFormItem :label="tl('agent_account')" class="!mb-0">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
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
          <ElTableColumn :label="tl('id')" prop="agent_id" width="100" />
          <ElTableColumn :label="tl('agent_account')" min-width="160">
            <template #default="{ row }">
              <span>
                <ElLink
                  type="primary"
                  underline="never"
                  @click="
                    searchUsername(
                      (row as SimpleSettlementDownlineRow).username,
                    )
                  "
                >
                  {{ row.username }}
                </ElLink>
                <CopyIcon :text="row.username" />
              </span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('percentage')" min-width="120">
            <template #default="{ row }">
              <ElLink
                underline="never"
                @click="editPercentage(row as SimpleSettlementDownlineRow)"
              >
                <b>{{ Number.parseInt(String(row.percentage), 10) }}%</b>
              </ElLink>
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_data') }}</template>
        </ElTable>
      </template>
    </ElCard>
  </Page>
</template>
