<script lang="ts" setup>
import type {
  AgentSettingGroup,
  AgentSettingItem,
  AgentSettingsBody,
} from '#/api';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { SimpleRichText } from '@vben/plugins/simple-rich-text';
import { useUserStore } from '@vben/stores';

import { useMediaQuery } from '@vueuse/core';
import dayjs from 'dayjs';
import {
  ElButton,
  ElDatePicker,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import { uploadImageApi } from '#/api';
import { tl } from '#/locales';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';

defineOptions({ name: 'AgentSettingsGroupsPanel' });

const props = defineProps<{
  agentId?: number | string;
  loadFn: (agentId: number | string) => Promise<AgentSettingsBody>;
  saveFn: (
    data: Record<string, any>,
  ) => Promise<{ code: number; message: string }>;
}>();

const userStore = useUserStore();
const targetId = computed(
  () => props.agentId ?? (userStore.userInfo as any)?.id,
);

const loading = ref(false);
const groups = ref<AgentSettingGroup[]>([]);
// 目前選中的類別 tab（setting_category_id 字串）
const activeTab = ref('');
// 電腦左側 tab；手機（≤768px）改上方橫向，避免右邊內容被擠太窄
const isMobile = useMediaQuery('(max-width: 768px)');
const tabPosition = computed<'left' | 'top'>(() =>
  isMobile.value ? 'top' : 'left',
);
const agentName = ref('');
const loadedAgentId = ref<number | string>('');

/** 跨類別關鍵字篩選：比對設定標題、language_text_code、setting_id 與類別名稱（不分大小寫） */
const keyword = ref('');
const visibleGroups = computed<AgentSettingGroup[]>(() => {
  const q = keyword.value.trim().toLowerCase();
  if (!q) return groups.value;

  return groups.value
    .map((g) => {
      const categoryHit = (g.setting_category_name ?? '')
        .toLowerCase()
        .includes(q);
      const settings = categoryHit
        ? (g.settings ?? [])
        : (g.settings ?? []).filter((s) =>
            [s.text, s.language_text_code, String(s.setting_id)].some((v) =>
              (v ?? '').toLowerCase().includes(q),
            ),
          );

      return { ...g, settings };
    })
    .filter((g) => g.settings.length > 0);
});
/** 篩選後目前 tab 消失了就跳到第一個有結果的類別 */
watch(visibleGroups, (list) => {
  const first = list[0];
  if (
    first &&
    !list.some((g) => String(g.setting_category_id) === activeTab.value)
  ) {
    activeTab.value = String(first.setting_category_id);
  }
});
/** 存檔／取消一律拿原始類別（含被篩掉的設定），避免只送出畫面上看得到的那幾筆 */
function originalGroup(gid: number | string): AgentSettingGroup | undefined {
  return groups.value.find(
    (g) => String(g.setting_category_id) === String(gid),
  );
}
/** 各組值 form_<settingId>（或 form_<id>_language_<langId>）→ value */
const values = reactive<Record<string, any>>({});
/** 編輯中的組 id */
const editing = reactive<Record<string, boolean>>({});
const saving = reactive<Record<string, boolean>>({});
const uploading = reactive<Record<string, boolean>>({});
/** 各多語系設定目前顯示的語系 tab，key 為 setting_id */
const activeLang = reactive<Record<string, string>>({});

function keyOf(id: number | string) {
  return `form_${id}`;
}
function langKeyOf(id: number | string, langId: number | string) {
  return `form_${id}_language_${langId}`;
}

function isLang(s: AgentSettingItem) {
  return s.data_type === 'language_text' || s.data_type === 'language_textarea';
}
// 這幾個多語系設定舊版用 textarea（其餘 language_text 用單行）
const LANG_TEXTAREA_IDS = new Set([3098, 3099, 9104]);
/**
 * 工單4104：頁尾政策文案，內容需要排版，改用編輯器存 HTML。
 *
 * 只套用在這 5 個欄位——其餘多語系設定維持純文字，免得舊資料被當成 HTML 解析。
 * 語系也只有這幾個走 tab：政策條文長、16 語系全攤開會讓頁面爆長，且能避免
 * 5 欄位 × 16 語系同時產生 80 個編輯器實例（ElTabPane 預設 lazy render）。
 * 既有設定（如客服的自動訊息回覆）維持原本全部語系並列，不改變營運習慣。
 */
const LANG_RICHTEXT_IDS = new Set([66_003, 66_005, 66_007, 66_009, 66_011]);
function isLangRichText(s: AgentSettingItem) {
  return LANG_RICHTEXT_IDS.has(Number(s.setting_id));
}
function isLangTextarea(s: AgentSettingItem) {
  return (
    s.data_type === 'language_textarea' ||
    LANG_TEXTAREA_IDS.has(Number(s.setting_id))
  );
}

/**
 * 後端 personal-settings 的 settings 是 Laravel Collection 直接輸出，鍵不從 0 起算的組
 * 會被序列化成物件（只有第一組是陣列），統一轉成陣列，否則 for...of／map 會炸掉。
 */
function toSettingArray(settings: unknown): AgentSettingItem[] {
  if (Array.isArray(settings)) return settings;
  return settings
    ? Object.values(settings as Record<string, AgentSettingItem>)
    : [];
}

/** datetime 欄位統一顯示格式；後端沒存過值時給的預設值不帶秒數，存過的反而帶秒數 */
const DATETIME_FORMAT = 'YYYY-MM-DD HH:mm';
/** 用 language_text_code 判斷是否為「結束時間」類欄位，不用畫面上的翻譯文字（會隨語系變動） */
function isEndTimeField(s: AgentSettingItem) {
  return (s.language_text_code ?? '').endsWith('_end_time');
}

/** 把一組設定的值寫進 values（載入與取消還原共用） */
function seedValues(s: AgentSettingItem) {
  if (isLang(s)) {
    for (const lv of s.languageValues ?? []) {
      values[langKeyOf(s.setting_id, lv.language_id)] = lv.language_value ?? '';
    }

    // 走 tab 的才需要預設語系；已選過的不覆蓋，避免取消編輯時跳回第一個 tab
    const first = s.languageValues?.[0];
    if (isLangRichText(s) && first && !activeLang[String(s.setting_id)]) {
      activeLang[String(s.setting_id)] = String(first.language_id);
    }
  } else if (s.data_type === 'datetime') {
    values[keyOf(s.setting_id)] = s.value
      ? dayjs(s.value).format(DATETIME_FORMAT)
      : dayjs().format(
          isEndTimeField(s) ? 'YYYY-MM-DD 23:59' : 'YYYY-MM-DD 00:00',
        );
  } else {
    values[keyOf(s.setting_id)] =
      s.data_type === 'boolean' ? Number(s.value) : (s.value ?? '');
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await props.loadFn(targetId.value);
    if (Number(res.code) === 1000 && res.data) {
      groups.value = (res.data.groups ?? []).map((g) => ({
        ...g,
        settings: toSettingArray(g.settings),
      }));
      activeTab.value = String(groups.value[0]?.setting_category_id ?? '');
      agentName.value = res.data.agent_name ?? '';
      loadedAgentId.value = res.data.agent_id ?? targetId.value;
      for (const g of groups.value) {
        for (const s of g.settings ?? []) seedValues(s);
      }
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    loading.value = false;
  }
}

function startEdit(gid: number | string) {
  editing[String(gid)] = true;
}

function cancelEdit(gid: number | string, group: AgentSettingGroup) {
  editing[String(gid)] = false;
  for (const s of group.settings ?? []) seedValues(s);
}

/** image 類型上傳本地檔：base64 → 圖庫，回填 URL 到該設定值 */
function uploadFile(s: AgentSettingItem) {
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = IMAGE_ACCEPT;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    if (!file) return;
    let base64: string;
    try {
      base64 = await readImageAsDataUrl(file);
    } catch (error) {
      if (error instanceof ImageUploadError) {
        ElMessage.error(error.message);
        return;
      }
      throw error;
    }
    uploading[String(s.setting_id)] = true;
    try {
      const url = await uploadImageApi(base64);
      if (url) values[keyOf(s.setting_id)] = url;
      else ElMessage.error(tl('failure'));
    } catch (error: any) {
      ElMessage.error(error?.message || tl('failure'));
    } finally {
      uploading[String(s.setting_id)] = false;
    }
  });
  input.click();
}

