<script lang="ts" setup>
import type { InformationRow } from '#/api';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElImage,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getInformationListApi, updateInformationSortApi } from '#/api';
import { tl } from '#/locales';

import InformationEditDialog from './components/information-edit-dialog.vue';

defineOptions({ name: 'InformationIndex' });

const loading = ref(false);
const list = ref<InformationRow[]>([]);

/** 排序編輯模式 */
const editMode = ref(false);
const sortDraft = ref<Record<number, string>>({});

async function loadData() {
  loading.value = true;
  try {
    list.value = (await getInformationListApi()) ?? [];
    // 依排序顯示
    list.value.sort((a, b) => Number(a.sort ?? 0) - Number(b.sort ?? 0));
  } finally {
    loading.value = false;
  }
}

function enterEdit() {
  sortDraft.value = Object.fromEntries(
    list.value.map((r) => [r.id, String(r.sort ?? '')]),
  );
  editMode.value = true;
}

function cancelEdit() {
  editMode.value = false;
}

async function saveSort() {
  const res = await updateInformationSortApi(sortDraft.value);
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    editMode.value = false;
    loadData();
  } else {
    ElMessage.error(res.message);
  }
}

/** type 4：content 為各語系外部連結 JSON */
function externalUrls(row: InformationRow) {
  let map: Record<string, string> = {};
  try {
    map = JSON.parse(row.content ?? '{}') ?? {};
  } catch {
    map = {};
  }
  return (row.detail ?? []).map((d) => ({
    name: d.name,
    url: map[String(d.language)] ?? row.content,
  }));
}

const editRef = ref<InstanceType<typeof InformationEditDialog>>();
/** 開新增/編輯彈窗 */
function goEdit(id?: number | string) {
  editRef.value?.open(id ?? '');
}

onMounted(loadData);
</script>

<template>
  <Page :title="`${tl('agnet_management')} > ${tl('menu_information')}`">
    <div class="mb-3 flex gap-2">
      <ElButton type="success" @click="goEdit()">{{ tl('add') }}</ElButton>
      <template v-if="editMode">
        <ElButton type="success" @click="saveSort">
          {{ tl('btn_update') }}
        </ElButton>
        <ElButton @click="cancelEdit">{{ tl('cancel') }}</ElButton>
      </template>
      <ElButton v-else type="info" @click="enterEdit">
        {{ tl('edit') }}
      </ElButton>
    </div>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="list" border size="small" stripe>
        <ElTableColumn :label="tl('sorts')" width="110">
          <template #default="{ row }">
            <ElInput
              v-if="editMode"
              v-model="sortDraft[row.id]"
              class="!w-20"
              size="small"
            />
            <span v-else>{{ row.sort }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('hyperlink')" width="130">
          <template #default="{ row }">
            {{ tl(`advertisement_type_${row.link_type}`) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('content')" min-width="220">
          <template #default="{ row }">
            <template v-if="Number(row.type) === 4">
              <div
                v-for="(u, i) in externalUrls(row as InformationRow)"
                :key="i"
              >
                <strong>{{ u.name }}:</strong>
                <a
                  :href="u.url"
                  class="text-blue-500 hover:underline"
                  target="_blank"
                >
                  {{ u.url }}
                </a>
              </div>
            </template>
            <span v-else>{{ row.content }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('picture')" min-width="200">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElImage
                v-for="(d, i) in row.detail ?? []"
                :key="i"
                :alt="d.name"
                :preview-src-list="[d.image]"
                :src="d.image"
                :title="d.name"
                class="max-h-16 max-w-28 rounded"
                fit="contain"
                preview-teleported
              />
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="90">
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-500'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElButton size="small" @click="goEdit(row.id)">
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <InformationEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
