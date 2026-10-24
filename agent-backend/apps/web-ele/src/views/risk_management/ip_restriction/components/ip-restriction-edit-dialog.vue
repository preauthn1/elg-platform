<script lang="ts" setup>
import type { RiskIpRestrictionBlockItem } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getRiskIpRestrictionViewApi,
  RISK_IP_RESTRICTION_BLOCK_ITEMS,
  saveRiskIpRestrictionApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const saving = ref(false);

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    block_items: [] as RiskIpRestrictionBlockItem[],
    ip: '',
    remark: '',
    status: 1,
  };
}

/** 阻擋項目下拉選項；label 走語系（computed 讓切語系即時反映），順序與列表一致 */
const blockItemOptions = computed(() =>
  RISK_IP_RESTRICTION_BLOCK_ITEMS.map((value) => ({
    label: tl(`ip_restriction_block_${value}`),
    value,
  })),
);

async function handleSave() {
  if (!form.ip.trim()) {
    ElMessage.error(`${tl('please_enter')} ${tl('ip_restriction_ip')}`);
    return;
  }
  // 至少擋一項規則才有意義（後端同樣要求 min:1，此處先擋避免多跑一趟）
  if (form.block_items.length === 0) {
    ElMessage.error(
      `${tl('please_choose')} ${tl('ip_restriction_block_items')}`,
    );
    return;
  }

  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      block_items: form.block_items,
      ip: form.ip.trim(),
      remark: form.remark,
      status: form.status,
    };
    if (isEdit.value) {
      payload.id = editId.value;
    }

    const res = await saveRiskIpRestrictionApi(payload, isEdit.value);
    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function open(id: number | string = '') {
  editId.value = id || '';
  Object.assign(form, makeDefaults());
  visible.value = true;

  if (isEdit.value) {
    const res = await getRiskIpRestrictionViewApi(editId.value).catch(
      () => null,
    );
    const d = res && Number(res.code) === 200 ? res.data : null;
    if (d) {
      form.ip = d.ip ?? '';
      form.block_items = d.block_items ?? [];
      form.remark = d.remark ?? '';
      form.status = Number(d.status ?? 0);
    } else {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      visible.value = false;
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('menu_risk_ip_restriction')}`"
    top="6vh"
    width="640"
  >
    <!-- 版型照示意圖：標籤在上，第一列＝IP 位址＋狀態，第二列＝阻擋項目（多選），第三列＝備註（IP 可編輯修正，無 delete） -->
    <ElForm label-position="top">
      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="tl('ip_restriction_ip')" required>
          <ElInput v-model="form.ip" :placeholder="tl('please_enter')" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElSelect v-model="form.status" class="!w-full">
            <ElOption :label="tl('enable')" :value="1" />
            <ElOption :label="tl('close')" :value="0" />
          </ElSelect>
        </ElFormItem>
      </div>
      <ElFormItem :label="tl('ip_restriction_block_items')" required>
        <ElSelect
          v-model="form.block_items"
          :placeholder="tl('please_choose')"
          class="!w-full"
          collapse-tags-tooltip
          multiple
        >
          <ElOption
            v-for="item in blockItemOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('memo')">
        <ElInput
          v-model="form.remark"
          :placeholder="tl('please_enter')"
          :rows="6"
          maxlength="191"
          show-word-limit
          type="textarea"
        />
      </ElFormItem>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
