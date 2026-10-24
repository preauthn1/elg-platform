<script lang="ts" setup>
import type { DomainTemplateOption, DomainTemplateRow } from '#/api';

import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElImage,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getDomainTemplateListApi,
  getDomainTemplateOptionsApi,
  getDomainTemplateViewApi,
  redisFlushDbApi,
  saveDomainTemplateApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import ImageInput from '#/components/image-input.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';

defineOptions({ name: 'DomainTemplateIndex' });

const router = useRouter();

const PER_PAGE = 20;

const loading = ref(false);
const allRows = ref<DomainTemplateRow[]>([]);
const templates = ref<DomainTemplateOption[]>([]);

const {
  handlePageChange,
  handleSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows,
  total,
} = useClientTable(allRows, {
  perPage: PER_PAGE,
  searchFields: (r) => [
    r.id,
    r.domain,
    r.title,
    r.username,
    r.agent_id,
    r.organization_name,
  ],
});

const title = computed(
  () => `${tl('system_management')} > ${tl('management_domain_template')}`,
);

function fmtTime(v?: string) {
  return v ? dayjs(v).add(8, 'hour').format('YYYY-MM-DD HH:mm:ss') : '';
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getDomainTemplateListApi();
    allRows.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
  } finally {
    loading.value = false;
  }
}

async function loadTemplates() {
  try {
    templates.value = (await getDomainTemplateOptionsApi()) ?? [];
  } catch {
    templates.value = [];
  }
}

/** 清除 Redis */
async function triggerRedisFlush() {
  const res = await redisFlushDbApi();
  ElMessage({
    message: res.message ?? '',
    type: Number(res.code) === 1000 ? 'success' : 'error',
  });
}

// 編輯/新增彈窗
const dialogVisible = ref(false);
const saving = ref(false);
const form = reactive({
  add_home_image: '',
  default_invitation_code: '',
  domain: '',
  icon: '',
  id: '' as number | string,
  logo_mobile: '',
  logo_pc: '',
  mobile_templates: 12 as number | string,
  need_login: 0 as number | string,
  pc_templates: 21 as number | string,
  status: 1 as number | string,
  title: '',
});

function resetForm() {
  form.id = '';
  form.domain = '';
  form.title = '';
  form.default_invitation_code = '';
  form.status = 1;
  form.need_login = 0;
  form.logo_pc = '';
  form.logo_mobile = '';
  form.icon = '';
  form.add_home_image = '';
  form.pc_templates = 21;
  form.mobile_templates = 12;
}

function openCreate() {
  resetForm();
  dialogVisible.value = true;
}

async function openEdit(row: DomainTemplateRow) {
  resetForm();
  form.id = row.id;
  dialogVisible.value = true;
  try {
    const d = await getDomainTemplateViewApi(row.id);
    if (d && typeof d === 'object') {
      form.domain = d.domain ?? '';
      form.title = d.title ?? '';
      form.default_invitation_code = d.default_invitation_code ?? '';
      form.status = d.status ?? 1;
      form.need_login = d.need_login ?? 0;
      form.logo_pc = d.logo_pc ?? '';
      form.logo_mobile = d.logo_mobile ?? '';
      form.icon = d.icon ?? '';
      form.add_home_image = d.add_home_image ?? '';
      form.pc_templates =
        Number(d.pc_templates) === 0
          ? (d.template_id ?? 21)
          : (d.pc_templates ?? 21);
      form.mobile_templates =
        Number(d.mobile_templates) === 0
          ? (d.template_id ?? 12)
          : (d.mobile_templates ?? 12);
    }
  } catch {
    // 用列資料
  }
}

async function submitForm() {
  saving.value = true;
  try {
    const res = await saveDomainTemplateApi({
      add_home_image: form.add_home_image,
      default_invitation_code: form.default_invitation_code,
      domain: form.domain,
      icon: form.icon,
      id: form.id === '' ? undefined : form.id,
      logo_mobile: form.logo_mobile,
      logo_pc: form.logo_pc,
      mobile_templates: form.mobile_templates,
      need_login: form.need_login,
      pc_templates: form.pc_templates,
      status: form.status,
      title: form.title,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message ?? '');
      dialogVisible.value = false;
      loadData();
    } else {
      ElMessage.error(
        Number(res.code) === 2002
          ? String(res.data ?? '')
          : (res.message ?? ''),
      );
    }
  } finally {
    saving.value = false;
  }
}

const imageSlots = computed(() => [
  { field: 'logo_pc' as const, key: 'choose_pc_logo', src: form.logo_pc },
  {
    field: 'logo_mobile' as const,
    key: 'choose_mobile_logo',
    src: form.logo_mobile,
  },
  { field: 'icon' as const, key: 'choose_favorite_icon', src: form.icon },
  {
    field: 'add_home_image' as const,
    key: 'add_home_image',
    src: form.add_home_image,
  },
]);

