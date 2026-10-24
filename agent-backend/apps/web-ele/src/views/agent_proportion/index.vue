<script lang="ts" setup>
import type { AgentProportionRow } from '#/api';

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

import { getAgentProportionListApi, saveAgentProportionApi } from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';

defineOptions({ name: 'AgentProportionIndex' });

const PER_PAGE = 20;

const router = useRouter();

const filters = reactive({ username: '' });

const { handlePageChange, list, loadData, loading, page, search, total } =
  useListQuery<AgentProportionRow>(
    ({ page, per_page }) =>
      getAgentProportionListApi({ page, per_page, username: filters.username }),
    { perPage: PER_PAGE },
  );

function parseProportion(s: string): null | Record<string, number | string> {
  if (!s || s === '-') return null;
  try {
    const o = JSON.parse(s);
    return o && typeof o === 'object' ? o : null;
  } catch {
    return null;
  }
}

// 占成設定 dialog
const dialogVisible = ref(false);
const dialogSaving = ref(false);
const editing = reactive({
  id: '' as number | string,
  klass: '' as number | string,
  fields: [] as Array<{ key: string; value: number | string }>,
});

function openSetting(
  row: AgentProportionRow,
  klass: number | string,
  raw: string,
) {
  const obj = parseProportion(raw);
  if (!obj) return;
  editing.id = row.id;
  editing.klass = klass;
  editing.fields = Object.entries(obj).map(([key, val]) => ({
    key,
    value: Number(val) * 100,
  }));
  dialogVisible.value = true;
}

async function handleSave() {
  dialogSaving.value = true;
  try {
    const percents: Record<string, number | string> = {};
    for (const f of editing.fields) percents[f.key] = f.value;
    const res = await saveAgentProportionApi(
      editing.id,
      editing.klass,
      percents,
    );
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      dialogVisible.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    dialogSaving.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_settlement_report')} > ${tl('menu_agent_proportion_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <ElForm label-width="auto" @submit.prevent="search">
        <div class="flex flex-wrap items-center gap-4">
          <ElFormItem :label="tl('agent_account')" class="!mb-0">
            <ElInput v-model="filters.username" clearable />
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
          :label="`${tl('upper')}${tl('agent')}${tl('username')}`"
          min-width="140"
          prop="parent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.parent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('agent')}${tl('username')}`"
          min-width="140"
          prop="agent_username"
        >
          <template #default="{ row }">
            <AgentAccount :username="row.agent_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('direct_member')" min-width="180">
          <template #default="{ row }">
            <template v-if="parseProportion(row.proportion_1)">
              <div v-for="(v, k) in parseProportion(row.proportion_1)" :key="k">
                {{ k }} {{ Number(v) * 100 }} %
              </div>
              <ElButton
                class="mt-1"
                size="small"
                @click="
                  openSetting(row as AgentProportionRow, 1, row.proportion_1)
                "
              >
                {{ tl('setting') }}
              </ElButton>
            </template>
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_second_layer')" min-width="180">
          <template #default="{ row }">
            <template v-if="parseProportion(row.proportion_2)">
              <div v-for="(v, k) in parseProportion(row.proportion_2)" :key="k">
                {{ k }} {{ Number(v) * 100 }} %
              </div>
              <ElButton
                class="mt-1"
                size="small"
                @click="
                  openSetting(row as AgentProportionRow, 2, row.proportion_2)
                "
              >
                {{ tl('setting') }}
              </ElButton>
            </template>
            <span v-else>-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('agent_third_layer')" min-width="180">
          <template #default="{ row }">
            <template v-if="parseProportion(row.proportion_3)">
              <div v-for="(v, k) in parseProportion(row.proportion_3)" :key="k">
                {{ k }} {{ Number(v) * 100 }} %
              </div>
              <ElButton
                class="mt-1"
                size="small"
                @click="
                  openSetting(row as AgentProportionRow, 3, row.proportion_3)
                "
              >
                {{ tl('setting') }}
              </ElButton>
            </template>
            <span v-else>-</span>
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

    <ElDialog v-model="dialogVisible" :title="tl('setting')" width="420">
      <ElForm label-width="120">
        <ElFormItem v-for="f in editing.fields" :key="f.key" :label="f.key">
          <ElInput v-model="f.value">
            <template #append>%</template>
          </ElInput>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('cancel') }}</ElButton>
        <ElButton :loading="dialogSaving" type="primary" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
