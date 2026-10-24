<script lang="ts" setup>
import type { MemberGroupOption } from '#/api';

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
  ElSwitch,
} from 'element-plus';

import {
  getMemberGroupDropdownApi,
  getRiskMemberRestrictionViewApi,
  saveRiskMemberRestrictionApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const TYPE_MEMBER = 1;
const TYPE_GROUP = 2;

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const saving = ref(false);
const groupOptions = ref<MemberGroupOption[]>([]);

/** 編輯模式下顯示用的目標名稱（目標建立後不可變，僅展示） */
const targetName = ref('');

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    is_disabled: 0,
    member_group_id: undefined as number | undefined,
    remark: '',
    status: 1,
    type: TYPE_MEMBER as number,
    username: '',
    withdrawal_locked: 0,
  };
}

/** 切換限制類型時清空另一側殘值，避免送出髒資料 */
function onTypeChange() {
  form.username = '';
  form.member_group_id = undefined;
}

/** 缺哪個欄位就回傳該欄位的提示，全部填齊回傳 null */
function findMissingField(): null | string {
  if (form.type === TYPE_MEMBER && !form.username.trim()) {
    return `${tl('please_enter')} ${tl('risk_member_restriction_username')}`;
  }
  if (form.type === TYPE_GROUP && !form.member_group_id) {
    return `${tl('please_choose')} ${tl('risk_member_restriction_type_2')}`;
  }

  return null;
}

async function handleSave() {
  if (!isEdit.value) {
    const missing = findMissingField();
    if (missing) {
      ElMessage.error(missing);
      return;
    }
  }

  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      is_disabled: form.is_disabled,
      remark: form.remark,
      status: form.status,
      withdrawal_locked: form.withdrawal_locked,
    };

    if (isEdit.value) {
      payload.id = editId.value;
    } else {
      payload.type = form.type;
      if (form.type === TYPE_MEMBER) {
        payload.username = form.username.trim();
      } else {
        payload.member_group_id = form.member_group_id;
      }
    }

    const res = await saveRiskMemberRestrictionApi(payload, isEdit.value);
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

async function loadGroups() {
  if (groupOptions.value.length > 0) return;
  const res = await getMemberGroupDropdownApi().catch(() => null);
  groupOptions.value = res && Number(res.code) === 200 ? (res.data ?? []) : [];
}

async function open(id: number | string = '') {
  editId.value = id || '';
  Object.assign(form, makeDefaults());
  targetName.value = '';
  visible.value = true;

  if (isEdit.value) {
    const res = await getRiskMemberRestrictionViewApi(editId.value).catch(
      () => null,
    );
    const d = res && Number(res.code) === 200 ? res.data : null;
    if (d) {
      form.type = Number(d.type ?? TYPE_MEMBER);
      form.remark = d.remark ?? '';
      form.status = Number(d.status ?? 0);
      form.is_disabled = Number(d.is_disabled ?? 0);
      form.withdrawal_locked = Number(d.withdrawal_locked ?? 0);
      targetName.value = d.target_name ?? '';
    } else {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      visible.value = false;
    }
  } else {
    loadGroups();
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${isEdit ? tl('edit') : tl('add')}${tl('menu_risk_member_restriction')}`"
    top="6vh"
    width="640"
  >
    <!-- 版型照示意圖：標籤在上，左欄＝限制類型＋備註、右欄＝用戶名＋(狀態/是否停用)＋提款鎖定 -->
    <ElForm label-position="top">
      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <div>
          <ElFormItem :label="tl('risk_member_restriction_type')" required>
            <ElSelect
              v-model="form.type"
              :disabled="isEdit"
              class="!w-full"
              @change="onTypeChange"
            >
              <ElOption
                :label="tl('risk_member_restriction_type_1')"
                :value="TYPE_MEMBER"
              />
              <ElOption
                :label="tl('risk_member_restriction_type_2')"
                :value="TYPE_GROUP"
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
        </div>

        <div>
          <!-- 目標：編輯時不可變，僅展示；新增時依類型切換 輸入框/群組下拉；標題跟著類型走（用戶名/會員群組） -->
          <ElFormItem
            :label="
              form.type === TYPE_MEMBER
                ? tl('risk_member_restriction_username')
                : tl('risk_member_restriction_type_2')
            "
            required
          >
            <ElInput v-if="isEdit" :model-value="targetName" disabled />
            <ElInput
              v-else-if="form.type === TYPE_MEMBER"
              v-model="form.username"
              :placeholder="tl('please_enter')"
            />
            <ElSelect
              v-else
              v-model="form.member_group_id"
              :placeholder="tl('please_choose')"
              class="!w-full"
              filterable
            >
              <ElOption
                v-for="g in groupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id"
              />
            </ElSelect>
          </ElFormItem>
          <div class="grid grid-cols-2 gap-x-4">
            <ElFormItem :label="tl('status')">
              <ElSwitch
                v-model="form.status"
                :active-text="tl('enable')"
                :active-value="1"
                :inactive-text="tl('close')"
                :inactive-value="0"
              />
            </ElFormItem>
            <ElFormItem :label="tl('risk_member_restriction_is_disabled')">
              <ElSwitch
                v-model="form.is_disabled"
                :active-text="tl('option_yes')"
                :active-value="1"
                :inactive-text="tl('option_no')"
                :inactive-value="0"
              />
            </ElFormItem>
          </div>
          <ElFormItem :label="tl('risk_member_restriction_withdrawal_lock')">
            <ElSwitch
              v-model="form.withdrawal_locked"
              :active-text="tl('option_yes')"
              :active-value="1"
              :inactive-text="tl('option_no')"
              :inactive-value="0"
            />
          </ElFormItem>
        </div>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('save') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
