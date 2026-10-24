<script lang="ts" setup>
import type { ExcelLangConvertData } from '#/api';

import { computed, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElAlert,
  ElButton,
  ElCard,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import { convertExcelToLangApi } from '#/api';
import { tl, tlKeys } from '#/locales';

/**
 * 內部工具：Excel 轉多語系（對應舊版 internal-helper/excel_helper.php）。
 * 貼上 Excel 內容 → 後端轉成各語言檔程式碼，逐檔逐語言複製。
 */
defineOptions({ name: 'InternalExcelHelper' });

const SHEET_URL =
  'https://example.invalid/spreadsheets/d/1DQlCWspgpc_bv9IwFRs8NxYbcrOIlR7rbA3vrh6gyL4/edit?usp=sharing';
const EXAMPLE_URL =
  'https://example.invalid/cdn-betmonsters-com-cdn-bucket/161673255753.png';

const title = computed(
  () => `${tl('system_management')} > ${tl('excel_to_multilingual')}`,
);

const applicationType = ref<number | string>(1);
const excelContent = ref('');
const converting = ref(false);
const result = ref<ExcelLangConvertData | null>(null);

const undefinedColumns = computed(() => result.value?.undefined_columns ?? []);

async function submitContent() {
  converting.value = true;
  try {
    const payload: {
      applicationType: number | string;
      compareTargetLangKeys?: string;
      excel_content: string;
    } = {
      applicationType: applicationType.value,
      excel_content: excelContent.value,
    };
    // 選 Web.AgentBackend 時把現有多語系 key 傳給 API 檢查未定義鍵值
    if (Number(applicationType.value) === 51) {
      payload.compareTargetLangKeys = tlKeys().join(',');
    }
    const res = await convertExcelToLangApi(payload, 0);
    result.value = res.data ?? null;
    if (!res.data) {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    converting.value = false;
  }
}

async function copyToClipboard(text: string) {
  await navigator.clipboard.writeText(text);
  ElMessage.success(tl('copy'));
}

function copyFileLang(fileName: string, index: number) {
  const text = result.value?.file_outCode_result?.[fileName]?.[index] ?? '';
  copyToClipboard(text);
}

/** 複製缺少的欄位（TSV，對齊舊版格式，貼回 Google Sheet 用） */
function copyUndefinedText() {
  const text = undefinedColumns.value
    .map(
      (c) => `${c.file_name}\t${c.lang_key}\tXXXXXXXXXXXXXX\tXXXXXXXXXXXXXX\n`,
    )
    .join('');
  copyToClipboard(text);
}
</script>

<template>
  <Page :title="title">
    <ElCard shadow="never">
      <div class="mb-4">
        <span class="font-semibold">{{ tl('paste_the_excel_format') }}</span>
        <a
          :href="SHEET_URL"
          class="ml-4 text-sm text-sky-600 underline"
          target="_blank"
        >
          {{ tl('excel_multilingual_link') }}
        </a>
        <a
          :href="EXAMPLE_URL"
          class="ml-4 text-sm text-sky-600 underline"
          target="_blank"
        >
          {{ tl('schematic_example') }}
        </a>
      </div>

      <div class="mb-1 text-xs text-muted-foreground">
        {{ tl('multilingual_classification') }}
      </div>
      <ElSelect v-model="applicationType" class="!w-72">
        <ElOption :value="1" label="API.Agent" />
        <ElOption :value="51" label="Web.AgentBackend" />
        <ElOption :label="tl('no_classification')" :value="0" />
      </ElSelect>

      <ElInput
        v-model="excelContent"
        :placeholder="tl('paste_excel_format')"
        :rows="10"
        class="mt-2"
        type="textarea"
      />

      <div class="mt-4">
        <ElButton :loading="converting" type="success" @click="submitContent">
          {{ tl('start_convert') }}
        </ElButton>
      </div>

      <!-- 有未定義鍵值：警告與複製缺漏欄位 -->
      <div
        v-if="undefinedColumns.length > 0"
        class="mt-6 rounded-md bg-orange-100 p-4 text-red-600"
      >
        <ElButton type="danger" @click="copyUndefinedText">
          {{ tl('copy_lost_data') }}
        </ElButton>
        <div class="mt-2">
          {{ tl('detect') }}【{{ undefinedColumns.length }}{{ tl('count') }}】
          {{ tl('undefine_lankey') }}
        </div>
        <ul class="mt-2 list-disc pl-6">
          <li>{{ tl('method1') }}</li>
          <li>{{ tl('method2') }}</li>
        </ul>
      </div>

      <!-- 轉換成功：逐檔逐語言複製 -->
      <template v-else-if="result?.list_files?.length">
        <ElAlert
          :closable="false"
          :title="tl('overwriting')"
          class="mt-6"
          type="info"
        />
        <div v-for="fileName in result.list_files" :key="fileName" class="mt-4">
          <h5 class="mb-2 font-bold">{{ fileName }}</h5>
          <div class="flex flex-wrap gap-2">
            <ElButton
              v-for="(lang, idx) in result.list_langs ?? []"
              :key="lang"
              type="primary"
              @click="copyFileLang(fileName, idx)"
            >
              {{ tl('copy') }}({{ lang }})
            </ElButton>
          </div>
        </div>
      </template>
    </ElCard>
  </Page>
</template>
