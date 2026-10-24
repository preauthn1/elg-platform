<script lang="ts" setup>
import type { VipListRow } from '#/api';

import { computed, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElImage,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getVipListApi, updateVipLevelApi } from '#/api';
import { tl } from '#/locales';

import OtherSupportDialog from './components/other-support-dialog.vue';
import VipEditDialog from './components/vip-edit-dialog.vue';
import VipEditRebateDialog from './components/vip-edit-rebate-dialog.vue';
import VipRewardListDialog from './components/vip-reward-list-dialog.vue';

defineOptions({ name: 'VipIndex' });

const editDialogRef = ref<InstanceType<typeof VipEditDialog>>();
const rewardListRef = ref<InstanceType<typeof VipRewardListDialog>>();
const rebateRef = ref<InstanceType<typeof VipEditRebateDialog>>();
const otherSupportRef = ref<InstanceType<typeof OtherSupportDialog>>();

const loading = ref(false);
const saving = ref(false);
const editing = ref(false);
const list = ref<VipListRow[]>([]);

const keyword = ref('');
const filteredList = computed(() => {
  const k = keyword.value.trim().toLowerCase();
  if (!k) return list.value;
  return list.value.filter((r) =>
    String(r.name ?? '')
      .toLowerCase()
      .includes(k),
  );
});

async function loadData() {
  loading.value = true;
  try {
    const res = await getVipListApi({ page: 1, per_page: 100 });
    list.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

function startEdit() {
  editing.value = true;
}

function cancelEdit() {
  editing.value = false;
  loadData();
}

async function save() {
  saving.value = true;
  try {
    const levelData: Record<string, number | string> = {};
    for (const row of list.value) {
      levelData[String(row.id)] = row.level ?? '';
    }
    const res = await updateVipLevelApi(levelData);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editing.value = false;
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

function isDowngradeText(v?: number) {
  return Number(v) === 1 ? tl('option_yes') : tl('option_no');
}

loadData();
</script>

<template>
  <Page :title="`${tl('menu_vip')} > ${tl('menu_vip_setting')}`">
    <ElCard shadow="never">
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-medium">{{ tl('menu_vip_setting') }}</span>
          <div class="flex items-center gap-2">
            <ElInput
              v-model="keyword"
              :placeholder="tl('search')"
              class="!w-48"
              clearable
            />
            <ElButton type="success" @click="editDialogRef?.open()">
              {{ tl('add') }}
            </ElButton>
            <ElButton v-if="!editing" type="info" @click="startEdit">
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton @click="cancelEdit">{{ tl('cancel') }}</ElButton>
              <ElButton :loading="saving" type="success" @click="save">
                {{ tl('btn_update') }}
              </ElButton>
            </template>
          </div>
        </div>
      </template>

      <ElTable
        v-loading="loading"
        :data="filteredList"
        border
        size="small"
        stripe
      >
        <ElTableColumn
          :label="tl('sorts')"
          align="center"
          prop="level"
          sortable
          width="100"
        >
          <template #default="{ row }">
            <ElInput
              v-if="editing"
              v-model="row.level"
              style="max-width: 70px"
            />
            <span v-else>{{ row.level }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('picture')" align="center" width="80">
          <template #default="{ row }">
            <ElImage
              v-if="row.image"
              :src="row.image"
              style="max-width: 30px; max-height: 30px"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('name')"
          align="center"
          prop="name"
          min-width="120"
          sortable
        />
        <ElTableColumn
          :label="tl('need_deposit_amount')"
          align="center"
          prop="target_amount"
          min-width="120"
          sortable
        />
        <ElTableColumn
          :label="tl('keep_amount')"
          align="center"
          prop="keep_amount"
          min-width="120"
          sortable
        />
        <ElTableColumn
          :label="tl('withdrawal_times_limit')"
          align="center"
          prop="withdrawal_times_limit"
          min-width="120"
          sortable
        />
        <ElTableColumn
          :label="tl('daily_withdrawal_amount_limit')"
          align="center"
          prop="withdrawal_amount_limit"
          min-width="140"
          sortable
        />
        <ElTableColumn :label="tl('is_downgrade')" align="center" width="100">
          <template #default="{ row }">
            {{ isDowngradeText(row.is_downgrade) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('other_support')" align="center" width="100">
          <template #default="{ row }">
            <ElButton
              size="small"
              type="danger"
              @click="otherSupportRef?.open(row.id)"
            >
              {{ tl('view') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn align="center" width="90" fixed="right">
          <template #default="{ row }">
            <ElDropdown>
              <ElButton size="small">{{ tl('features') }}</ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem @click="editDialogRef?.open(row.id)">
                    {{ tl('edit') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="rewardListRef?.open(row.id, row.name ?? '')"
                  >
                    {{ tl('reward') }}
                  </ElDropdownItem>
                  <ElDropdownItem
                    @click="rebateRef?.open(row.id, row.name ?? '')"
                  >
                    {{ tl('edit') }}{{ tl('rebate') }}
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElCard>

    <OtherSupportDialog ref="otherSupportRef" />
    <VipEditDialog ref="editDialogRef" @saved="loadData" />
    <VipRewardListDialog ref="rewardListRef" />
    <VipEditRebateDialog ref="rebateRef" />
  </Page>
</template>
