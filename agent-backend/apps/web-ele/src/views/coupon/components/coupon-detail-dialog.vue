<script lang="ts" setup>
import type { CouponCodeRow, CouponDetail } from '#/api';

import { computed, ref } from 'vue';

import {
  ElButton,
  ElCard,
  ElCollapse,
  ElCollapseItem,
  ElDialog,
  ElInput,
  ElMessage,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCouponCodesApi,
  getCouponViewApi,
  getLanguagesApi,
  toggleCouponStatusApi,
  updateCouponApi,
  uploadCouponImageApi,
} from '#/api';
import TablePagination from '#/components/table-pagination.vue';
import { useClientTable } from '#/composables/use-client-table';
import { tl } from '#/locales';
import { exportExcel } from '#/utils/export-excel';
import {
  IMAGE_ACCEPT,
  ImageUploadError,
  imageUploadText,
  readImageAsDataUrl,
} from '#/utils/image-upload';
import MemberAccountLink from '#/views/member/components/member-account-link.vue';

const emit = defineEmits<{ success: [] }>();

const PER_PAGE = 20;

const visible = ref(false);
const detailId = ref('');
const detail = ref<CouponDetail>({ id: '' });
const codes = ref<CouponCodeRow[]>([]);
const loading = ref(false);

const {
  handlePageChange,
  handleSearch: handleQuickSearch,
  handleSortChange,
  keyword,
  page,
  pagedRows: pagedCodes,
  total,
} = useClientTable(codes, {
  perPage: PER_PAGE,
  searchFields: (r) => [r.code, r.member_username, r.mobile, r.used_at],
});

function typeText(t?: number | string) {
  return Number(t) === 2 ? tl('rolling_chip') : tl('platform_point');
}
function yesNo(v?: number | string) {
  return Number(v) === 1 ? tl('option_yes') : tl('option_no');
}
function limitText(v?: number | string) {
  return Number(v) > 0 ? String(v) : tl('unlimited');
}

/* ───────── 隨機獎勵 ───────── */
const isRandom = computed(() => Number(detail.value.reward_mode) === 2);
const prizeList = computed(() => detail.value.prizes ?? []);
const totalWeight = computed(() =>
  prizeList.value.reduce((sum, p) => sum + (Number(p.weight) || 0), 0),
);
function prizeProbability(p: { weight?: number | string }) {
  const w = Number(p.weight) || 0;
  return totalWeight.value > 0
    ? `${((w / totalWeight.value) * 100).toFixed(2)}%`
    : '0.00%';
}
function prizeQuantityText(p: {
  awarded?: number | string;
  quantity?: number | string;
}) {
  return Number(p.quantity) > 0
    ? `${Number(p.awarded) || 0} / ${p.quantity}`
    : tl('unlimited');
}

const languages = ref<Array<{ code: string; id: number; name: string }>>([]);
const activeImageCards = ref<string[]>([]);
const savingImages = ref(false);
// 對齊 prizeList 的可編輯圖片：[{ id, images: { langId: url } }]
const prizeImages = ref<
  Array<{ id: number | string; images: Record<string, string> }>
>([]);

/** 把詳細回傳的 images（陣列）轉成 { langId: url } 方便編輯。 */
function buildPrizeImages() {
  prizeImages.value = prizeList.value.map((p) => {
    const map: Record<string, string> = {};
    const imgs = p.images;
    if (Array.isArray(imgs)) {
      for (const img of imgs) map[String(img.language_id)] = img.image_url;
    }
    return { id: p.id ?? '', images: map };
  });
}

async function onDetailPrizeImageFile(
  e: Event,
  idx: number,
  langId: number | string,
) {
  const input = e.target as HTMLInputElement;
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
  } finally {
    input.value = '';
  }
  const res = await uploadCouponImageApi(base64);
  const target = prizeImages.value[idx];
  if (Number(res.code) === 1000 && res.data?.path && target) {
    target.images[String(langId)] = res.data.path;
  } else {
    ElMessage.error(res.message || tl('failure'));
  }
}

