<script lang="ts" setup>
import type { EmailGroupSettingLanguage, EmailGroupSettingText } from '#/api';

import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import { useMediaQuery } from '@vueuse/core';
import {
  ElButton,
  ElCard,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTabPane,
  ElTabs,
  ElTag,
} from 'element-plus';

import {
  getEmailGroupSettingApi,
  saveEmailGroupDetailApi,
  saveEmailTextApi,
} from '#/api';
import { tl } from '#/locales';

/**
 * 電子郵件設置組別（type7）高級設置（工單4286）
 *
 * 版型比照 agent_sms_setting.vue：左側分頁、每分頁各自「編輯／更新／取消」。
 * 差別：簡訊樣板以國家分、郵件樣板以語系分（固定一張「全語系」卡片不可移除），且郵件多了主旨。
 */
defineOptions({ name: 'AgentEmailSetting' });

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

/** 全語系列的 language_id */
const ALL_LANGUAGE_ID = 0;
/** 兩格都只收正整數 */
const POSITIVE_INT = /^[1-9]\d*$/;

// 分頁一：郵件群組設定（未設定過顯示空值，不是 0）
const detail = reactive({ max_send: '', token_expired: '' });
const editingDetail = ref(false);
const savingDetail = ref(false);

// 分頁二：郵件內容（依 8 種類型分區，每個語系一張卡片）
const textRows = ref<EmailGroupSettingText[]>([]);
const languages = ref<EmailGroupSettingLanguage[]>([]);
const placeholders = ref<string[]>(['{code}', '{email}']);
const editingText = ref(false);
const savingText = ref(false);
/** 各類型「＋ 新增語系」下拉目前選到的語系 */
const pendingLanguage = reactive<Record<number, '' | number>>({});

const groupedText = computed(() => {
  const map = new Map<
    number,
    { items: EmailGroupSettingText[]; parameter: string; typeId: number }
  >();
  for (const row of textRows.value) {
    const group = map.get(row.type_id) ?? {
      items: [],
      parameter: row.parameter ?? String(row.type_id),
      typeId: row.type_id,
    };
    group.items.push(row);
    map.set(row.type_id, group);
  }
  // 全語系卡片固定排第一
  return [...map.values()]
    .map((group) => ({
      ...group,
      items: group.items.toSorted((a, b) => a.language_id - b.language_id),
    }))
    .toSorted((a, b) => a.typeId - b.typeId);
});

function textKey(row: EmailGroupSettingText) {
  return `${row.type_id}_${row.language_id}`;
}

function languageLabel(row: EmailGroupSettingText) {
  if (row.language_id === ALL_LANGUAGE_ID) return tl('all_language');
  return (
    row.language_name ??
    languages.value.find((l) => l.id === row.language_id)?.name ??
    String(row.language_id)
  );
}

/** 該類型還沒加入的語系（已加入者不重複出現） */
function availableLanguages(typeId: number) {
  const used = new Set(
    textRows.value
      .filter((row) => row.type_id === typeId)
      .map((row) => row.language_id),
  );
  return languages.value.filter((l) => !used.has(l.id));
}

function addLanguage(typeId: number, parameter: string) {
  const languageId = pendingLanguage[typeId];
  if (languageId === '' || languageId === undefined) return;
  const language = languages.value.find((l) => l.id === languageId);
  if (!language) return;
  textRows.value.push({
    content: '',
    language_id: language.id,
    language_name: language.name,
    parameter,
    subject: '',
    type_id: typeId,
  });
  pendingLanguage[typeId] = '';
}

function removeLanguage(row: EmailGroupSettingText) {
  if (row.language_id === ALL_LANGUAGE_ID) return;
  textRows.value = textRows.value.filter((r) => textKey(r) !== textKey(row));
}

async function loadSetting() {
  if (!groupId.value) return;
  loading.value = true;
  try {
    const res = await getEmailGroupSettingApi(groupId.value);
    if (Number(res.code) !== 200 || !res.data) {
      ElMessage.error(res.message || tl('failure'));
      textRows.value = [];
      return;
    }
    groupName.value = res.data.group_name ?? '';
    detail.token_expired = res.data.group_detail?.setting.token_expired ?? '';
    detail.max_send = res.data.group_detail?.setting.max_send ?? '';
    textRows.value = (res.data.email_text ?? []).map((row) => ({ ...row }));
    languages.value = res.data.languages ?? [];
    placeholders.value = res.data.placeholders ?? ['{code}', '{email}'];
    for (const key of Object.keys(pendingLanguage)) {
      pendingLanguage[Number(key)] = '';
    }
  } finally {
    loading.value = false;
  }
}

