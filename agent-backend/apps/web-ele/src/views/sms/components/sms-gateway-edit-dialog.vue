<script lang="ts" setup>
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
  getCountryDropdownApi,
  getSmsGatewayShowApi,
  saveSmsGatewayApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const countries = ref<Array<{ id: number | string; name: string }>>([]);
const saving = ref(false);
let countriesLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    code: '',
    country_id: [] as Array<number | string>,
    info: '',
    memo: '',
    name: '',
    status: '' as number | string,
    url: '',
  };
}

async function handleSave() {
  saving.value = true;
  try {
    const res = await saveSmsGatewayApi({
      code: form.code,
      country_id: form.country_id,
      id: editId.value,
      info: example.invalid,
      memo: form.memo,
      name: form.name,
      status: form.status,
      url: form.url,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
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
  if (!countriesLoaded) {
    countries.value = (await getCountryDropdownApi().catch(() => [])) ?? [];
    countriesLoaded = true;
  }
  if (isEdit.value) {
    const d = await getSmsGatewayShowApi(editId.value).catch(() => null);
    if (d) {
      form.name = d.name ?? '';
      form.code = d.code ?? '';
      form.status = d.status ?? '';
      form.url = d.url ?? '';
      form.memo = d.memo ?? '';
      example.invalid = example.invalid ?? '';
      form.country_id = (d.country_id ?? '')
        .split(',')
        .filter(Boolean)
        .map(Number);
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('sms_gateway_list')} - ${isEdit ? tl('edit') : tl('add')}`"
    width="860"
  >
    <ElForm label-width="140">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="tl('sms_gateway_name')">
          <ElInput v-model="form.name" maxlength="45" />
        </ElFormItem>
        <ElFormItem :label="tl('sms_gateway_code')">
          <ElInput v-model="form.code" :readonly="isEdit" maxlength="10" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElInput v-model="form.status" />
        </ElFormItem>
        <ElFormItem :label="tl('api_url')">
          <ElInput v-model="form.url" />
        </ElFormItem>
        <ElFormItem :label="tl('memo')">
          <ElInput v-model="form.memo" />
        </ElFormItem>
      </div>
      <ElFormItem :label="tl('sms_gateway_info')">
        <ElInput v-model="example.invalid" :rows="4" type="textarea" />
      </ElFormItem>
      <ElFormItem :label="tl('support_country')">
        <ElSelect v-model="form.country_id" class="!w-full" filterable multiple>
          <ElOption
            v-for="c in countries"
            :key="c.id"
            :label="c.name"
            :value="c.id"
          />
        </ElSelect>
      </ElFormItem>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