async function save(group: AgentSettingGroup) {
  const gid = String(group.setting_category_id);
  saving[gid] = true;
  try {
    const settingIds = (group.settings ?? []).map((s) => s.setting_id);
    const payload: Record<string, any> = {
      agent_id: loadedAgentId.value,
      setting_ids: settingIds.join(','),
    };
    for (const s of group.settings ?? []) {
      if (isLang(s)) {
        for (const lv of s.languageValues ?? []) {
          const k = langKeyOf(s.setting_id, lv.language_id);
          payload[k] = values[k];
        }
      } else {
        payload[keyOf(s.setting_id)] = values[keyOf(s.setting_id)];
      }
    }
    const res = await props.saveFn(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      // 寫回基準值供下次取消還原
      for (const s of group.settings ?? []) {
        if (isLang(s)) {
          for (const lv of s.languageValues ?? []) {
            lv.language_value = values[langKeyOf(s.setting_id, lv.language_id)];
          }
        } else {
          s.value = values[keyOf(s.setting_id)];
        }
      }
      editing[gid] = false;
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    saving[gid] = false;
  }
}

onMounted(load);
</script>

<template>
  <div v-loading="loading">
    <div v-if="agentName" class="mb-3 text-base">
      <b>{{ tl('agent') }}</b
      >：{{ agentName }} ({{ loadedAgentId }})
    </div>

    <ElInput
      v-if="groups.length > 0"
      v-model="keyword"
      class="mb-3"
      clearable
      :placeholder="tl('settings_keyword_filter')"
    />
    <ElEmpty
      v-if="groups.length > 0 && visibleGroups.length === 0"
      :description="tl('no_data')"
    />

    <ElTabs
      v-if="visibleGroups.length > 0"
      v-model="activeTab"
      :tab-position="tabPosition"
      class="settings-tabs"
    >
      <ElTabPane
        v-for="group in visibleGroups"
        :key="group.setting_category_id"
        :label="group.setting_category_name"
        :name="String(group.setting_category_id)"
      >
        <div class="mb-3 flex items-center justify-between gap-2">
          <span class="text-base font-medium">
            {{ group.setting_category_name }}
          </span>
          <div class="flex gap-2">
            <ElButton
              v-if="!editing[String(group.setting_category_id)]"
              size="small"
              type="primary"
              @click="startEdit(group.setting_category_id)"
            >
              {{ tl('edit') }}
            </ElButton>
            <template v-else>
              <ElButton
                :loading="saving[String(group.setting_category_id)]"
                size="small"
                type="success"
                @click="save(originalGroup(group.setting_category_id) ?? group)"
              >
                {{ tl('btn_update') }}
              </ElButton>
              <ElButton
                size="small"
                @click="
                  cancelEdit(
                    group.setting_category_id,
                    originalGroup(group.setting_category_id) ?? group,
                  )
                "
              >
                {{ tl('cancel') }}
              </ElButton>
            </template>
          </div>
        </div>

        <!--
          @submit.prevent：Tiptap 工具列的下拉按鈕（標題／顏色）底層是
          reka-ui Primitive as="button"，沒指定 type，在表單內預設 submit，
          點擊會提交表單導致整頁刷新。
        -->
        <ElForm label-position="top" @submit.prevent>
          <ElFormItem
            v-for="s in group.settings"
            :key="s.setting_id"
            :label="s.text"
          >
            <!-- boolean：啟用/停用 -->
            <ElRadioGroup
              v-if="s.data_type === 'boolean'"
              v-model="values[keyOf(s.setting_id)]"
              :disabled="!editing[String(group.setting_category_id)]"
            >
              <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
              <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
            </ElRadioGroup>

            <!--
              工單4104 的政策文案：語系切為 tab（內容長、16 語系全攤開頁面會爆），
              已填的語系標一個點方便盤點。其餘多語系設定維持原本全部並列。
            -->
            <div v-else-if="isLangRichText(s)" class="w-full">
              <ElTabs
                v-model="activeLang[String(s.setting_id)]"
                class="lang-tabs"
                type="border-card"
              >
                <ElTabPane
                  v-for="lv in s.languageValues"
                  :key="lv.language_id"
                  :name="String(lv.language_id)"
                >
                  <template #label>
                    <span class="flex items-center gap-1">
                      {{ lv.language_name }}
                      <span
                        v-if="values[langKeyOf(s.setting_id, lv.language_id)]"
                        class="size-1.5 rounded-full bg-green-500"
                      ></span>
                    </span>
                  </template>
                  <SimpleRichText
                    v-model="values[langKeyOf(s.setting_id, lv.language_id)]"
                    :editable="!!editing[String(group.setting_category_id)]"
                    :min-height="180"
                  />
                </ElTabPane>
              </ElTabs>
            </div>

            <!-- 其餘多語系文字：維持每語系一格並列（原本的樣子） -->
            <div v-else-if="isLang(s)" class="w-full">
              <div
                v-for="lv in s.languageValues"
                :key="lv.language_id"
                class="mb-2 flex items-start gap-3"
              >
                <span class="text-muted-foreground w-28 shrink-0 pt-1 text-sm">
                  {{ lv.language_name }}
                </span>
                <ElInput
                  v-model="values[langKeyOf(s.setting_id, lv.language_id)]"
                  :disabled="!editing[String(group.setting_category_id)]"
                  :rows="isLangTextarea(s) ? 3 : undefined"
                  :type="isLangTextarea(s) ? 'textarea' : 'text'"
                />
              </div>
            </div>

            <!-- image：URL 框 + 上傳 -->
            <div v-else-if="s.data_type === 'image'" class="w-full">
              <div class="flex items-center gap-2">
                <ElInput
                  v-model="values[keyOf(s.setting_id)]"
                  :disabled="!editing[String(group.setting_category_id)]"
                  class="flex-1"
                />
                <ElButton
                  v-if="editing[String(group.setting_category_id)]"
                  :loading="uploading[String(s.setting_id)]"
                  size="small"
                  @click="uploadFile(s)"
                >
                  {{ tl('upload_image') }}
                </ElButton>
              </div>
              <div
                v-if="editing[String(group.setting_category_id)]"
                class="text-muted-foreground mt-1 text-xs"
              >
                {{ imageUploadText('image_upload_hint') }}
              </div>
              <img
                v-if="values[keyOf(s.setting_id)]"
                :src="values[keyOf(s.setting_id)]"
                class="mt-1 max-h-40 max-w-full object-contain"
              />
            </div>

            <!-- textarea -->
            <ElInput
              v-else-if="s.data_type === 'textarea'"
              v-model="values[keyOf(s.setting_id)]"
              :disabled="!editing[String(group.setting_category_id)]"
              :rows="3"
              type="textarea"
            />

            <!-- datetime -->
            <ElDatePicker
              v-else-if="s.data_type === 'datetime'"
              v-model="values[keyOf(s.setting_id)]"
              :disabled="!editing[String(group.setting_category_id)]"
              class="!w-full"
              format="YYYY-MM-DD HH:mm"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm"
            />

            <!-- 其他（string / json …） -->
            <ElInput
              v-else
              v-model="values[keyOf(s.setting_id)]"
              :disabled="!editing[String(group.setting_category_id)]"
            />
          </ElFormItem>
        </ElForm>
      </ElTabPane>
    </ElTabs>

    <ElEmpty
      v-if="!loading && groups.length === 0"
      :description="tl('no_record')"
    />
  </div>
</template>
