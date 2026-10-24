<script lang="ts" setup>
import { onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';

import { ElCard, ElTabPane, ElTabs } from 'element-plus';

import {
  getGameTypeListApi,
  getVendorCategoryApi,
  getVendorListApi,
} from '#/api';
import { tl } from '#/locales';

import VendorCategoryPanel from './components/vendor-category-panel.vue';

defineOptions({ name: 'VendorCategory' });

/** 類別固定五項，對應 type_id 1~5 */
const CATEGORIES: Array<{ name: string; typeId: number }> = [
  { name: 'Slot', typeId: 1 },
  { name: 'Score', typeId: 2 },
  { name: 'Live', typeId: 3 },
  { name: 'Sport', typeId: 4 },
  { name: 'Other', typeId: 5 },
];

const GROUP_ID = 41;

const loading = ref(false);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const categoryData = ref<Record<string, number[][]>>({});
const activeTab = ref('Slot');

async function loadData() {
  loading.value = true;
  try {
    vendors.value = (await getVendorListApi()) ?? [];
    gameTypes.value = (await getGameTypeListApi()) ?? [];
    const res = await getVendorCategoryApi(GROUP_ID);
    categoryData.value = Number(res.code) === 1000 ? (res.data ?? {}) : {};
  } finally {
    loading.value = false;
  }
}

onMounted(loadData);
</script>

<template>
  <Page
    :title="`${tl('menu_settlement_report')} > ${tl('menu_vendor_category')}`"
  >
    <ElCard v-loading="loading" shadow="never">
      <ElTabs v-model="activeTab" type="border-card">
        <ElTabPane
          v-for="cat in CATEGORIES"
          :key="cat.name"
          :label="cat.name"
          :name="cat.name"
        >
          <VendorCategoryPanel
            :category-name="cat.name"
            :game-types="gameTypes"
            :rows="categoryData[cat.name] ?? []"
            :type-id="cat.typeId"
            :vendors="vendors"
            @saved="loadData"
          />
        </ElTabPane>
      </ElTabs>
    </ElCard>
  </Page>
</template>
