<script lang="ts" setup>
import type { TierErrors, VendorDetailForm } from '../form-model';

import { computed, reactive, ref, watch } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElInput,
  ElInputNumber,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTag,
  ElTooltip,
} from 'element-plus';

import { getGgrGameOptionsApi } from '#/api';
import { tl } from '#/locales';

import { MAX_AMOUNT } from '../../validation';
import { makeDefaultTier, tierErrors } from '../form-model';

/**
 * 單一遊戲類型 tab 的明細表（2026-08-31 工單重寫版）。
 *
 * 每列＝vendor_info × game_type 組合，內含多階層（⭐工單主需求）：
 * 遊戲商／排除遊戲／狀態對同遊戲商所有階層共用（跨列合併，只在首階顯示）；
 * 「計算金額>=」為 ElInputNumber（＋/− 步進）；「最低輸贏／最大GGR／加權」
 * 三欄平鋪於「遊戲總收入」群組表頭下；階層操作（＋新增階層／刪除）在列尾，
 * 第一階無刪除鈕。無「遊戲組」欄（newabo 無此維度，類別已由 tab 表達）。
 * rows 為父層 reactive 物件的過濾視圖，直接就地修改。
 */

const props = defineProps<{ rows: VendorDetailForm[] }>();

const vendorFilter = ref('');

const filteredRows = computed(() => {
  const keyword = vendorFilter.value.trim().toLowerCase();
  if (!keyword) return props.rows;
  return props.rows.filter((row) =>
    row.vendorInfoName.toLowerCase().includes(keyword),
  );
});

/** 排除遊戲下拉：展開時才逐列 lazy load（最大組合上千款，不能全量預載） */
const gameOptionsCache = reactive(
  new Map<string, { id: number; name: string }[]>(),
);
const gameOptionsLoading = reactive(new Set<string>());

function optionsKey(row: VendorDetailForm) {
  return `${row.vendorId}-${row.gameTypeId}`;
}

async function loadGameOptions(row: VendorDetailForm, opened: boolean) {
  const key = optionsKey(row);
  if (!opened || gameOptionsCache.has(key) || gameOptionsLoading.has(key)) {
    return;
  }
  gameOptionsLoading.add(key);
  try {
    const res = await getGgrGameOptionsApi({
      game_type_id: row.gameTypeId,
      vendor_id: row.vendorId,
    });
    if (Number(res.code) === 200) {
      gameOptionsCache.set(key, res.data ?? []);
    }
  } finally {
    gameOptionsLoading.delete(key);
  }
}

/**
 * 編輯/複製回填時，已選排除遊戲的列要先取得選項，否則 ElSelect 只有 id
 * 沒有對應 option，畫面會顯示原始編號而非遊戲名稱。
 * 只預載「有選取值」的列，其餘維持展開才載入。
 */
watch(
  () => props.rows,
  (rows) => {
    for (const row of rows) {
      if (row.excludedGameIds.length > 0) {
        loadGameOptions(row, true);
      }
    }
  },
  { immediate: true },
);

/** 全選/取消全選當列的排除遊戲（選項未載入時不動作） */
function toggleSelectAll(row: VendorDetailForm) {
  const options = gameOptionsCache.get(optionsKey(row)) ?? [];
  row.excludedGameIds =
    row.excludedGameIds.length === options.length && options.length > 0
      ? []
      : options.map((game) => game.id);
}

/** 複製第一列設定＝把第一個遊戲商的整組階層（含階數與各階值）複製到其餘列。
 * 以 filteredRows 為準而非 props.rows：篩選中時只動看得見的列，
 * 否則被篩掉的數十家遊戲商會在沒有提示、也沒得復原的情況下被覆寫。
 * 作用範圍是當前分頁（rows 即當前 tab 的列）；排除遊戲與狀態屬各組合自身，不隨複製覆蓋。 */
function applyBatch() {
  const rows = filteredRows.value;
  const first = rows[0];
  if (!first) return;
  for (const row of rows) {
    if (row === first) continue;
    row.tiers = first.tiers.map((tier) => ({ ...tier }));
  }
}

function addTier(row: VendorDetailForm) {
  row.tiers.push(makeDefaultTier());
}

function removeTier(row: VendorDetailForm, index: number) {
  row.tiers.splice(index, 1);
}

/** 勾「無最低限制／無上限」就把該欄歸 0——0 即不限制，欄位值與勾選狀態必須一致 */
function syncUnlimited(
  tier: VendorDetailForm['tiers'][number],
  field: 'maxGgr' | 'minWinlose',
) {
  const checked = field === 'maxGgr' ? tier.noCap : tier.noMinLimit;
  if (checked) tier[field] = '0';
}