loadData();
loadTemplates();
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="mb-3 flex flex-wrap gap-2">
        <ElButton type="success" @click="openCreate">{{ tl('add') }}</ElButton>
        <ElButton type="danger" @click="triggerRedisFlush">
          {{ tl('redis_flush') }}
        </ElButton>
        <ElButton
          type="warning"
          @click="router.push('/internal-helper/excel_helper')"
        >
          {{ tl('multilingual_conversion_tool') }}
        </ElButton>
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleSearch"
        />
      </div>

      <ElTable
        v-loading="loading"
        :data="pagedRows"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('id')"
          prop="id"
          sortable="custom"
          width="80"
        />
        <ElTableColumn
          :label="tl('domain')"
          min-width="160"
          prop="domain"
          sortable="custom"
        >
          <template #default="{ row }">
            <span class="text-sky-600 font-semibold">{{ row.domain }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('website_name')"
          prop="title"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('agent')"
          min-width="140"
          prop="username"
          sortable="custom"
        >
          <template #default="{ row }">
            <div class="font-semibold">
              <AgentAccount :username="row.username" />
            </div>
            <div class="text-muted-foreground text-xs">
              {{ tl('id') }} {{ row.agent_id }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="`${tl('agent')} ${tl('organization')}`"
          prop="organization_name"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn :label="tl('webiste_logo')" min-width="100">
          <template #default="{ row }">
            <ElImage
              v-if="row.logo_pc"
              :src="row.logo_pc"
              fit="contain"
              style="height: 40px"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('status')"
          min-width="90"
          prop="status"
          sortable="custom"
        >
          <template #default="{ row }">
            <span :class="Number(row.status) === 1 ? '' : 'text-red-400'">
              {{ Number(row.status) === 1 ? tl('enable') : tl('disable') }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('operating_time')"
          min-width="160"
          prop="updated_at"
          sortable="custom"
        >
          <template #default="{ row }">{{ fmtTime(row.updated_at) }}</template>
        </ElTableColumn>
        <ElTableColumn label="" width="120" fixed="right">
          <template #default="{ row }">
            <ElButton size="small" @click="openEdit(row as DomainTemplateRow)">
              {{ tl('setting_template') }}
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>

    <ElDialog
      v-model="dialogVisible"
      :title="`${tl('management_domain_template')} > ${tl('edit_template')}`"
      top="5vh"
      width="900px"
    >
      <ElForm
        class="max-h-[70vh] overflow-y-auto pb-6 pr-1"
        label-position="top"
      >
        <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
          <ElFormItem :label="tl('domain')">
            <ElInput v-model="form.domain" />
          </ElFormItem>
          <ElFormItem :label="tl('website_name')">
            <ElInput v-model="form.title" />
          </ElFormItem>
          <ElFormItem :label="`${tl('default')}${tl('invitation_code')}`">
            <ElInput v-model="form.default_invitation_code" />
          </ElFormItem>
        </div>
        <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
          <ElFormItem :label="tl('enable_status')">
            <ElRadioGroup v-model="form.status">
              <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="tl('show_public_view_without_login')">
            <ElRadioGroup v-model="form.need_login">
              <ElRadio :value="0">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="1">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div v-for="slot in imageSlots" :key="slot.field">
            <p class="mb-2 text-center font-semibold">{{ tl(slot.key) }}</p>
            <ImageInput v-model="form[slot.field]" />
          </div>
        </div>

        <p class="mb-2 mt-4 font-semibold">{{ tl('choose_template') }}</p>
        <ElTabs>
          <ElTabPane :label="tl('pc_version')">
            <ElRadioGroup v-model="form.pc_templates" class="!block">
              <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                <div v-for="t in templates" :key="`pc-${t.id}`">
                  <ElRadio :value="t.id">{{ t.name }}</ElRadio>
                  <ElImage
                    v-if="t.thumbnail"
                    class="w-full"
                    :src="t.thumbnail"
                    fit="contain"
                    style="height: 150px"
                  />
                </div>
              </div>
            </ElRadioGroup>
          </ElTabPane>
          <ElTabPane :label="tl('mobile_version')">
            <ElRadioGroup v-model="form.mobile_templates" class="!block">
              <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                <div v-for="t in templates" :key="`mb-${t.id}`">
                  <ElRadio :value="t.id">{{ t.name }}</ElRadio>
                  <ElImage
                    v-if="t.thumbnail"
                    class="w-full"
                    :src="t.thumbnail"
                    fit="contain"
                    style="height: 150px"
                  />
                </div>
              </div>
            </ElRadioGroup>
          </ElTabPane>
        </ElTabs>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ tl('btn_back') }}</ElButton>
        <ElButton :loading="saving" type="primary" @click="submitForm">
          {{ tl('btn_update') }}
        </ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