async function saveDetail() {
  const tokenExpired = String(detail.token_expired).trim();
  const maxSend = String(detail.max_send).trim();
  if (!POSITIVE_INT.test(tokenExpired) || !POSITIVE_INT.test(maxSend)) {
    ElMessage.error(tl('positive_integer_required'));
    return;
  }
  savingDetail.value = true;
  try {
    const res = await saveEmailGroupDetailApi({
      group_id: groupId.value,
      max_send: Number(maxSend),
      token_expired: Number(tokenExpired),
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      editingDetail.value = false;
      await loadSetting();
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
    const res = await saveEmailTextApi({
      email_text: textRows.value.map((row) => ({
        content: row.content,
        language_id: row.language_id,
        subject: row.subject,
        type_id: row.type_id,
      })),
      group_id: groupId.value,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('success'));
      editingText.value = false;
      await loadSetting();
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    savingText.value = false;
  }
}

/** 取消＝重載丟棄未儲存修改（與簡訊一致） */
function cancelEdit() {
  editingDetail.value = false;
  editingText.value = false;
  loadSetting();
}

/** 切分頁時未儲存的修改不保留 */
function onTabChange() {
  if (editingDetail.value || editingText.value) cancelEdit();
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

    <ElTabs
      v-model="activeTab"
      :tab-position="tabPosition"
      @tab-change="onTabChange"
    >
      <!-- 郵件群組設定 -->
      <ElTabPane :label="tl('email_group_detail_setting')" name="detail">
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
            <ElButton size="small" @click="cancelEdit">
              {{ tl('cancel') }}
            </ElButton>
          </template>
        </div>
        <div v-loading="loading">
          <ElForm label-position="top">
            <ElFormItem :label="tl('email_detail.token_expired')">
              <ElInput
                v-model="detail.token_expired"
                :disabled="!editingDetail"
                inputmode="numeric"
              />
            </ElFormItem>
            <ElFormItem :label="tl('email_detail.max_send')">
              <ElInput
                v-model="detail.max_send"
                :disabled="!editingDetail"
                inputmode="numeric"
              />
            </ElFormItem>
          </ElForm>
        </div>
      </ElTabPane>

      <!-- 郵件內容 -->
      <ElTabPane :label="tl('email_content')" name="text">
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
            <ElButton size="small" @click="cancelEdit">
              {{ tl('cancel') }}
            </ElButton>
          </template>
        </div>
        <div v-loading="loading">
          <p class="text-muted-foreground mb-3 text-xs">
            {{ tl('available_variables') }}：
            <code v-for="p in placeholders" :key="p" class="mr-2">{{ p }}</code>
          </p>
          <div
            v-for="grp in groupedText"
            :key="grp.typeId"
            class="mb-6"
            :data-type-id="grp.typeId"
          >
            <div class="mb-2 flex items-center justify-between border-b pb-1">
              <h4 class="font-bold">
                {{ tl(`sms_text_type.${grp.parameter}`) }}
              </h4>
              <div v-if="editingText" class="flex items-center gap-2">
                <ElSelect
                  v-model="pendingLanguage[grp.typeId]"
                  :placeholder="tl('language')"
                  size="small"
                  style="width: 160px"
                >
                  <ElOption
                    v-for="l in availableLanguages(grp.typeId)"
                    :key="l.id"
                    :label="l.name"
                    :value="l.id"
                  />
                </ElSelect>
                <ElButton
                  :disabled="
                    pendingLanguage[grp.typeId] === '' ||
                    pendingLanguage[grp.typeId] === undefined
                  "
                  size="small"
                  @click="addLanguage(grp.typeId, grp.parameter)"
                >
                  {{ tl('add_language') }}
                </ElButton>
              </div>
            </div>
            <ElCard
              v-for="row in grp.items"
              :key="textKey(row)"
              class="mb-3"
              shadow="never"
            >
              <template #header>
                <div class="flex items-center justify-between">
                  <span>
                    <ElTag
                      :type="
                        row.language_id === ALL_LANGUAGE_ID ? 'info' : 'primary'
                      "
                      size="small"
                    >
                      {{ languageLabel(row) }}
                    </ElTag>
                  </span>
                  <ElButton
                    v-if="editingText && row.language_id !== ALL_LANGUAGE_ID"
                    link
                    size="small"
                    type="danger"
                    @click="removeLanguage(row)"
                  >
                    {{ tl('remove') }}
                  </ElButton>
                </div>
              </template>
              <ElForm label-position="top">
                <ElFormItem :label="tl('email_subject')">
                  <ElInput
                    v-model="row.subject"
                    :disabled="!editingText"
                    maxlength="200"
                  />
                </ElFormItem>
                <ElFormItem :label="tl('email_body')">
                  <ElInput
                    v-model="row.content"
                    :autosize="{ minRows: 3 }"
                    :disabled="!editingText"
                    type="textarea"
                  />
                </ElFormItem>
              </ElForm>
            </ElCard>
          </div>
          <ElEmpty
            v-if="!loading && textRows.length === 0"
            :description="tl('no_record')"
          />
        </div>
      </ElTabPane>
    </ElTabs>
  </Page>
</template>
