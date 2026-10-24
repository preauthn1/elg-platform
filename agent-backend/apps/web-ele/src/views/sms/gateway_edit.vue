<script lang="ts" setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
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

defineOptions({ name: 'SmsGatewayEdit' });

const route = useRoute();
const router = useRouter();

const editId = ref<number | string>((route.query.id as string) ?? '');
const isEdit = ref(!!editId.value);

const countries = ref<Array<{ id: number | string; name: string }>>([]);
const saving = ref(false);

const form = reactive({
  name: '',
  code: '',
  status: '' as number | string,
  url: '',
  memo: '',
  info: '',
  country_id: [] as Array<number | string>,
});

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
      router.back();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  countries.value = (await getCountryDropdownApi().catch(() => [])) ?? [];
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
});
</script>

<template>
  <Page
    :title="`${tl('sms_management')} > ${tl('sms_gateway_list')} > ${
      isEdit ? tl('edit') : tl('add')
    }`"
  >
    <ElCard shadow="never">
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
          <ElSelect
            v-model="form.country_id"
            class="!w-full"
            filterable
            multiple
          >
            <ElOption
              v-for="c in countries"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </ElSelect>
        </ElFormItem>
        <div class="flex justify-end gap-2">
          <ElButton @click="router.back()">{{ tl('btn_back') }}</ElButton>
          <ElButton :loading="saving" type="primary" @click="handleSave">
            {{ tl('btn_update') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>
  </Page>
</template>
