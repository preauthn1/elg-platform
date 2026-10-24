<script lang="ts" setup>
import type { AnnouncementRow, RebateProportion } from '#/api';

import { computed, onMounted, ref } from 'vue';

import { Page } from '@vben/common-ui';
import { useUserStore } from '@vben/stores';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElEmpty,
  ElTable,
  ElTableColumn,
  ElTag,
} from 'element-plus';

import {
  getAnnouncementsApi,
  getCustomizedSettingApi,
  getGeneralAgentApi,
  getRebateProportionApi,
  markAnnouncementReadApi,
} from '#/api';
import { tl } from '#/locales';

import PersonalInfo from './components/personal-info.vue';

defineOptions({ name: 'Home' });

const userStore = useUserStore();

const PER_PAGE = 20;

/** 代理自訂設定（setting_key → value） */
const settingsMap = ref<Map<number, any>>(new Map());

/** 設定旗標 */
const personalInfoVisible = ref(false); // 5401
const rebateVisible = ref(false); // 5402
const showAnnouncement = ref(true);

/** 公告 */
const loading = ref(false);
const announcements = ref<AnnouncementRow[]>([]);
const page = ref(1);
const hasNext = computed(() => announcements.value.length >= PER_PAGE);

/** 內容彈窗 */
const contentDialogVisible = ref(false);
const currentContent = ref('');

/** 返水占成彈窗（5402） */
const rebateDialogVisible = ref(false);
const rebateRows = ref<RebateProportion[]>([]);
const rebateTableRef = ref<{ doLayout: () => void }>();

/** 占成百分比顯示 */
function pct(v?: number) {
  return String(Number((Number(v) * 100).toFixed(4)));
}

/** 公告類型 → 語言 key / 標籤顏色 */
const ANNOUNCEMENT_TYPE: Record<
  number,
  { key: string; type: 'danger' | 'info' | 'primary' | 'success' | 'warning' }
> = {
  1: { key: 'general_announcement', type: 'info' },
  2: { key: 'vendor_announcement', type: 'primary' },
  3: { key: 'emergency_announcement', type: 'danger' },
  4: { key: 'important_announcement', type: 'warning' },
  5: { key: 'system_announcement', type: 'success' },
};

function typeLabel(type: number) {
  const item = ANNOUNCEMENT_TYPE[type];
  return item
    ? { text: tl(item.key), type: item.type }
    : { text: String(type), type: 'info' as const };
}

function openContent(row: AnnouncementRow) {
  currentContent.value = row.content;
  contentDialogVisible.value = true;
}

async function loadAnnouncements() {
  loading.value = true;
  try {
    const rows = await getAnnouncementsApi({
      page: page.value,
      per_page: PER_PAGE,
      type: 0,
    });
    announcements.value = rows.toSorted(
      (a, b) => Number(b.is_top ?? 0) - Number(a.is_top ?? 0),
    );
  } finally {
    loading.value = false;
  }
}

function changePage(delta: number) {
  const next = page.value + delta;
  if (next < 1) return;
  page.value = next;
  loadAnnouncements();
}

/** 依 getGeneralAgent 判斷是否顯示公告 */
async function resolveShowAnnouncement() {
  const info = userStore.userInfo as any;
  const id = Number(info?.id ?? 0);
  const hostId = Number(info?.host_id ?? 0);
  const parent = Number(info?.parent ?? 0);

  if (id === 1 || !id) return; // 總代或資訊不足，維持顯示
  if (hostId === 1 || parent === 1) return;

  try {
    const data = await getGeneralAgentApi();
    const generalAgents = (data?.host ?? []).map((h) => Number(h.id));
    const target = hostId === 0 ? id : hostId;
    if (!generalAgents.includes(target)) {
      showAnnouncement.value = false;
    }
  } catch {
    // 取不到一般代理清單時維持顯示
  }
}

