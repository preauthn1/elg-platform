<script lang="ts" setup>
import type { TgbotBrandRow, TgbotBroadcastRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import { Page } from '@vben/common-ui';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElForm,
  ElFormItem,
  ElLink,
  ElMessage,
  ElMessageBox,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  deleteTgbotBroadcastApi,
  getTgbotBrandListApi,
  getTgbotBroadcastListApi,
  pauseTgbotBroadcastApi,
  resumeTgbotBroadcastApi,
} from '#/api';
import AgentAccount from '#/components/agent-account.vue';
import TablePagination from '#/components/table-pagination.vue';
import { tl } from '#/locales';

import TgbotBroadcastEditDialog from './components/tgbot-broadcast-edit-dialog.vue';

defineOptions({ name: 'TgbotTelegramBroadcastList' });

const editRef = ref<InstanceType<typeof TgbotBroadcastEditDialog>>();
const PER_PAGE = 10;

const filters = reactive({ brand_id: '' as number | string, status: '' });
const brands = ref<TgbotBrandRow[]>([]);
const loading = ref(false);
const rows = ref<TgbotBroadcastRow[]>([]);
const total = ref(0);
const page = ref(1);

const title = computed(
  () =>
    `${tl('menu_tgbot_management')} > ${tl('menu_tgbot_telegram_broadcast_message')}`,
);

interface MsgItem {
  content?: string;
  lang?: string;
  media_url?: string;
}
function parseMessage(message?: string): MsgItem[] | string {
  if (!message) return '';
  try {
    const v = JSON.parse(message);
    return Array.isArray(v) ? v : message;
  } catch {
    return message;
  }
}

/** 0 發送中、1 暫停（待處理）、2 發送完畢、3 未發送 */
const STATUS_UNSENT = 3;

function statusText(s: number | string) {
  const n = Number(s);
  if (n === 0) return tl('sending');
  if (n === 1) return tl('pending');
  if (n === STATUS_UNSENT) return tl('not_sent');
  return tl('success');
}

async function loadBrands() {
  const res = await getTgbotBrandListApi();
  brands.value = Number(res.code) === 1000 ? (res.data ?? []) : [];
}

async function loadData() {
  loading.value = true;
  try {
    const res = await getTgbotBroadcastListApi({
      brand_id: filters.brand_id,
      page: page.value,
      status: filters.status,
    });
    if (Number(res.code) === 1000) {
      rows.value = res.data?.data ?? [];
      total.value = res.data?.total ?? 0;
    } else {
      rows.value = [];
      total.value = 0;
    }
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadData();
}
function handlePage(p: number) {
  page.value = p;
  loadData();
}

async function doPause(row: TgbotBroadcastRow) {
  try {
    await ElMessageBox.confirm(tl('confirm_pause'));
    const res = await pauseTgbotBroadcastApi(row.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('pause_success'));
      loadData();
    } else {
      ElMessage.error(tl('pause_failed'));
    }
  } catch {
    /* cancelled */
  }
}

async function doResume(row: TgbotBroadcastRow) {
  try {
    await ElMessageBox.confirm(
      Number(row.status) === STATUS_UNSENT
        ? tl('confirm_broadcast_send')
        : tl('confirm_send'),
    );
    const res = await resumeTgbotBroadcastApi(row.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('send_success'));
      loadData();
    } else {
      ElMessage.error(tl('send_failed'));
    }
  } catch {
    /* cancelled */
  }
}

async function doDelete(row: TgbotBroadcastRow) {
  try {
    await ElMessageBox.confirm(tl('confirm_broadcast_delete'));
    const res = await deleteTgbotBroadcastApi(row.id);
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('delete_success'));
      loadData();
    } else {
      ElMessage.error(res.message || tl('delete_failed'));
    }
  } catch {
    /* cancelled */
  }
}

/* 預覽 modal */
const previewVisible = ref(false);
const previewType = ref('');
const previewItems = ref<MsgItem[]>([]);
function openPreview(row: TgbotBroadcastRow) {
  previewType.value = String(row.type ?? '');
  const parsed = parseMessage(row.message);
  previewItems.value = Array.isArray(parsed) ? parsed : [];
  previewVisible.value = true;
}

loadBrands();
loadData();
</script>

