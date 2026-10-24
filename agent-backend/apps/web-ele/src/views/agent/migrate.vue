<script lang="ts" setup>
import type { MigratePreview } from '#/api';

import { onMounted, ref, watch } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElForm,
  ElFormItem,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
} from 'element-plus';

import { checkHierarchyApi, migrateAgentApi, migratePreviewApi } from '#/api';
import { useAgentOptions } from '#/composables/use-agent-options';
import { tl } from '#/locales';

defineOptions({ name: 'AgentMigrate' });

const {
  loading: targetSearching,
  options: targetOptions,
  search: searchTarget,
} = useAgentOptions();
const {
  loading: parentSearching,
  options: parentOptions,
  search: searchParent,
} = useAgentOptions();

const targetAgentId = ref<'' | number>('');
const newParentAgentId = ref<'' | number>('');

const previewLoading = ref(false);
const preview = ref<MigratePreview | null>(null);
const previewError = ref<null | { data?: string; message: string }>(null);

// 變更選擇即清空預覽
watch([targetAgentId, newParentAgentId], () => {
  preview.value = null;
  previewError.value = null;
});

async function getPreview() {
  previewLoading.value = true;
  preview.value = null;
  previewError.value = null;
  try {
    const res = await migratePreviewApi({
      new_parent_agent_id: newParentAgentId.value,
      target_agent_id: targetAgentId.value,
    });
    if (Number(res.code) === 1000) {
      preview.value = res.data as MigratePreview;
    } else {
      previewError.value = {
        data: typeof res.data === 'string' ? res.data : '',
        message: res.message,
      };
    }
  } finally {
    previewLoading.value = false;
  }
}

/** 檢查全體代理樹狀異常 */
async function checkHierarchy() {
  const res = await checkHierarchyApi();
  if (Number(res.code) === 1000) {
    const ids = res.data?.unexpected_agentIDs;
    if (ids && ids.length > 0) {
      ElMessageBox.alert(
        `${tl('detect_agent_hiearchy_error')}，${res.data?.unexpected_agentIDsText ?? ''}`,
      );
    } else {
      ElMessageBox.alert(`${res.message}, ${tl('no_abnormality')}`);
    }
  } else {
    ElMessageBox.alert(`${res.message}.${res.data ?? ''}`);
  }
}

/** 執行移轉 */
async function executeMigrate() {
  try {
    await ElMessageBox.confirm(tl('are_you_sure'));
  } catch {
    return;
  }
  const res = await migrateAgentApi({
    new_parent_agent_id: newParentAgentId.value,
    target_agent_id: targetAgentId.value,
  });
  if (Number(res.code) === 1000) {
    const ids = res.data?.unexpected_agentIDs;
    if (ids && ids.length > 0) {
      ElMessageBox.alert(
        `${tl('detect_agent_hiearchy_error')}，${res.data?.unexpected_agentIDsText ?? ''}`,
      );
    } else {
      ElMessage.success(res.message);
    }
    preview.value = null;
  } else {
    ElMessageBox.alert(`${res.message}.${res.data ?? ''}`);
  }
}