onMounted(async () => {
  // 1. 讀代理自訂設定
  try {
    const settings = await getCustomizedSettingApi();
    const map = new Map(settings.map((s) => [Number(s.setting_key), s.value]));
    settingsMap.value = map;
    personalInfoVisible.value = Number(map.get(5401)) === 1;
    rebateVisible.value = Number(map.get(5402)) === 1;
  } catch {
    // 設定讀取失敗則用預設值
  }

  // 2. 一般代理判斷
  await resolveShowAnnouncement();

  // 3. 公告（非個人資訊模式且允許顯示時）
  if (!personalInfoVisible.value && showAnnouncement.value) {
    await loadAnnouncements();
    // 標記已讀（fire and forget）
    markAnnouncementReadApi().catch(() => {});
  }

  // 4. 股東返水占成彈窗（5402）
  if (rebateVisible.value) {
    try {
      rebateRows.value = await getRebateProportionApi();
      if (rebateRows.value.length > 0) rebateDialogVisible.value = true;
    } catch {
      // ignore
    }
  }
});
</script>

<template>
  <Page>
    <!-- 個人資訊／股東模式（5401） -->
    <PersonalInfo v-if="personalInfoVisible" :settings="settingsMap" />

    <!-- 公告表（預設首頁內容） -->
    <ElCard v-else-if="showAnnouncement" shadow="never">
      <template #header>
        <span class="text-base font-medium">
          {{ tl('menu_backend_announcement') }}
        </span>
      </template>

      <ElTable v-loading="loading" :data="announcements" stripe border>
        <ElTableColumn align="center" width="80">
          <template #default="{ row }">
            <ElTag v-if="Number(row.is_top) === 1" size="small" type="danger">
              {{ tl('is_top') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('num')"
          prop="id"
          align="center"
          width="100"
        />
        <ElTableColumn :label="tl('time')" prop="created_at" width="200" />
        <ElTableColumn :label="tl('type')" align="center" width="120">
          <template #default="{ row }">
            <ElTag :type="typeLabel(row.type).type" size="small">
              {{ typeLabel(row.type).text }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('content')" min-width="320">
          <template #default="{ row }">
            <div class="flex items-center gap-2">
              <span class="line-clamp-1 flex-1">{{ row.content }}</span>
              <ElButton
                link
                type="primary"
                @click="openContent(row as AnnouncementRow)"
              >
                {{ tl('watch_data') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>
          <ElEmpty :description="tl('no_record')" />
        </template>
      </ElTable>

      <div class="mt-4 flex items-center justify-end gap-3">
        <ElButton :disabled="page <= 1 || loading" @click="changePage(-1)">
          {{ tl('previous_page') }}
        </ElButton>
        <span class="text-sm">{{ page }}</span>
        <ElButton :disabled="!hasNext || loading" @click="changePage(1)">
          {{ tl('next_page') }}
        </ElButton>
      </div>
    </ElCard>

    <!-- 公告內容彈窗 -->
    <ElDialog
      v-model="contentDialogVisible"
      :title="tl('announcement_content')"
      width="540"
      align-center
    >
      <p class="whitespace-pre-line break-words">{{ currentContent }}</p>
    </ElDialog>

    <!-- 股東返水占成彈窗（5402） -->
    <ElDialog
      v-model="rebateDialogVisible"
      :title="tl('rebase_list')"
      width="640"
      align-center
      @opened="rebateTableRef?.doLayout()"
    >
      <ElTable
        ref="rebateTableRef"
        :data="rebateRows"
        size="small"
        border
        max-height="560"
        style="width: 100%"
      >
        <ElTableColumn :label="tl('gaming_platform')" min-width="130">
          <template #default="{ row }">
            <div>{{ row.vendor_name }}</div>
            <div
              v-if="row.item_type === 1"
              class="text-muted-foreground text-xs"
            >
              {{ row.game_type_name }}
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('currency')" align="center" width="72">
          <template #default>MYR</template>
        </ElTableColumn>
        <ElTableColumn :label="tl('shareholder')" align="right" width="90">
          <template #default="{ row }">
            {{ pct(row.proportion_3) }}
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('upper_cash_player')"
          align="right"
          width="120"
        >
          <template #default="{ row }">
            {{ pct(row.proportion_2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('cash_player')" align="right" width="104">
          <template #default="{ row }">
            {{ pct(row.proportion_1) }}
          </template>
        </ElTableColumn>
      </ElTable>
    </ElDialog>
  </Page>
</template>
