<script lang="ts" setup>
import type { EmailGatewayGroupOption } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getEmailGatewayDetailApi,
  getEmailGatewayGroupDropdownApi,
  saveEmailGatewayApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const saving = ref(false);
const groupOptions = ref<EmailGatewayGroupOption[]>([]);

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_group_id: undefined as number | undefined,
    email: '',
    from_name: '',
    host: '',
    // 留空表示不變更密碼
    password: '',
    port: 587,
    scheme: 'smtp',
    sort: 0,
    status: 1,
  };
}

/** 切換加密方式時同步預設埠號 */
function onSchemeChange(scheme: number | string) {
  form.port = scheme === 'smtps' ? 465 : 587;
}

/** 缺哪個欄位就回傳該欄位的提示，全部填齊回傳 null */
function findMissingField(): null | string {
  if (!isEdit.value && !form.agent_group_id) {
    return `${tl('please_choose')} ${tl('agent_group_type_7')}`;
  }
  if (!form.email.trim()) {
    return `${tl('please_enter')} ${tl('email')}`;
  }
  if (!isEdit.value && !form.password) {
    return `${tl('please_enter')} ${tl('password')}`;
  }
  if (!form.host.trim()) {
    return `${tl('please_enter')} ${tl('email_gateway_host')}`;
  }

  return null;
}

async function handleSave() {
  const missing = findMissingField();
  if (missing) {
    ElMessage.error(missing);
    return;
  }

  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      email: form.email,
      from_name: form.from_name,
      host: form.host,
      port: form.port,
      scheme: form.scheme,
      sort: form.sort,
      status: form.status,
    };

    if (isEdit.value) {
      payload.id = editId.value;
      if (form.password) payload.password = [REDACTED];
    } else {
      payload.agent_group_id = form.agent_group_id;
      payload.password = [REDACTED];
    }

    const res = await saveEmailGatewayApi(payload, isEdit.value);
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
    const res = await getEmailGatewayDetailApi(editId.value).catch(() => null);
    const d = res && Number(res.code) === 200 ? res.data : null;
    if (d) {
      form.agent_group_id = d.agent_group_id;
      form.email = d.email ?? '';
      form.host = d.host ?? '';
      form.port = Number(d.port ?? 587);
      form.scheme = d.scheme ?? 'smtp';
      form.from_name = d.from_name ?? '';
      form.sort = Number(d.sort ?? 0);
      form.status = Number(d.status ?? 0);
    } else {
      ElMessage.error(res?.message ?? tl('operation_failed'));
      visible.value = false;
    }
  } else if (groupOptions.value.length === 0) {
    const res = await getEmailGatewayGroupDropdownApi().catch(() => null);
    groupOptions.value =
      res && Number(res.code) === 200 ? (res.data ?? []) : [];
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_email_gateway_list')} - ${isEdit ? tl('edit') : tl('add')}`"
    top="6vh"
    width="720"
  >
    <ElForm label-width="140">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem v-if="!isEdit" :label="tl('agent_group_type_7')" required>
          <ElSelect
            v-model="form.agent_group_id"
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
        <ElFormItem :label="tl('email')" required>
          <ElInput v-model="form.email" />
        </ElFormItem>
        <ElFormItem :label="tl('password')" :required="!isEdit">
          <ElInput
            v-model="form.password"
            :placeholder="isEdit ? tl('email_gateway_password_hint') : ''"
            show-password
            type="password"
          />
        </ElFormItem>
        <ElFormItem :label="tl('email_gateway_from_name')">
          <ElInput v-model="form.from_name" />
        </ElFormItem>
        <ElFormItem :label="tl('email_gateway_host')" required>
          <ElInput v-model="form.host" />
        </ElFormItem>
        <ElFormItem :label="tl('email_gateway_scheme')">
          <ElSelect
            v-model="form.scheme"
            class="!w-full"
            @change="onSchemeChange"
          >
            <ElOption :label="tl('email_gateway_scheme_smtp')" value="smtp" />
            <ElOption :label="tl('email_gateway_scheme_smtps')" value="smtps" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('email_gateway_port')">
          <ElInputNumber
            v-model="form.port"
            :max="65535"
            :min="1"
            controls-position="right"
          />
        </ElFormItem>
        <ElFormItem :label="tl('field_sort')">
          <ElInputNumber v-model="form.sort" controls-position="right" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
