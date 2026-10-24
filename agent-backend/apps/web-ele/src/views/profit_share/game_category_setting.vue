<script lang="ts" setup>
import type {
  ProfitShareGameCategoryPendingRow,
  ProfitShareGameCategoryRow,
} from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInputNumber,
  ElMessage,
  ElMessageBox,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  cancelProfitShareAgentScheduleApi,
  getProfitShareGameCategoryListApi,
  getProfitShareUnexecutedGameCategoryApi,
  setProfitShareGameCategoryApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import { tl } from '#/locales';

defineOptions({ name: 'ProfitShareGameCategorySetting' });

const userStore = useUserStore();
/** 本頁目前僅支援檢視自己的設定，尚無下鑽指定代理的入口 */
const selfUsername = computed(() =>
  String((userStore.userInfo as any)?.username ?? ''),
);

const loading = ref(false);
const agentUsername = ref('');
const categories = ref<ProfitShareGameCategoryRow[]>([]);
const pending = ref<ProfitShareGameCategoryPendingRow[]>([]);

/** 有未執行排程的類別 id（按鈕變「處理中」disabled） */
const pendingCategoryIds = computed(
  () => new Set(pending.value.map((p) => Number(p.shareholder_category_id))),
);

function isSet(row: ProfitShareGameCategoryRow) {
  return row.direct_percentage !== null && row.direct_percentage !== undefined;
}
function isSetting(row: ProfitShareGameCategoryRow) {
  return !pendingCategoryIds.value.has(Number(row.shareholder_category_id));
}

const dialog = reactive({
  agentId: '' as number | string,
  categoryId: '' as number | string,
  direct: 0,
  name: '',
  preDirect: 0 as number | string,
  preSub: 0 as number | string,
  sub: 0,
  submitting: false,
  visible: false,
});

async function loadData() {
  loading.value = true;
  try {
    const [listRes, pendRes] = await Promise.all([
      getProfitShareGameCategoryListApi({ username: selfUsername.value }),
      getProfitShareUnexecutedGameCategoryApi({
        username: selfUsername.value,
      }),
    ]);
    if (Number(listRes.code) === 1000) {
      agentUsername.value = listRes.data?.agent_username ?? '';
      categories.value = listRes.data?.data ?? [];
    } else {
      ElMessage.warning(`${listRes.code} - ${listRes.message}`);
      categories.value = [];
    }
    pending.value =
      Number(pendRes.code) === 1000 ? (pendRes.data?.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function openSet(row: ProfitShareGameCategoryRow) {
  dialog.categoryId = row.shareholder_category_id;
  dialog.agentId = row.agent_id ?? '';
  dialog.name = row.shareholder_category_name ?? '';
  dialog.preDirect = row.direct_percentage ?? 0;
  dialog.preSub = row.team_percentage ?? 0;
  dialog.direct = Number(row.direct_percentage ?? 0);
  dialog.sub = Number(row.team_percentage ?? 0);
  dialog.visible = true;
}

async function submitSet() {
  if (!Number.isInteger(dialog.direct) || !Number.isInteger(dialog.sub)) {
    ElMessage.warning(tl('only_integer_are_allow'));
    return;
  }
  if (
    dialog.direct < 0 ||
    dialog.direct > 100 ||
    dialog.sub < 0 ||
    dialog.sub > 100
  ) {
    ElMessage.warning(tl('commission_ratio_must_be_between'));
    return;
  }
  dialog.submitting = true;
  try {
    const res = await setProfitShareGameCategoryApi({
      agent_id: dialog.agentId,
      category_id: dialog.categoryId,
      direct_share: dialog.direct,
      pre_direct_share: dialog.preDirect,
      pre_sub_share: dialog.preSub,
      sub_share: dialog.sub,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      dialog.visible = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    dialog.submitting = false;
  }
}

async function cancelPending(row: ProfitShareGameCategoryPendingRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      tl('enter_memo'),
      tl('cancel'),
      {
        confirmButtonText: tl('submit'),
        cancelButtonText: tl('cancel'),
        inputType: 'textarea',
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res = await cancelProfitShareAgentScheduleApi({
      remark: value,
      schedule_id: row.schedule_log_id ?? '',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } catch {
    // 取消
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_agent_profit_sharing_management')} > ${tl('menu_profit_share_game_category_setting')}`"
  >
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="mb-3 text-center text-base font-bold text-blue-500">
        {{ agentUsername }}
      </div>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-4">
        <ElCard
          v-for="c in categories"
          :key="c.shareholder_category_id"
          :class="{ 'opacity-60 grayscale': !isSet(c) }"
          shadow="hover"
        >
          <div class="text-center">
            <h5 class="mb-2 font-bold">{{ c.shareholder_category_name }}</h5>
            <p class="mb-3 text-sm">
              <template v-if="isSet(c)">
                <strong
                  >{{ tl('direct_share') }}：{{ c.direct_percentage }}%</strong
                >
                <br />
                <strong>{{ tl('sub_share') }}：{{ c.team_percentage }}%</strong>
              </template>
              <span v-else class="text-gray-400">{{ tl('not_open') }}</span>
            </p>
            <ElButton
              :disabled="!isSetting(c)"
              :type="isSetting(c) ? 'primary' : 'warning'"
              size="small"
              @click="openSet(c)"
            >
              {{ isSetting(c) ? tl('setting') : tl('schedule_status.0') }}
            </ElButton>
          </div>
        </ElCard>
      </div>
    </ElCard>

    <ElCard shadow="never">
      <ElTable :data="pending" border size="small">
        <ElTableColumn
          :label="tl('id')"
          prop="schedule_log_id"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('type')"
          prop="shareholder_category_name"
          min-width="120"
        />
        <ElTableColumn :label="tl('direct_share')" min-width="130">
          <template #default="{ row }">
            {{ (row as ProfitShareGameCategoryPendingRow).pre_direct_value }}
            &gt;
            {{ (row as ProfitShareGameCategoryPendingRow).direct_value }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('sub_share')" min-width="130">
          <template #default="{ row }">
            {{ (row as ProfitShareGameCategoryPendingRow).pre_team_value }} &gt;
            {{ (row as ProfitShareGameCategoryPendingRow).team_value }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_at')"
          prop="created_at"
          min-width="160"
        />
        <ElTableColumn :label="tl('operate')" min-width="100" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="danger"
              @click="cancelPending(row as ProfitShareGameCategoryPendingRow)"
            >
              {{ tl('cancel') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <ElDialog
      v-model="dialog.visible"
      :title="`${dialog.name} ${tl('set_commission_ratio')}`"
      width="420px"
    >
      <ElForm label-width="auto">
        <ElFormItem :label="`${tl('direct')} (%)`">
          <ElInputNumber
            v-model="dialog.direct"
            :max="100"
            :min="0"
            :step="1"
            class="!w-full"
          />
        </ElFormItem>
        <ElFormItem :label="`${tl('all_lower')} (%)`">
          <ElInputNumber
            v-model="dialog.sub"
            :max="100"
            :min="0"
            :step="1"
            class="!w-full"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialog.visible = false">{{ tl('cancel') }}</ElButton>
        <ElButton
          :loading="dialog.submitting"
          type="primary"
          @click="submitSet"
        >
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
