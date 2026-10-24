<script lang="ts" setup>
import type { ProfitShareMemberRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
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
  cancelProfitShareMemberScheduleApi,
  getProfitShareMemberListApi,
  modifyProfitShareMemberTypeApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { toHalfWidth } from '#/utils/format';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

defineOptions({ name: 'ProfitShareMemberList' });

const PER_PAGE = 30;

/** 類別下拉（0=全部、1=份桶會員、2=佔成會員） */
const TYPE_OPTIONS = [
  { label: 'all', value: 0 },
  { label: 'shareholder_member', value: 1 },
  { label: 'profit_share_member', value: 2 },
] as const;

const filters = reactive({
  type: 0 as number | string,
  username: '',
});

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<ProfitShareMemberRow>(
    ({ page, per_page }) =>
      getProfitShareMemberListApi({
        page,
        per_page,
        type: filters.type,
        username: toHalfWidth(filters.username),
      }),
    { perPage: PER_PAGE, showError: true },
  );

/** 修改帳號類別彈窗（含目前類別、新類別下拉、備註） */
const typeDialogVisible = ref(false);
const typeSaving = ref(false);
const typeCurrent = ref<ProfitShareMemberRow>({});
const typeSelected = ref<number | string>('');
const typeRemark = ref('');

/**
 * 預設帶入該帳號目前類別（對齊舊版）。
 * stored type 0=份桶、2=佔成；下拉提交值 1=份桶、2=佔成，需映射。
 */
function openTypeDialog(row: ProfitShareMemberRow) {
  typeCurrent.value = row;
  const t = Number(row.type);
  if (t === 2) {
    typeSelected.value = 2;
  } else if (t === 0) {
    typeSelected.value = 1;
  } else {
    typeSelected.value = '';
  }
  typeRemark.value = '';
  typeDialogVisible.value = true;
}

async function confirmType() {
  const curType = Number(typeCurrent.value.type);
  if (!typeSelected.value) {
    ElMessage.warning(`${tl('please_choose')} ${tl('account_type')}`);
    return;
  }
  if (curType === Number(typeSelected.value)) {
    ElMessage.warning(tl('same_account_type'));
    return;
  }
  if (!typeRemark.value.trim()) {
    ElMessage.warning(tl('enter_memo'));
    return;
  }
  typeSaving.value = true;
  try {
    const res = await modifyProfitShareMemberTypeApi({
      member_id: typeCurrent.value.member_id ?? '',
      remark: typeRemark.value.trim(),
      type: typeSelected.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      typeDialogVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    typeSaving.value = false;
  }
}

/** 撤銷進行中的類別切換排程 */
async function revokeSchedule(row: ProfitShareMemberRow) {
  try {
    const { value } = await ElMessageBox.prompt(
      `${tl('username')}: ${row.username ?? ''}${String.fromCodePoint(0x30_00)}${tl('account_type')}: ${
        row.account_type ?? ''
      } >> ${row.apply_type_text ?? ''}`,
      tl('revoke'),
      {
        confirmButtonText: tl('submit'),
        cancelButtonText: tl('cancel'),
        inputType: 'textarea',
        inputPlaceholder: tl('enter_memo'),
        inputValidator: (v) => (v ? true : tl('enter_memo')),
      },
    );
    const res = await cancelProfitShareMemberScheduleApi({
      remark: value,
      schedule_id: row.member_schedule_id ?? '',
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

onMounted(search);
</script>

<template>
  <Page
    :title="`${tl('menu_cj7_user_management')} > ${tl('menu_profit_share_member_list')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 lg:grid-cols-3">
          <ElFormItem :label="tl('member_account')">
            <ElInput v-model="filters.username" clearable />
          </ElFormItem>
          <ElFormItem :label="tl('type')">
            <ElSelect v-model="filters.type" class="!w-full">
              <ElOption
                v-for="o in TYPE_OPTIONS"
                :key="o.value"
                :label="tl(o.label)"
                :value="o.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton :loading="loading" native-type="submit" type="primary">
              {{ tl('search') }}
            </ElButton>
          </ElFormItem>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small">
        <ElTableColumn
          :label="tl('agent_account')"
          prop="agent_username"
          min-width="130"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('username')" min-width="130">
          <template #default="{ row }">
            <MemberAccountLink :username="row.username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('account_type')"
          prop="account_type"
          min-width="120"
        />
        <ElTableColumn
          :label="tl('apply_to_change_account_type')"
          prop="apply_type_text"
          min-width="160"
        />
        <ElTableColumn
          :label="tl('operate')"
          align="center"
          min-width="180"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              v-if="
                Number((row as ProfitShareMemberRow).member_schedule_id) === 0
              "
              size="small"
              type="primary"
              @click="openTypeDialog(row as ProfitShareMemberRow)"
            >
              {{ tl('modify_account_type') }}
            </ElButton>
            <template v-else>
              <ElButton disabled size="small" type="warning">
                {{ tl('processing') }}
              </ElButton>
              <ElButton
                size="small"
                type="danger"
                @click="revokeSchedule(row as ProfitShareMemberRow)"
              >
                {{ tl('revoke') }}
              </ElButton>
            </template>
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

    <ElDialog
      v-model="typeDialogVisible"
      :title="tl('account_type')"
      width="500px"
    >
      <div class="space-y-3 text-left">
        <p>
          <b>{{ tl('account_type') }}：</b>{{ typeCurrent.account_type ?? '' }}
        </p>
        <div>
          <div class="mb-1 font-bold">{{ tl('modify_account_type') }}：</div>
          <ElSelect
            v-model="typeSelected"
            class="!w-full"
            :placeholder="tl('please_choose')"
          >
            <ElOption :label="tl('shareholder_member')" :value="1" />
            <ElOption :label="tl('profit_share_member')" :value="2" />
          </ElSelect>
        </div>
        <div>
          <div class="mb-1 font-bold">{{ tl('memo') }}：</div>
          <ElInput
            v-model="typeRemark"
            :placeholder="tl('enter_memo')"
            :rows="3"
            type="textarea"
          />
        </div>
      </div>
      <template #footer>
        <ElButton @click="typeDialogVisible = false">
          {{ tl('cancel') }}
        </ElButton>
        <ElButton :loading="typeSaving" type="primary" @click="confirmType">
          {{ tl('confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
