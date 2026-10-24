<script lang="ts" setup>
import type { SmsDropdownRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  getAgentGroupDropdownApi,
  getCountryMobileListApi,
  getSmsDropdownApi,
  getSmsGatewayShowApi,
  getSmsInfoShowApi,
  saveSmsInfoApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));

const gateways = ref<SmsDropdownRow[]>([]);
const groups = ref<SmsDropdownRow[]>([]);
const countries = ref<Array<{ id: number | string; name: string }>>([]);
const params = ref<Array<{ key: string; type: string }>>([]);
const saving = ref(false);

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_group_id: 0 as number | string,
    cost: '' as number | string,
    country_id: [] as Array<number | string>,
    gateway_id: '' as number | string,
    gateway_name: '',
    paramValues: {} as Record<string, string>,
    sel_gateway_id: 0 as number | string,
    sort: '' as number | string,
    status: 0 as number | string,
  };
}

/** 由 schema JSON 建立動態參數欄位 */
function buildParams(schemaJson: string, values: Record<string, any> = {}) {
  let schema: Record<string, any>;
  try {
    schema = JSON.parse(schemaJson) ?? {};
  } catch {
    schema = {};
  }
  const list: Array<{ key: string; type: string }> = [];
  const vals: Record<string, string> = {};
  for (const [key, param] of Object.entries(schema)) {
    if (param && (param as any).type) {
      list.push({ key, type: (param as any).type });
      vals[key] = String(values[key] ?? '');
    }
  }
  params.value = list;
  form.paramValues = vals;
}

async function onGatewayChange(gatewayId: number | string) {
  form.gateway_id = gatewayId;
  if (!gatewayId || Number(gatewayId) === 0) {
    params.value = [];
    form.paramValues = {};
    countries.value = [];
    return;
  }
  const [detail, mobiles] = await Promise.all([
    getSmsGatewayShowApi(gatewayId).catch(() => null),
    getCountryMobileListApi(gatewayId).catch(() => []),
  ]);
  if (detail) buildParams(example.invalid ?? '{}');
  countries.value = mobiles ?? [];
}

async function handleSave() {
  saving.value = true;
  try {
    const payload: Record<string, unknown> = {
      cost: form.cost,
      country_id: form.country_id,
      gateway_id: form.gateway_id,
      id: editId.value,
      sort: form.sort,
      status: form.status,
      ...form.paramValues,
    };
    if (isEdit.value) {
      payload.name = form.gateway_name;
    } else {
      payload.sel_gateway_id = form.sel_gateway_id;
      payload.agent_group_id = form.agent_group_id;
    }
    const res = await saveSmsInfoApi(payload);
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
  params.value = [];
  countries.value = [];
  visible.value = true;
  if (isEdit.value) {
    const d = await getSmsInfoShowApi(editId.value).catch(() => null);
    if (d) {
      form.gateway_name = d.gateway_name ?? '';
      form.gateway_id = d.gateway_id ?? '';
      form.cost = d.cost ?? '';
      form.sort = d.sort ?? '';
      form.status = Number(d.status ?? 0);
      form.country_id = (d.country_id ?? '')
        .split(',')
        .filter(Boolean)
        .map(Number);
      let values: Record<string, any>;
      try {
        values = JSON.parse(example.invalid) ?? {};
      } catch {
        values = {};
      }
      buildParams(d.gateway_info ?? '{}', values);
      countries.value =
        (await getCountryMobileListApi(d.gateway_id).catch(() => [])) ?? [];
    }
  } else {
    const [gw, gp] = await Promise.all([
      getSmsDropdownApi().catch(() => []),
      getAgentGroupDropdownApi().catch(() => []),
    ]);
    gateways.value = gw ?? [];
    groups.value = (gp ?? []).filter(
      (g) => Number((g as any).agent_group_type) === 3,
    );
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('sms_info_list')} - ${isEdit ? tl('edit') : tl('add')}`"
    top="6vh"
    width="860"
  >
    <ElForm label-width="140">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="tl('sms_gateway_name')">
          <ElInput v-if="isEdit" v-model="form.gateway_name" readonly />
          <ElSelect
            v-else
            v-model="form.sel_gateway_id"
            class="!w-full"
            @change="onGatewayChange"
          >
            <ElOption :label="tl('please_choose')" :value="0" />
            <ElOption
              v-for="g in gateways"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="!isEdit" :label="tl('agent_group_type_3')">
          <ElSelect v-model="form.agent_group_id" class="!w-full">
            <ElOption :label="tl('please_choose')" :value="0" />
            <ElOption
              v-for="g in groups"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('sms_fee')">
          <ElInput v-model="form.cost" class="text-right" />
        </ElFormItem>
        <ElFormItem :label="tl('sort')">
          <ElInput v-model="form.sort" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>

      <ElFormItem v-for="p in params" :key="p.key" :label="p.key" class="!mb-3">
        <ElInput v-model="form.paramValues[p.key]" :type="p.type" />
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
