<script lang="ts" setup>
import type { VendorImageRow } from '#/api';

import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVendorImageSettingsApi, updateVendorImageSettingsApi } from '#/api';
import { useLeavePage } from '#/composables/use-leave-page';
import { tl } from '#/locales';

defineOptions({ name: 'AgentVendorImageSetting' });

const route = useRoute();
const leaveTo = useLeavePage();
const groupId = (route.query.group_id as string) || '';

const loading = ref(false);
const saving = ref(false);
const groupName = ref('');
const rows = ref<VendorImageRow[]>([]);

async function loadData() {
  if (!groupId) return;
  loading.value = true;
  try {
    const res = await getVendorImageSettingsApi(groupId);
    if (Number(res.code) === 1000 && res.data) {
      groupName.value = res.data.groups?.name ?? '';
      rows.value = (res.data.data ?? []).map((r) => ({ ...r }));
    } else {
      ElMessage.warning(res.message);
      rows.value = [];
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await updateVendorImageSettingsApi({
      group_id: groupId,
      vendor_image_datas: rows.value.map((r) => ({
        app_icon: r.app_icon ?? '',
        image: r.image ?? '',
        show_name: r.show_name ?? '',
        vendor_id: r.id,
      })),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(
        String((res as any).data ?? res.message) || tl('failure'),
      );
    }
  } finally {
    saving.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_advance_setting')}`"
  >
    <ElCard class="mb-3" shadow="never">
      <div class="flex items-center justify-between">
        <div>
          <b>{{ tl('name') }}</b
          >：{{ groupName }}
          <span class="text-muted-foreground">({{ groupId }})</span>
        </div>
        <ElButton link type="primary" @click="leaveTo('/agent/group_list')">
          {{ tl('btn_back') }}
        </ElButton>
      </div>
    </ElCard>

    <ElCard v-loading="loading" shadow="never">
      <div class="mb-3 flex justify-end">
        <ElButton :loading="saving" type="success" @click="handleSave">
          {{ tl('btn_update') }}
        </ElButton>
      </div>
      <ElTable :data="rows" border size="small">
        <ElTableColumn :label="tl('id')" prop="id" width="90" />
        <ElTableColumn :label="tl('vendor_name')" prop="name" min-width="140" />
        <ElTableColumn :label="tl('name')" min-width="180">
          <template #default="{ row }">
            <ElInput v-model="(row as VendorImageRow).show_name" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="`${tl('show')}${tl('picture')}`" min-width="240">
          <template #default="{ row }">
            <ElInput v-model="(row as VendorImageRow).image" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('game_provider_icon')" min-width="240">
          <template #default="{ row }">
            <ElInput v-model="(row as VendorImageRow).app_icon" />
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>
  </Page>
</template>
