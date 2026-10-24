<script lang="ts" setup>
import type { CsTag } from '#/api/cs-tag';

import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElEmpty,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { runCsBootstrap } from '#/api/cs';
import { getCsTagListApi } from '#/api/cs-tag';
import { tl } from '#/locales';

import TagEditDialog from './components/tag-edit-dialog.vue';

defineOptions({ name: 'CsTagList' });

const loading = ref(false);
const bootstrapError = ref(false);
const list = ref<CsTag[]>([]);
const editRef = ref<InstanceType<typeof TagEditDialog>>();

/** 不吞錯誤的原始版本，bootstrap() 才需要知道有沒有真的失敗（供重試判斷） */
async function fetchList() {
  list.value = await getCsTagListApi();
}
async function loadData() {
  loading.value = true;
  try {
    await fetchList();
  } catch {
    list.value = [];
  } finally {
    loading.value = false;
  }
}

async function bootstrap() {
  loading.value = true;
  bootstrapError.value = false;
  try {
    await runCsBootstrap(fetchList);
  } catch {
    bootstrapError.value = true;
  } finally {
    loading.value = false;
  }
}
onMounted(bootstrap);
</script>

<template>
  <Page :title="tl('menu.cs_tag')">
    <ElCard shadow="never">
      <div class="mb-3 flex items-center">
        <div class="flex-1"></div>
        <ElButton type="primary" @click="editRef?.open()">
          {{ tl('add') }}
        </ElButton>
      </div>

      <div v-if="bootstrapError" class="flex flex-col items-center gap-3 py-16">
        <span class="text-muted-foreground">{{ tl('cs.load_failed') }}</span>
        <ElButton type="primary" @click="bootstrap">
          {{ tl('retry') }}
        </ElButton>
      </div>
      <ElTable
        v-else
        v-loading="loading"
        :data="list"
        border
        max-height="560"
        size="small"
      >
        <ElTableColumn :label="tl('list.label_name')" min-width="160">
          <template #default="{ row }">
            <span
              :style="{
                backgroundColor: row.background_color,
                color: row.text_color,
              }"
              class="rounded px-1.5 py-0.5"
            >
              {{ row.name }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" width="120">
          <template #default="{ row }">
            <b
              :class="
                Number(row.status) === 1 ? 'text-green-600' : 'text-red-500'
              "
            >
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </b>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('list.update_user')"
          min-width="140"
          prop="update_user"
        />
        <ElTableColumn
          :label="tl('list.action')"
          align="center"
          width="120"
          fixed="right"
        >
          <template #default="{ row }">
            <ElButton
              size="small"
              type="primary"
              @click="editRef?.open(row as CsTag)"
            >
              {{ tl('edit') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('no_data')" />
        </template>
      </ElTable>
    </ElCard>

    <TagEditDialog ref="editRef" @saved="loadData" />
  </Page>
</template>