function errorsAt(row: VendorDetailForm, index: number): TierErrors {
  const tier = row.tiers[index];
  if (!tier) return {};
  const prev =
    index === 0 ? null : (row.tiers[index - 1]?.thresholdAmount ?? null);
  return tierErrors(tier, prev);
}
</script>

<template>
  <div>
    <div class="mb-3 flex items-center justify-between gap-2">
      <div class="flex items-center gap-2">
        <span class="text-sm">{{ tl('label_vendor') }}:</span>
        <ElInput
          v-model="vendorFilter"
          :placeholder="tl('please_enter')"
          class="!w-48"
          clearable
        />
        <ElButton @click="vendorFilter = ''">{{ tl('clear') }}</ElButton>
      </div>
      <ElTooltip :content="tl('ggr_batch_set_hint')" placement="top">
        <ElButton type="success" @click="applyBatch">
          {{ tl('ggr_batch_set') }}
        </ElButton>
      </ElTooltip>
    </div>

    <!-- 兩層表頭：上層「遊戲總收入」群組跨三欄，下層各欄名 -->
    <div
      class="border-border bg-muted text-muted-foreground grid grid-cols-[150px_200px_60px_190px_minmax(390px,1fr)_110px] items-center gap-2 rounded-t border border-b-0 px-3 pt-2 text-sm font-medium"
    >
      <span></span>
      <span></span>
      <span></span>
      <span></span>
      <span class="border-border border-b pb-1 text-center">
        {{ tl('ggr_game_revenue') }}
      </span>
      <span></span>
    </div>
    <div
      class="border-border bg-muted text-muted-foreground grid grid-cols-[150px_200px_60px_190px_minmax(390px,1fr)_110px] items-center gap-2 border-x px-3 pb-2 pt-1 text-sm font-medium"
    >
      <span>{{ tl('ggr_vendor_provider') }}</span>
      <span>{{ tl('ggr_excluded_games') }}</span>
      <span>{{ tl('status') }}</span>
      <!-- 三個門檻的 tooltip 必須寫明加權前/後（工單欄位定義段），不能只寫「最低值」 -->
      <span class="flex items-center gap-1">
        {{ tl('ggr_threshold_amount') }}
        <ElTooltip :content="tl('ggr_threshold_hint')" placement="top">
          <span class="cursor-help text-xs">ⓘ</span>
        </ElTooltip>
      </span>
      <span class="grid grid-cols-3 gap-2">
        <span class="flex items-center gap-1">
          {{ tl('ggr_min_winlose') }}
          <ElTooltip :content="tl('ggr_zero_no_min_hint')" placement="top">
            <span class="cursor-help text-xs">ⓘ</span>
          </ElTooltip>
        </span>
        <span class="flex items-center gap-1">
          {{ tl('ggr_max_ggr') }}
          <ElTooltip :content="tl('ggr_zero_no_cap_hint')" placement="top">
            <span class="cursor-help text-xs">ⓘ</span>
          </ElTooltip>
        </span>
        <span>{{ tl('ggr_weight_rate') }}</span>
      </span>
      <span></span>
    </div>

    <div class="border-border rounded-b border">
      <div v-if="filteredRows.length === 0" class="p-6 text-center text-sm">
        {{ tl('no_record') }}
      </div>

      <div
        v-for="row in filteredRows"
        :key="`${row.vendorInfoId}-${row.gameTypeId}`"
        class="border-border border-b last:border-b-0"
      >
        <div
          v-for="(tier, tierIndex) in row.tiers"
          :key="tierIndex"
          class="grid grid-cols-[150px_200px_60px_190px_minmax(390px,1fr)_110px] items-start gap-2 px-3 py-3"
        >
          <!-- 首階帶遊戲商/排除遊戲/狀態（同遊戲商共用、跨列合併）；子階只顯示階層標籤 -->
          <template v-if="tierIndex === 0">
            <span class="break-all pt-1 text-sm">
              {{ row.vendorInfoName }}
              <ElTag v-if="row.isNew" class="ml-1" size="small" type="success">
                {{ tl('ggr_new_badge') }}
              </ElTag>
            </span>
            <ElSelect
              v-model="row.excludedGameIds"
              :loading="gameOptionsLoading.has(optionsKey(row))"
              :placeholder="tl('please_choose')"
              class="!w-full"
              filterable
              multiple
              @visible-change="(opened) => loadGameOptions(row, opened)"
            >
              <!-- 收合時只顯示「已選 N 項」，不塞滿標籤撐高列；
                   未選時什麼都不輸出，否則會與 placeholder 疊在同一格 -->
              <template #tag>
                <span
                  v-if="row.excludedGameIds.length > 0"
                  class="px-1 text-sm"
                >
                  {{ tl('ggr_selected_prefix') }}
                  {{ row.excludedGameIds.length }}
                  {{ tl('ggr_selected_suffix') }}
                </span>
              </template>
              <template #header>
                <ElCheckbox
                  :model-value="
                    row.excludedGameIds.length > 0 &&
                    row.excludedGameIds.length ===
                      (gameOptionsCache.get(optionsKey(row)) ?? []).length
                  "
                  @change="toggleSelectAll(row)"
                >
                  {{ tl('select_all') }}
                </ElCheckbox>
              </template>
              <ElOption
                v-for="game in gameOptionsCache.get(optionsKey(row)) ?? []"
                :key="game.id"
                :label="game.name"
                :value="game.id"
              />
            </ElSelect>
            <div class="pt-1"><ElSwitch v-model="row.status" /></div>
          </template>
          <template v-else>
            <span class="pt-1 text-sm">
              {{ tl('ggr_tier_level') }} {{ tierIndex + 1 }}
            </span>
            <span></span>
            <span></span>
          </template>

          <!-- 計算金額>=：ElInputNumber 步進（0=不限制，逐階遞增） -->
          <div>
            <ElInputNumber
              v-model="tier.thresholdAmount"
              :max="MAX_AMOUNT"
              :min="0"
              :precision="2"
              :value-on-clear="0"
              class="!w-full"
            />
            <div
              v-if="errorsAt(row, tierIndex).thresholdAmount"
              class="text-destructive mt-1 text-xs"
            >
              {{ errorsAt(row, tierIndex).thresholdAmount }}
            </div>
          </div>

          <!-- 遊戲總收入三欄平鋪：最低輸贏/最大GGR/加權 -->
          <div class="grid grid-cols-3 gap-2">
            <div>
              <ElInput v-model="tier.minWinlose" :disabled="tier.noMinLimit" />
              <div class="mt-1 flex items-center gap-1">
                <ElCheckbox
                  v-model="tier.noMinLimit"
                  size="small"
                  @change="syncUnlimited(tier, 'minWinlose')"
                >
                  {{ tl('ggr_no_min_limit') }}
                </ElCheckbox>
                <ElTooltip
                  :content="tl('ggr_zero_no_min_hint')"
                  placement="top"
                >
                  <span class="text-muted-foreground cursor-help text-xs">
                    ⓘ
                  </span>
                </ElTooltip>
              </div>
              <div
                v-if="errorsAt(row, tierIndex).minWinlose"
                class="text-destructive mt-1 text-xs"
              >
                {{ errorsAt(row, tierIndex).minWinlose }}
              </div>
            </div>
            <div>
              <ElInput v-model="tier.maxGgr" :disabled="tier.noCap" />
              <div class="mt-1 flex items-center gap-1">
                <ElCheckbox
                  v-model="tier.noCap"
                  size="small"
                  @change="syncUnlimited(tier, 'maxGgr')"
                >
                  {{ tl('ggr_no_cap') }}
                </ElCheckbox>
                <ElTooltip
                  :content="tl('ggr_zero_no_cap_hint')"
                  placement="top"
                >
                  <span class="text-muted-foreground cursor-help text-xs">
                    ⓘ
                  </span>
                </ElTooltip>
              </div>
              <div
                v-if="errorsAt(row, tierIndex).maxGgr"
                class="text-destructive mt-1 text-xs"
              >
                {{ errorsAt(row, tierIndex).maxGgr }}
              </div>
            </div>
            <div>
              <ElInput v-model="tier.weightRate" />
              <div
                v-if="errorsAt(row, tierIndex).weightRate"
                class="text-destructive mt-1 text-xs"
              >
                {{ errorsAt(row, tierIndex).weightRate }}
              </div>
            </div>
          </div>

          <!-- 階層操作：每階可加階，第一階不可刪（工單需求 1） -->
          <div class="flex flex-col items-start gap-1 pt-1">
            <ElButton link size="small" type="primary" @click="addTier(row)">
              ＋ {{ tl('ggr_add_tier') }}
            </ElButton>
            <ElButton
              v-if="tierIndex > 0"
              class="!ml-0"
              link
              size="small"
              type="danger"
              @click="removeTier(row, tierIndex)"
            >
              {{ tl('delete') }}
            </ElButton>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
