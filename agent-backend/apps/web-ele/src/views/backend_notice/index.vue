<script lang="ts" setup>
import type { PlatformNotificationRow, SystemNotificationRow } from '#/api';

import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElEmpty,
  ElPagination,
  ElTabPane,
  ElTabs,
  ElTag,
} from 'element-plus';

import {
  getPlatformNotificationListApi,
  getSystemNotificationListApi,
  readAllPlatformNotificationApi,
  readAllSystemNotificationApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'BackendNotice' });

const router = useRouter();
const PER_PAGE = 10;
const activeTab = ref('news');

// --- 最新消息 ---
const newsList = ref<PlatformNotificationRow[]>([]);
const newsTotal = ref(0);
const newsPage = ref(1);
const newsLoading = ref(false);

// --- 系統通知 ---
const sysList = ref<SystemNotificationRow[]>([]);
const sysTotal = ref(0);
const sysPage = ref(1);
const sysLoading = ref(false);

/** tag → ElTag type（0 不顯示） */
const TAG_TYPE: Record<
  number,
  'danger' | 'info' | 'primary' | 'success' | 'warning'
> = { 1: 'danger', 2: 'info', 3: 'success', 4: 'primary', 5: 'warning' };

async function loadNews() {
  newsLoading.value = true;
  try {
    const res = await getPlatformNotificationListApi({
      page: newsPage.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      newsList.value = res.data ?? [];
      newsTotal.value = res.pagination?.total ?? 0;
      readAllPlatformNotificationApi().catch(() => {});
    } else {
      newsList.value = [];
      newsTotal.value = 0;
    }
  } finally {
    newsLoading.value = false;
  }
}

async function loadSystem() {
  sysLoading.value = true;
  try {
    const res = await getSystemNotificationListApi({
      page: sysPage.value,
      per_page: PER_PAGE,
    });
    if (Number(res.code) === 1000) {
      sysList.value = res.data ?? [];
      sysTotal.value = res.pagination?.total ?? 0;
      readAllSystemNotificationApi().catch(() => {});
    } else {
      sysList.value = [];
      sysTotal.value = 0;
    }
  } finally {
    sysLoading.value = false;
  }
}

function onTabChange(name: number | string) {
  if (name === 'system' && sysList.value.length === 0) loadSystem();
}

// --- 最新消息點擊 ---
const newsModal = ref(false);
const newsModalData = reactive({ content: '', image: '', title: '' });
function onNewsClick(n: PlatformNotificationRow) {
  const type = Number(n.type);
  if (![1, 2, 3].includes(type)) return;
  const content = String(n.content ?? '');
  if (type === 2) {
    if (Number(n.open_window) === 1) window.open(content, '_blank');
    else window.location.href = content;
  } else if (type === 3) {
    const path = `/${content.replace(/^\//, '')}`;
    if (Number(n.open_window) === 1) window.open(path, '_blank');
    else router.push(path);
  } else {
    newsModalData.title = n.title ?? '';
    newsModalData.content = content;
    newsModalData.image = n.image_url ?? '';
    newsModal.value = true;
  }
}

// --- 系統通知詳情 ---
const SHOW_DETAIL_TAGS = new Set([
  'agent_transfer',
  'cs_transfer',
  'member_transfer',
  'orders',
]);
const sysModal = ref(false);
const sysRow = ref<null | SystemNotificationRow>(null);

function typeLabel(tag?: string) {
  return tag ? tl(`backend_notice_type_label_${tag}`) : '';
}
function subType(type?: number | string) {
  return type === undefined ? '' : tl(`agent_transaction_type.${type}`);
}
function fmtAmount(v?: number | string) {
  return Number(v ?? 0).toLocaleString('en-US', {
    maximumFractionDigits: 2,
    minimumFractionDigits: 2,
  });
}
function onSysClick(n: SystemNotificationRow) {
  if (!n.tag || !SHOW_DETAIL_TAGS.has(n.tag)) return;
  sysRow.value = n;
  sysModal.value = true;
}

onMounted(loadNews);
</script>

<template>
  <Page :title="`${tl('system_management')} > ${tl('menu_backend_notice')}`">
    <ElCard shadow="never">
      <ElTabs v-model="activeTab" @tab-change="onTabChange">
        <!-- 最新消息 -->
        <ElTabPane :label="tl('menu_platform_announcement')" name="news">
          <div v-loading="newsLoading" class="min-h-24">
            <ElEmpty
              v-if="newsList.length === 0"
              :description="tl('no_data')"
            />
            <div
              v-for="(n, i) in newsList"
              :key="i"
              class="mb-2 rounded border-l-4 border-red-500 p-4 shadow-sm"
              :class="[
                [1, 2, 3].includes(Number(n.type))
                  ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800'
                  : '',
              ]"
              @click="onNewsClick(n)"
            >
              <div class="flex items-start justify-between">
                <div>
                  <span class="text-base font-bold">{{ n.title }}</span>
                  <ElTag
                    v-if="Number(n.tag) > 0"
                    :type="TAG_TYPE[Number(n.tag)] ?? 'info'"
                    class="ml-2"
                    size="small"
                  >
                    {{ tl(`platform_announcement_tag_${n.tag}`) }}
                  </ElTag>
                </div>
              </div>
              <div class="text-muted-foreground mt-2 text-sm">
                {{ tl('label_start_time') }}: {{ n.start_time }}
              </div>
              <div
                v-if="Number(n.type) === 1 && n.content"
                class="text-muted-foreground mt-1 line-clamp-2 text-sm"
              >
                {{ n.content }}
              </div>
            </div>
          </div>
          <div v-if="newsTotal > PER_PAGE" class="mt-3 flex justify-center">
            <ElPagination
              :current-page="newsPage"
              :page-size="PER_PAGE"
              :total="newsTotal"
              background
              layout="prev, pager, next"
              @current-change="
                (p: number) => {
                  newsPage = p;
                  loadNews();
                }
              "
            />
          </div>
        </ElTabPane>

        <!-- 系統通知 -->
        <ElTabPane :label="tl('menu_system_notification')" name="system">
          <div v-loading="sysLoading" class="min-h-24">
            <ElEmpty v-if="sysList.length === 0" :description="tl('no_data')" />
            <div
              v-for="(n, i) in sysList"
              :key="i"
              class="mb-2 rounded border p-4 shadow-sm"
              :class="[
                n.tag && SHOW_DETAIL_TAGS.has(n.tag)
                  ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800'
                  : '',
              ]"
              @click="onSysClick(n)"
            >
              <div class="flex items-center justify-between">
                <span class="font-bold">{{ typeLabel(n.tag) }}</span>
                <span class="text-muted-foreground text-sm">
                  {{ n.time }}
                  <span
                    v-if="n.status === 'success'"
                    class="ml-1 text-green-600"
                  >
                    ✓
                  </span>
                  <span
                    v-else-if="n.status === 'fail'"
                    class="ml-1 text-red-500"
                  >
                    ✗
                  </span>
                  <span v-else class="text-muted-foreground ml-1">⧖</span>
                </span>
              </div>
              <div class="mt-2 text-center">
                <div class="text-muted-foreground text-sm">
                  {{ subType(n.type) }}
                </div>
                <div class="mt-1 text-base font-bold">{{ n.amount }}</div>
              </div>
            </div>
          </div>
          <div v-if="sysTotal > PER_PAGE" class="mt-3 flex justify-center">
            <ElPagination
              :current-page="sysPage"
              :page-size="PER_PAGE"
              :total="sysTotal"
              background
              layout="prev, pager, next"
              @current-change="
                (p: number) => {
                  sysPage = p;
                  loadSystem();
                }
              "
            />
          </div>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 最新消息彈窗（type=1） -->
    <ElDialog v-model="newsModal" :title="newsModalData.title" width="480">
      <div v-if="newsModalData.image" class="mb-3 text-center">
        <img
          :src="newsModalData.image"
          class="mx-auto max-w-full rounded"
          alt=""
        />
      </div>
      <div class="whitespace-pre-wrap text-sm">{{ newsModalData.content }}</div>
    </ElDialog>

    <!-- 系統通知詳情 -->
    <ElDialog v-model="sysModal" :title="tl('detail')" width="420">
      <template v-if="sysRow">
        <div class="mb-3 text-center text-sm">
          <span v-if="sysRow.status === 'success'" class="text-green-600">
            ✓ {{ tl('success') }}
          </span>
          <span v-else-if="sysRow.status === 'fail'" class="text-red-500">
            ✗ {{ tl('failure') }}
          </span>
          <span v-else class="text-muted-foreground">
            ⧖ {{ tl('processing') }}
          </span>
        </div>
        <div
          class="mb-3 rounded p-4 text-center text-white"
          style="background: linear-gradient(135deg, #4a0000 0%, #8b0000 100%)"
        >
          <div class="mb-1 text-sm">{{ subType(sysRow.type) }}</div>
          <div
            v-if="sysRow.tag === 'orders' && sysRow.content"
            class="text-2xl font-bold"
          >
            {{ sysRow.content }}
          </div>
          <div class="text-xl font-bold">{{ fmtAmount(sysRow.amount) }}</div>
        </div>
        <table class="w-full text-sm">
          <tbody>
            <tr>
              <td class="text-muted-foreground py-1">{{ tl('date') }}</td>
              <td class="py-1 text-right">{{ sysRow.time }}</td>
            </tr>
            <tr v-if="sysRow.content_1">
              <td class="text-muted-foreground py-1">{{ tl('account_no') }}</td>
              <td class="py-1 text-right">{{ sysRow.content_1 }}</td>
            </tr>
            <tr v-if="sysRow.content_2">
              <td class="text-muted-foreground py-1">
                {{ tl('account_name') }}
              </td>
              <td class="py-1 text-right">{{ sysRow.content_2 }}</td>
            </tr>
            <tr v-if="sysRow.from && sysRow.to">
              <td class="text-muted-foreground py-1">{{ tl('payer') }}</td>
              <td class="py-1 text-right">{{ sysRow.from }}</td>
            </tr>
            <tr v-if="sysRow.from && sysRow.to">
              <td class="text-muted-foreground py-1">{{ tl('payee') }}</td>
              <td class="py-1 text-right">{{ sysRow.to }}</td>
            </tr>
          </tbody>
        </table>
      </template>
      <template #footer>
        <ElButton @click="sysModal = false">{{ tl('close') }}</ElButton>
      </template>
    </ElDialog>
  </Page>
</template>
