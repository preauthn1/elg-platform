<script lang="ts" setup>
import type { RefreshRankingEventView, RefreshRankingPrize } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  editRefreshRankingApi,
  getLanguagesApi,
  getRefreshRankingViewApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

/** 獎勵點數類型 */
const POINT_TYPE_LABEL: Record<number, string> = {
  1: 'platform_point',
  2: 'rolling_chip',
  3: 'entity_reward',
  4: 'promotion_wallet',
};

const visible = ref(false);
const eventId = ref('');
const languages = ref<Array<{ id: number | string; name: string }>>([]);
const event = ref<RefreshRankingEventView>({});
const saving = ref(false);
let languagesLoaded = false;

const activeStep = ref('step1');
const activeLang = ref('default');

/** 預設語系名稱/內容 */
const base = reactive({ content: '', title: '' });
/** 各語系名稱/內容（key = language_id） */
const langData = reactive<Record<string, { content: string; title: string }>>(
  {},
);

const prizes = computed<RefreshRankingPrize[]>(() => event.value.prizes ?? []);

function pointTypeText(t?: number | string) {
  return tl(POINT_TYPE_LABEL[Number(t)] ?? '');
}

/** 獎勵點數顯示：type 1=百分比、其他=點數 */
function rewardPoint(row: RefreshRankingPrize) {
  return Number(row.type) === 1
    ? `${row.point_percentage} %`
    : String(row.point ?? '');
}

/** 稽核流水限制（vendor/game_type 並行陣列） */
const flowLimitRows = computed(() => {
  const vendorRaw = String(event.value.flow_limit_vendor_id ?? '')
    .replaceAll(/["[\] ]/g, '')
    .split(',')
    .filter((s) => s !== '');
  const typeRaw = String(event.value.flow_limit_game_type ?? '')
    .replaceAll(/["[\] ]/g, '')
    .split(',')
    .filter((s) => s !== '');
  return vendorRaw.map((v, i) => ({
    game_type: typeRaw[i] ?? '0',
    vendor_id: v,
  }));
});

async function handleSave() {
  saving.value = true;
  try {
    const event_languages = languages.value
      .filter((l) => (langData[String(l.id)]?.title ?? '') !== '')
      .map((l) => ({
        content: langData[String(l.id)]?.content ?? '',
        language_id: l.id,
        title: langData[String(l.id)]?.title ?? '',
      }));
    const res = await editRefreshRankingApi({
      event_content: base.content === '' ? ' ' : base.content,
      event_languages: event_languages.length > 0 ? event_languages : undefined,
      id: eventId.value,
      title: base.title,
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

async function open(id: number | string) {
  eventId.value = String(id ?? '');
  event.value = {};
  base.content = '';
  base.title = '';
  for (const k of Object.keys(langData)) delete langData[k];
  activeStep.value = 'step1';
  activeLang.value = 'default';
  visible.value = true;
  if (!languagesLoaded) {
    const langRes = await getLanguagesApi().catch(() => []);
    languages.value = (langRes ?? []).map((l) => ({ id: l.id, name: l.name }));
    languagesLoaded = true;
  }
  for (const l of languages.value) {
    langData[String(l.id)] = { content: '', title: '' };
  }
  const viewRes = await getRefreshRankingViewApi(eventId.value).catch(
    () => null,
  );
  if (viewRes && Number(viewRes.code) === 1000 && viewRes.data) {
    event.value = viewRes.data;
    base.title = viewRes.data.title ?? '';
    base.content = viewRes.data.content ?? '';
    for (const row of viewRes.data.languages ?? []) {
      langData[String(row.language_id)] = {
        content: row.content ?? '',
        title: row.title ?? '',
      };
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_refresh_ranking_list')} - ${tl('edit')}${tl('event')}`"
    top="5vh"
    width="900"
  >
    <ElTabs v-model="activeStep" type="border-card">
      <ElTabPane :label="tl('event_and_content')" name="step1">
        <div class="mb-4 flex items-center gap-2">
          <span class="font-medium">
            <span class="text-red-500">*</span> {{ tl('agent_account') }}：
          </span>
          <span>{{ event.agent_username }}</span>
        </div>
        <ElTabs v-model="activeLang">
          <ElTabPane :label="tl('default')" name="default">
            <div class="mb-3">
              <p class="mb-1 text-sm">
                <span class="text-red-500">*</span> {{ tl('event_name') }}
              </p>
              <ElInput v-model="base.title" />
            </div>
            <div>
              <p class="mb-1 text-sm">{{ tl('event_content') }}</p>
              <ElInput v-model="base.content" :rows="4" type="textarea" />
            </div>
          </ElTabPane>
          <ElTabPane
            v-for="l in languages"
            :key="l.id"
            :label="l.name"
            :name="String(l.id)"
          >
            <div class="mb-3">
              <p class="mb-1 text-sm">{{ tl('event_name') }}</p>
              <ElInput v-model="langData[String(l.id)]!.title" />
            </div>
            <div>
              <p class="mb-1 text-sm">{{ tl('event_content') }}</p>
              <ElInput
                v-model="langData[String(l.id)]!.content"
                :rows="4"
                type="textarea"
              />
            </div>
          </ElTabPane>
        </ElTabs>
      </ElTabPane>

      <ElTabPane :label="tl('event_reward_setting')" name="step2">
        <ElTable :data="prizes" border size="small" stripe>
          <ElTableColumn
            :label="tl('reward_name')"
            prop="name"
            min-width="140"
          />
          <ElTableColumn :label="tl('level')" prop="level" width="100" />
          <ElTableColumn :label="tl('reward_type')" width="140">
            <template #default="{ row }">
              {{ pointTypeText(row.point_type) }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('reward_point')" align="right" width="140">
            <template #default="{ row }">{{ rewardPoint(row) }}</template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('reward_item_count')"
            align="right"
            prop="number"
            width="140"
          />
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElTabPane>

      <ElTabPane :label="tl('flow_limit')" name="step3">
        <div class="mb-4 flex items-center gap-2">
          <span class="font-medium">
            {{ tl('event_flow_limit_multiple') }}：
          </span>
          <span>{{ event.flow_limit_multiple ?? 0 }}</span>
        </div>
        <ElTable :data="flowLimitRows" border size="small" stripe>
          <ElTableColumn :label="tl('label_vendor')" min-width="160">
            <template #default="{ row }">
              {{
                Number(row.vendor_id) === 0 ? tl('unlimited') : row.vendor_id
              }}
            </template>
          </ElTableColumn>
          <ElTableColumn :label="tl('game_type')" min-width="160">
            <template #default="{ row }">
              {{
                Number(row.game_type) === 0 ? tl('unlimited') : row.game_type
              }}
            </template>
          </ElTableColumn>
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElTabPane>
    </ElTabs>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="success" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
