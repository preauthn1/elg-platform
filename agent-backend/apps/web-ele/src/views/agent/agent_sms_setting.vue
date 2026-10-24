<script lang="ts" setup>
import type { AgentSmsTextItem } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import { useMediaQuery } from '@vueuse/core';
import {
  ElButton,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import { getAgentSmsGroupSettingApi, updateAgentSmsSettingApi } from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'AgentSmsSetting' });

const props = defineProps<{ embedGroupId?: string }>();

const route = useRoute();

const groupId = ref<number | string>(
  props.embedGroupId || (route.query.group_id as string) || '',
);
const groupName = ref('');
const loading = ref(false);

// 分頁位置：電腦左側 / 手機上方
const activeTab = ref('detail');
const isMobile = useMediaQuery('(max-width: 768px)');
const tabPosition = computed<'left' | 'top'>(() =>
  isMobile.value ? 'top' : 'left',
);

// 卡1：群組明細設定（key→value）
const detailId = ref<number | string>(0);
const detailKeys = ref<string[]>([]);
const detailValues = reactive<Record<string, string>>({});
const editingDetail = ref(false);
const savingDetail = ref(false);

// 卡2：簡訊內容（依 parameter 分區，name sms_text[type_id][country_id]）
const smsText = ref<AgentSmsTextItem[]>([]);
const smsTextValues = reactive<Record<string, string>>({});
const editingText = ref(false);
const savingText = ref(false);

const groupedText = computed(() => {
  const map = new Map<string, AgentSmsTextItem[]>();
  for (const t of smsText.value) {
    const k = String(t.parameter ?? t.remark ?? '');
    if (!map.has(k)) map.set(k, []);
    map.get(k)?.push(t);
  }
  return [...map.entries()].map(([parameter, items]) => ({ items, parameter }));
});

function smsKey(t: AgentSmsTextItem) {
  return `${t.type_id}_${t.country_id}`;
}

async function loadSetting() {
  if (!groupId.value) return;
  loading.value = true;
  try {
    const res = await getAgentSmsGroupSettingApi(groupId.value);
    if (Number(res.code) !== 1000 || !res.data) {
      ElMessage.error(res.message || tl('failure'));
      smsText.value = [];
      detailKeys.value = [];
      return;
    }
    groupName.value = res.data.group_name ?? '';
    // detail
    detailId.value = res.data.group_detail?.id ?? 0;
    let parsed: Record<string, any> = {};
    try {
      parsed = JSON.parse(res.data.group_detail?.setting ?? '{}');
    } catch {
      parsed = {};
    }
    detailKeys.value = Object.keys(parsed);
    for (const k of detailKeys.value) {
      detailValues[k] =
        Number(detailId.value) === 0 ? '' : String(parsed[k] ?? '');
    }
    // sms_text
    smsText.value = res.data.sms_text ?? [];
    for (const t of smsText.value) {
      smsTextValues[smsKey(t)] = t.text ?? '';
    }
  } finally {
    loading.value = false;
  }
}

async function saveDetail() {
  savingDetail.value = true;
  try {
    const obj: Record<string, string> = {};
    for (const k of detailKeys.value) obj[k] = detailValues[k] ?? '';
    const res = await updateAgentSmsSettingApi({
      group_id: groupId.value,
      setting: JSON.stringify(obj),
      type: 'detail',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editingDetail.value = false;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    savingDetail.value = false;
  }
}

async function saveText() {
  savingText.value = true;
  try {
    const nested: Record<string, Record<string, string>> = {};
    for (const t of smsText.value) {
      const tid = String(t.type_id);
      const cid = String(t.country_id);
      const row = (nested[tid] ??= {});
      row[cid] = smsTextValues[smsKey(t)] ?? '';
    }
    const res = await updateAgentSmsSettingApi({
      group_id: groupId.value,
      sms_text: nested,
      type: 'sms_text',
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      editingText.value = false;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    savingText.value = false;
  }
}

onMounted(loadSetting);
</script>

<template>
  <Page
    :content-class="embedGroupId ? 'px-0' : ''"
    :title="`${tl('agnet_management')} > ${tl('menu_agent_advance_setting')}`"
  >
    <template #description>
      <b>{{ tl('name') }}</b
      >：{{ groupName }}
      <span class="text-muted-foreground">({{ groupId }})</span>
    </template>

    <ElTabs v-model="activeTab" :tab-position="tabPosition">
      <!-- 簡訊群組設定 -->
      <ElTabPane :label="tl('sms_group_detail_setting')" name="detail">
        <div class="mb-3 flex justify-end gap-2">
          <ElButton
            v-if="!editingDetail"
            size="small"
            type="primary"
            @click="editingDetail = true"
          >
            {{ tl('edit') }}
          </ElButton>
          <template v-else>
            <ElButton
              :loading="savingDetail"
              size="small"
              type="success"
              @click="saveDetail"
            >
              {{ tl('btn_update') }}
            </ElButton>
            <ElButton
              size="small"
              @click="
                loadSetting();
                editingDetail = false;
              "
            >
              {{ tl('cancel') }}
            </ElButton>
          </template>
        </div>
        <div v-loading="loading">
          <ElForm label-position="top">
            <ElFormItem
              v-for="k in detailKeys"
              :key="k"
              :label="tl(`sms_detail.${k}`)"
            >
              <ElInput v-model="detailValues[k]" :disabled="!editingDetail" />
            </ElFormItem>
            <ElEmpty
              v-if="detailKeys.length === 0"
              :description="tl('no_record')"
            />
          </ElForm>
        </div>
      </ElTabPane>

      <!-- 簡訊文字內容 -->
      <ElTabPane :label="tl('sms_content')" name="text">
        <div class="mb-3 flex justify-end gap-2">
          <ElButton
            v-if="!editingText"
            size="small"
            type="primary"
            @click="editingText = true"
          >
            {{ tl('edit') }}
          </ElButton>
          <template v-else>
            <ElButton
              :loading="savingText"
              size="small"
              type="success"
              @click="saveText"
            >
              {{ tl('btn_update') }}
            </ElButton>
            <ElButton
              size="small"
              @click="
                loadSetting();
                editingText = false;
              "
            >
              {{ tl('cancel') }}
            </ElButton>
          </template>
        </div>
        <div v-loading="loading">
          <ElForm label-position="top">
            <div v-for="grp in groupedText" :key="grp.parameter" class="mb-4">
              <h4 class="mb-2 border-b pb-1 font-bold">
                {{ tl(`sms_text_type.${grp.parameter}`) }}
              </h4>
              <ElFormItem
                v-for="t in grp.items"
                :key="smsKey(t)"
                :label="t.country_name"
              >
                <ElInput
                  v-model="smsTextValues[smsKey(t)]"
                  :disabled="!editingText"
                />
              </ElFormItem>
            </div>
            <ElEmpty
              v-if="smsText.length === 0"
              :description="tl('no_record')"
            />
          </ElForm>
        </div>
      </ElTabPane>
    </ElTabs>
  </Page>
</template>
