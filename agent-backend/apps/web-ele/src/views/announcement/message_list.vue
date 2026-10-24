<script lang="ts" setup>
import type { AnnouncementMessageRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElDialog,
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
  createAnnouncementMessageApi,
  deleteAnnouncementMessageApi,
  getAnnouncementMessageListApi,
  getOrganizationDropdownApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useAgentOptions } from '#/composables/use-agent-options';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { hasMenuCode } from '#/router/agent-menu';

defineOptions({ name: 'AnnouncementMessageList' });

const PER_PAGE = 20;

const canCreate = hasMenuCode('announcement_message_create');
const canDelete = hasMenuCode('announcement_message_delete');

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AnnouncementMessageRow>(
    ({ page, per_page }) =>
      getAnnouncementMessageListApi({
        end_time: dayjs().subtract(8, 'hour').format('YYYY-MM-DD HH:mm'),
        page,
        per_page,
        start_time: dayjs()
          .subtract(1, 'day')
          .subtract(8, 'hour')
          .format('YYYY-MM-DD HH:mm'),
        username: '',
      }),
    { perPage: PER_PAGE, showError: true },
  );

const orgOptions = ref<Array<{ id: number; name: string }>>([]);
const {
  loading: agentSearching,
  options: agentOptions,
  search: searchAgents,
} = useAgentOptions();

const title = computed(
  () =>
    `${tl('menu_system_management')} > ${tl('menu_announcement_message_list')}`,
);

/** 顯示時間 +8h */
function fmtTime(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

// 新增彈窗
const createVisible = ref(false);
const saving = ref(false);
const form = reactive({
  end_time: '',
  hierarchy_agent_id: '' as number | string,
  memo: '',
  message: '',
  organization_id: '' as number | string,
  start_time: '',
  type: '' as number | string,
});

const typeOptions = [
  { label: tl('announcement_messages_participants_type_99'), value: 99 },
  { label: tl('announcement_messages_participants_type_2'), value: 2 },
];

async function openCreate() {
  form.message = '';
  form.memo = '';
  form.start_time = dayjs().format('YYYY-MM-DD 00:00');
  form.end_time = dayjs().format('YYYY-MM-DD 23:59');
  form.type = '';
  form.organization_id = '';
  form.hierarchy_agent_id = '';
  createVisible.value = true;
  if (orgOptions.value.length === 0) {
    orgOptions.value =
      (await getOrganizationDropdownApi().catch(() => [])) ?? [];
  }
  if (agentOptions.value.length === 0) {
    await searchAgents('');
  }
}

async function submitCreate() {
  saving.value = true;
  try {
    const res = await createAnnouncementMessageApi({
      end_time: form.end_time,
      hierarchy_agent_id:
        Number(form.type) === 2 ? form.hierarchy_agent_id : undefined,
      memo: form.memo,
      message: form.message,
      organization_id:
        Number(form.type) === 99 ? form.organization_id : undefined,
      start_time: form.start_time,
      type: form.type,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message ?? '');
      createVisible.value = false;
      search();
    } else {
      ElMessage.error(`${res.message ?? ''}.${res.data ?? ''}`);
    }
  } finally {
    saving.value = false;
  }
}

/** 刪除 */
async function handleDelete(row: AnnouncementMessageRow) {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'), { type: 'warning' });
  } catch {
    return;
  }
  const res = await deleteAnnouncementMessageApi(row.id);
  if (Number(res.code) === 1000) {
    ElMessage.success(res.message ?? '');
    loadData();
  } else {
    ElMessage.error(`${res.message ?? ''}.${res.data ?? ''}`);
  }
}

loadData();
</script>

<template>
  <Page :title="title">
    <ElCard v-if="canCreate" class="mb-3" shadow="never">
      <ElButton type="primary" @click="openCreate">
        {{ tl('add') }}
      </ElButton>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('time')" min-width="160">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('type')" min-width="130">
          <template #default="{ row }">
            {{
              tl(
                `announcement_messages_participants_type_${row.participants_type}`,
              )
            }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('sendMessage_target')"
          prop="target_name"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('content')"
          prop="message"
          min-width="240"
          show-overflow-tooltip
        />
        <ElTableColumn :label="tl('memo')" prop="memo" min-width="120" />
        <ElTableColumn
          :label="`${tl('label_start_time')}／${tl('label_end_time')}`"
          min-width="170"
        >
          <template #default="{ row }">
            <div>{{ fmtTime(row.start_time) }}</div>
            <div>{{ fmtTime(row.end_time) }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('created_operator')"
          prop="op_username"
          min-width="120"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.op_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn v-if="canDelete" label="" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="danger"
              @click="handleDelete(row as AnnouncementMessageRow)"
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
        @current-change="handlePageChange"
      />
    </ElCard>

    <ElDialog v-model="createVisible" :title="tl('add')" width="700px">
      <ElForm label-width="120px">
        <ElFormItem :label="tl('content')">
          <ElInput v-model="form.message" :rows="3" type="textarea" />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" />
        </ElFormItem>
        <ElFormItem :label="tl('label_start_time')">
          <ElDatePicker
            v-model="form.start_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('label_end_time')">
          <ElDatePicker
            v-model="form.end_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('type')">
          <ElSelect
            v-model="form.type"
            :placeholder="tl('please_choose')"
            class="!w-full"
          >
            <ElOption
              v-for="opt in typeOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('sendMessage_target')">
          <ElSelect
            v-if="Number(form.type) === 99"
            v-model="form.organization_id"
            :placeholder="tl('please_choose')"
            class="!w-full"
            filterable
          >
            <ElOption
              v-for="o in orgOptions"
              :key="o.id"
              :label="o.name"
              :value="o.id"
            />
          </ElSelect>
          <ElSelect
            v-else-if="Number(form.type) === 2"
            v-model="form.hierarchy_agent_id"
            :debounce="500"
            :loading="agentSearching"
            :placeholder="tl('please_choose')"
            :remote-method="searchAgents"
            class="!w-full"
            filterable
            remote
          >
            <ElOption
              v-for="a in agentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
          <span v-else class="text-muted-foreground">-</span>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :loading="saving" type="primary" @click="submitCreate">
          {{ tl('btn_update') }}
        </ElButton>
        <ElButton @click="createVisible = false">Close</ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