onMounted(() => {
  searchTarget('');
  searchParent('');
});
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('menu_migrate_agent')}`">
    <ElCard shadow="never">
      <ElForm label-position="top" inline>
        <ElFormItem :label="tl('transfer_to_which_agent')">
          <ElSelect
            v-model="targetAgentId"
            :debounce="500"
            :loading="targetSearching"
            :remote-method="searchTarget"
            class="!w-72"
            filterable
            remote
          >
            <ElOption :label="tl('please_choose')" value="" />
            <ElOption
              v-for="a in targetOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('which_agent_to_transfer')">
          <ElSelect
            v-model="newParentAgentId"
            :debounce="500"
            :loading="parentSearching"
            :remote-method="searchParent"
            class="!w-72"
            filterable
            remote
          >
            <ElOption :label="tl('please_choose')" value="" />
            <ElOption
              v-for="a in parentOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
      </ElForm>
      <div class="flex items-center gap-4">
        <ElButton :loading="previewLoading" type="info" @click="getPreview">
          {{ tl('show') }}{{ tl('result_preview') }}
        </ElButton>
        <ElButton link @click="checkHierarchy">
          {{ tl('check_whole_agent_hiearchy_error') }}
        </ElButton>
      </div>

      <template v-if="previewError">
        <div class="mt-6 text-red-500">{{ previewError.message }}</div>
        <div>{{ previewError.data }}</div>
      </template>

      <template v-if="preview">
        <h2 class="mt-8 border-l-4 border-teal-200 pl-2 text-lg font-bold">
          {{ tl('result_preview') }}
        </h2>
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
          <ElCard shadow="never">
            <div class="text-center">
              <div class="text-xl font-bold">
                {{ preview.target_agent?.username }}
              </div>
              <div class="text-muted-foreground text-xs">
                {{ preview.target_agent?.agent_id }}
              </div>
              <div class="mt-2">{{ preview.target_agent?.hierarchy }}</div>
              <div class="text-red-400">
                {{ preview.target_agent?.hierarchy_path }}
              </div>
            </div>
          </ElCard>
          <ElCard shadow="never">
            <div class="text-center">
              <div class="text-muted-foreground text-xs font-bold">
                {{ tl('new_upper_agent') }} ({{ tl('new') }}!)
              </div>
              <div class="text-xl font-bold">
                {{ preview.new_parent_agent?.username }}
              </div>
              <div class="text-muted-foreground text-xs">
                {{ preview.new_parent_agent?.agent_id }}
              </div>
              <div class="mt-2">{{ preview.new_parent_agent?.hierarchy }}</div>
              <div class="text-red-400">
                {{ preview.new_parent_agent?.hierarchy_path }}
              </div>
            </div>
          </ElCard>
          <ElCard class="opacity-50" shadow="never">
            <div class="text-center">
              <div class="text-muted-foreground text-xs font-bold">
                {{ tl('original_upper_agent') }}
              </div>
              <div class="text-xl font-bold">
                {{ preview.original_agent?.username }}
              </div>
              <div class="text-muted-foreground text-xs">
                {{ preview.original_agent?.agent_id }}
              </div>
              <div class="mt-2">{{ preview.original_agent?.hierarchy }}</div>
              <div>{{ preview.original_agent?.hierarchy_path }}</div>
            </div>
          </ElCard>
        </div>

        <template v-if="(preview.hierachyChilds ?? []).length > 0">
          <h2 class="mt-8 border-l-4 border-teal-200 pl-2 text-lg font-bold">
            {{ tl('afffect_lower_agent') }}
          </h2>
          <div class="mt-2 space-y-1 text-sm">
            <div v-for="(c, i) in preview.hierachyChilds" :key="i">
              <span class="inline-block min-w-24">{{ c.agent_id }}</span>
              <span class="inline-block min-w-32">{{ c.username }}</span>
              <span class="inline-block min-w-52">{{ c.hierarchy }}</span>
              <span class="inline-block min-w-52">{{ c.hierarchy_path }}</span>
              <span class="inline-block min-w-32">
                {{ tl('expected_to_convert') }} =>
              </span>
              <span class="text-green-700">{{ c.expect_new_path }}</span>
            </div>
          </div>
        </template>

        <h2 class="mt-8 border-l-4 border-teal-200 pl-2 text-lg font-bold">
          {{ tl('sql_preview') }}
        </h2>
        <div class="bg-muted mt-2 rounded p-4 text-sm">
          <div>{{ preview.sqlRaw_1 }}</div>
          <div>{{ preview.sqlRaw_2 }}</div>
          <div>{{ preview.sqlRaw_3 }}</div>
        </div>

        <div class="mt-6 border-t pt-4">
          <ElButton type="danger" @click="executeMigrate">
            {{ tl('start_to_transfer') }}
          </ElButton>
        </div>
      </template>
    </ElCard>
  </Page>
</template>