<template>
  <Page :title="title">
    <div class="mb-3">
      <ElButton type="success" @click="editRef?.open()">
        {{ tl('add') }}
      </ElButton>
    </div>

    <ElCard class="mb-3" shadow="never">
      <template #header>
        <span class="font-medium">{{ tl('search') }}</span>
      </template>
      <ElForm label-width="auto" @submit.prevent="handleSearch">
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('agent_account')">
            <ElSelect v-model="filters.brand_id" class="!w-full" filterable>
              <ElOption :label="tl('all')" value="" />
              <ElOption
                v-for="b in brands"
                :key="b.id"
                :label="`${b.bot_id} (${b.count})`"
                :value="b.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSelect v-model="filters.status" class="!w-full">
              <ElOption :label="tl('all')" value="" />
              <ElOption :label="tl('sending')" value="0" />
              <ElOption :label="tl('pending')" value="1" />
              <ElOption :label="tl('success')" value="2" />
              <ElOption :label="tl('not_sent')" value="3" />
            </ElSelect>
          </ElFormItem>
        </div>
        <div class="flex justify-end">
          <ElButton :loading="loading" native-type="submit" type="primary">
            {{ tl('search') }}
          </ElButton>
        </div>
      </ElForm>
    </ElCard>

    <ElCard shadow="never">
      <ElTable v-loading="loading" :data="rows" border size="small" stripe>
        <ElTableColumn :label="tl('id')" prop="id" width="70" />
        <ElTableColumn
          :label="tl('message_type')"
          prop="type"
          min-width="100"
        />
        <ElTableColumn :label="tl('message')" min-width="220">
          <template #default="{ row }">
            <template v-if="Array.isArray(parseMessage(row.message))">
              <ul class="list-none p-0">
                <li
                  v-for="(m, i) in parseMessage(row.message) as MsgItem[]"
                  :key="i"
                >
                  <strong>{{ (m.lang || '').toUpperCase() }} </strong>:
                  {{ (m.content || '').slice(0, 30)
                  }}{{ (m.content || '').length > 30 ? '...' : '' }}
                  <ElLink
                    v-if="m.media_url"
                    :href="m.media_url"
                    target="_blank"
                    type="primary"
                  >
                    [{{ tl('media') }}]
                  </ElLink>
                </li>
              </ul>
            </template>
            <span v-else>
              {{ String(parseMessage(row.message)).slice(0, 50)
              }}{{ String(parseMessage(row.message)).length > 50 ? '...' : '' }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('status')" min-width="100">
          <template #default="{ row }">{{ statusText(row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('total_count')"
          prop="total_count"
          align="right"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('waiting_count')"
          prop="waiting_count"
          align="right"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('success_count')"
          prop="success_count"
          align="right"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('fail_count')"
          prop="fail_count"
          align="right"
          min-width="90"
        />
        <ElTableColumn
          :label="tl('created_at')"
          prop="created_at"
          min-width="160"
        />
        <ElTableColumn :label="tl('operator')" prop="operator" min-width="110">
          <template #default="{ row }">
            <AgentAccount :username="row.operator" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('action')" min-width="230" fixed="right">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElButton
                v-if="Number(row.status) === 0"
                size="small"
                type="warning"
                @click="doPause(row as TgbotBroadcastRow)"
              >
                {{ tl('pause') }}
              </ElButton>
              <ElButton
                v-else-if="Number(row.status) === 1"
                size="small"
                type="success"
                @click="doResume(row as TgbotBroadcastRow)"
              >
                {{ tl('start_send') }}
              </ElButton>
              <template v-else-if="Number(row.status) === STATUS_UNSENT">
                <ElButton
                  size="small"
                  type="success"
                  @click="doResume(row as TgbotBroadcastRow)"
                >
                  {{ tl('send_broadcast') }}
                </ElButton>
                <ElButton
                  size="small"
                  type="primary"
                  @click="editRef?.open(row as TgbotBroadcastRow)"
                >
                  {{ tl('edit') }}
                </ElButton>
                <ElButton
                  size="small"
                  type="danger"
                  @click="doDelete(row as TgbotBroadcastRow)"
                >
                  {{ tl('delete') }}
                </ElButton>
              </template>
              <ElButton
                size="small"
                type="info"
                @click="openPreview(row as TgbotBroadcastRow)"
              >
                {{ tl('preview') }}
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePage"
      />
    </ElCard>

    <ElDialog
      v-model="previewVisible"
      :title="tl('message_preview')"
      width="700px"
    >
      <h5 class="mb-2 font-medium">
        {{ tl('message_type') }}: {{ previewType }}
      </h5>
      <div v-for="(item, i) in previewItems" :key="i" class="mb-3">
        <h6 class="font-medium">{{ (item.lang || '').toUpperCase() }}</h6>
        <template v-if="item.media_url">
          <img
            v-if="previewType === 'image'"
            :src="item.media_url"
            class="mb-2 max-w-full"
            alt=""
          />
          <video
            v-else-if="previewType === 'animation'"
            :src="item.media_url"
            class="mb-2 max-w-full"
            controls
          ></video>
          <audio
            v-else-if="previewType === 'voice'"
            :src="item.media_url"
            controls
          ></audio>
        </template>
        <p>{{ item.content || '' }}</p>
        <hr />
      </div>
    </ElDialog>

    <TgbotBroadcastEditDialog ref="editRef" @success="loadData" />
  </Page>
</template>