async function saveImages() {
  savingImages.value = true;
  try {
    const res = await updateCouponApi({
      id: detailId.value,
      prizes: prizeImages.value.map((p) => ({
        amount: 0,
        flow_limit_multiple: 0,
        id: p.id,
        images: Object.fromEntries(
          Object.entries(p.images).filter(([, url]) => url),
        ),
        point_type: 0,
        quantity: 0,
        weight: 0,
      })),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      loadData();
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    savingImages.value = false;
  }
}

const totalAmount = computed(
  () => Number(detail.value.amount ?? 0) * Number(detail.value.quantity ?? 0),
);

async function loadData() {
  loading.value = true;
  try {
    const [v, c] = await Promise.all([
      getCouponViewApi(detailId.value),
      getCouponCodesApi(detailId.value),
    ]);
    if (Number(v.code) === 1000 && v.data) detail.value = v.data;
    if (Number(c.code) === 1000) {
      codes.value = [...(c.data ?? [])].toSorted((a, b) =>
        String(b.used_at ?? '').localeCompare(String(a.used_at ?? '')),
      );
    }
    if (isRandom.value) {
      buildPrizeImages();
      if (languages.value.length === 0) {
        const langs = await getLanguagesApi();
        if (Array.isArray(langs)) languages.value = langs;
      }
    }
  } finally {
    loading.value = false;
  }
}

async function toggleStatus() {
  const next = Number(detail.value.status) === 1 ? 0 : 1;
  const res = await toggleCouponStatusApi({ id: detailId.value, status: next });
  if (Number(res.code) === 1000) {
    ElMessage.success(tl('success'));
    loadData();
    emit('success');
  } else {
    ElMessage.error(res.message);
  }
}

const exporting = ref(false);
/** 匯出 Excel：欄位（4 欄；兌換碼明細） */
async function handleExport() {
  exporting.value = true;
  try {
    await exportExcel({
      audit: { conditions: { id: detailId.value } },
      columns: [
        { title: tl('code'), width: 30 },
        { title: tl('member'), width: 20 },
        { title: tl('mobile'), width: 20 },
        { title: tl('exchange_time'), width: 30 },
      ],
      rows: codes.value.map((r) => [
        r.code,
        r.member_username,
        r.mobile,
        r.used_at,
      ]),
      title: `${detail.value.batch_code ?? ''} ${detail.value.start_time ?? ''}~${detail.value.end_time ?? ''}`,
    });
  } finally {
    exporting.value = false;
  }
}

function open(id: number | string) {
  detailId.value = String(id ?? '');
  detail.value = { id: detailId.value };
  codes.value = [];
  keyword.value = '';
  page.value = 1;
  visible.value = true;
  loadData();
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_coupon')} - ${detail.batch_code ?? ''}`"
    top="5vh"
    width="1000"
  >
    <ElCard v-loading="loading" class="mb-3" shadow="never">
      <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('agent_account') }}
          </div>
          <div>{{ detail.agent_username }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('apply_to_all_downline') }}
          </div>
          <div>{{ yesNo(detail.all_downline) }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('reward_mode') }}
          </div>
          <div>
            {{ isRandom ? tl('random_reward') : tl('fixed_amount_reward') }}
          </div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">{{ tl('type') }}</div>
          <div>{{ isRandom ? '-' : typeText(detail.type) }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('denomination') }}
          </div>
          <div>{{ isRandom ? tl('random_reward') : detail.amount }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('event_flow_limit_multiple') }}
          </div>
          <div>{{ detail.flow_limit_multiple }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('member_claim_limit') }}
          </div>
          <div>{{ limitText(detail.receive_limit) }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('mobile_daily_claim_limit') }}
          </div>
          <div>{{ limitText(detail.mobile_daily_limit) }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('block_duplicate_realname') }}
          </div>
          <div>{{ yesNo(detail.realname_required) }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('created_time') }}
          </div>
          <div>{{ detail.created_at }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">{{ tl('between') }}</div>
          <div>{{ detail.start_time }} ~ {{ detail.end_time }}</div>
        </div>
        <div>
          <div class="text-muted-foreground text-xs">
            {{ tl('status') }}
            <ElButton link size="small" @click="toggleStatus">↻</ElButton>
          </div>
          <div>
            {{ Number(detail.status) === 1 ? tl('enabled') : tl('disabled') }}
          </div>
        </div>
      </div>
    </ElCard>

    <div class="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4">
      <ElCard shadow="never">
        <div class="text-muted-foreground text-xs">{{ tl('quantity') }}</div>
        <div class="text-lg font-bold">{{ detail.quantity }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-xs">{{ tl('received') }}</div>
        <div class="text-lg font-bold">{{ detail.used_count }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-xs">{{ tl('not_receive') }}</div>
        <div class="text-lg font-bold">{{ detail.unused_count }}</div>
      </ElCard>
      <ElCard shadow="never">
        <div class="text-muted-foreground text-xs">
          {{ tl('total_amount') }}
        </div>
        <div class="text-lg font-bold">{{ isRandom ? '-' : totalAmount }}</div>
      </ElCard>
    </div>

    <!-- 隨機獎勵：獎池 + 多語系圖片 -->
    <template v-if="isRandom">
      <ElCard class="mb-3" shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('random_reward') }}</span>
        </template>
        <ElTable :data="prizeList" border size="small" stripe>
          <ElTableColumn
            :formatter="(row) => typeText(row.point_type)"
            :label="tl('type')"
            width="120"
          />
          <ElTableColumn :label="tl('prize_amount')" prop="amount" />
          <ElTableColumn :label="tl('prize_weight')" prop="weight" />
          <ElTableColumn :label="tl('win_probability')">
            <template #default="{ row }">{{ prizeProbability(row) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="tl('prize_quantity')">
            <template #default="{ row }">{{ prizeQuantityText(row) }}</template>
          </ElTableColumn>
          <ElTableColumn
            :label="tl('event_flow_limit_multiple')"
            prop="flow_limit_multiple"
          />
          <template #empty>{{ tl('no_record') }}</template>
        </ElTable>
      </ElCard>

      <ElCard v-if="prizeImages.length > 0" class="mb-3" shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-medium">
              {{ tl('random_reward') }} - {{ tl('image') }}
            </span>
            <ElButton
              :loading="savingImages"
              size="small"
              type="primary"
              @click="saveImages"
            >
              {{ tl('save') }}
            </ElButton>
          </div>
        </template>
        <ElCollapse v-model="activeImageCards">
          <ElCollapseItem
            v-for="(p, idx) in prizeImages"
            :key="idx"
            :name="String(idx)"
            :title="`#${idx + 1} ${typeText(prizeList[idx]?.point_type)} ${prizeList[idx]?.amount ?? ''}`"
          >
            <div
              v-for="lang in languages"
              :key="lang.id"
              class="mb-2 flex items-center gap-2"
            >
              <span class="w-20 shrink-0 text-sm">{{ lang.name }}</span>
              <ElInput
                v-model="p.images[String(lang.id)]"
                :placeholder="`${tl('image')} URL`"
              />
              <label class="el-button el-button--small cursor-pointer">
                {{ tl('upload_image') }}
                <input
                  :accept="IMAGE_ACCEPT"
                  class="hidden"
                  type="file"
                  @change="onDetailPrizeImageFile($event, idx, lang.id)"
                />
              </label>
              <img
                v-if="p.images[String(lang.id)]"
                :src="p.images[String(lang.id)]"
                class="h-12 rounded border"
              />
            </div>
            <div class="text-muted-foreground mt-1 text-xs">
              {{ imageUploadText('image_upload_hint') }}
            </div>
          </ElCollapseItem>
        </ElCollapse>
      </ElCard>
    </template>

    <ElCard shadow="never">
      <div class="mb-2 flex items-center justify-between gap-2">
        <ElInput
          v-model="keyword"
          :placeholder="tl('search')"
          class="!w-56"
          clearable
          @input="handleQuickSearch"
        />
        <ElButton
          :disabled="codes.length === 0"
          :loading="exporting"
          type="success"
          @click="handleExport"
        >
          {{ tl('excel') }}
        </ElButton>
      </div>
      <ElTable
        v-loading="loading"
        :data="pagedCodes"
        border
        size="small"
        stripe
        @sort-change="handleSortChange"
      >
        <ElTableColumn
          :label="tl('code')"
          prop="code"
          min-width="140"
          sortable="custom"
        />
        <ElTableColumn :label="tl('member')" min-width="120" sortable="custom">
          <template #default="{ row }">
            <MemberAccountLink :username="row.member_username" />
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="tl('mobile')"
          prop="mobile"
          min-width="120"
          sortable="custom"
        />
        <ElTableColumn
          :label="tl('exchange_time')"
          prop="used_at"
          sortable="custom"
          width="170"
        />
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
      <TablePagination
        :current-page="page"
        :page-size="PER_PAGE"
        :total="total"
        @current-change="handlePageChange"
      />
    </ElCard>
  </ElDialog>
</template>
