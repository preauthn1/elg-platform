<script lang="ts" setup>
import type { ShareholderCostRow } from '#/api';

import { computed, ref, watch } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElMessage,
  ElMessageBox,
} from 'element-plus';

import { shareholderV3SetSubSiteCostApi } from '#/api';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';

/** 稱桶子站輸入成本（狀態 7）：主站成本唯讀，輸入子站成本並即時試算總成本。 */
defineOptions({ name: 'ShareholderSubSiteCostDialog' });

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const submitting = ref(false);
const row = ref<null | ShareholderCostRow>(null);
const siteCostInput = ref('');
const memo = ref('');

const mainCost = computed(() => Number(row.value?.game_profit ?? 0));
const siteCost = computed(() => {
  const n = Number(siteCostInput.value);
  return Number.isFinite(n) ? n : 0;
});
const totalCost = computed(() => mainCost.value + siteCost.value);

/** 輸入正數即時顯示為負值，0 或空白維持原樣，小數點正常輸入。 */
function normalizeSiteCost(raw: string): string {
  let cleaned = raw.replaceAll(/[^0-9.]/g, '');
  const firstDot = cleaned.indexOf('.');
  if (firstDot !== -1) {
    cleaned =
      cleaned.slice(0, firstDot + 1) +
      cleaned.slice(firstDot + 1).replaceAll('.', '');
  }
  const isZeroish = cleaned === '' || /^0*\.?0*$/.test(cleaned);
  return isZeroish ? cleaned : `-${cleaned}`;
}

watch(siteCostInput, (val) => {
  const normalized = normalizeSiteCost(val);
  if (normalized !== val) siteCostInput.value = normalized;
});

function open(target: ShareholderCostRow) {
  row.value = target;
  siteCostInput.value = '';
  memo.value = '';
  visible.value = true;
}

async function submit() {
  if (siteCostInput.value.trim() === '') {
    ElMessage.warning(tl('required'));
    return;
  }
  const current = row.value;
  if (!current) return;
  try {
    await ElMessageBox.confirm(
      `${tl('main_site_cost')}: ${amountThousands(mainCost.value, 4)}\n` +
        `${tl('site_cost')}: ${amountThousands(siteCost.value, 4)}\n` +
        `${tl('total_cost')}: ${amountThousands(totalCost.value, 4)}\n` +
        `${tl('memo')}: ${memo.value}`,
      tl('are_you_sure'),
      { type: 'info' },
    );
  } catch {
    return;
  }
  submitting.value = true;
  try {
    const res = await shareholderV3SetSubSiteCostApi({
      date: current.summary_time ?? '',
      id: current.id,
      memo: memo.value,
      payout_record_id: current.payout_record_id ?? '',
      site_cost: siteCost.value,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    submitting.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${row?.summary_time ?? ''} ${tl('edit_cost')}`"
    width="460px"
  >
    <div class="space-y-3 text-sm">
      <div class="flex items-center justify-between">
        <span>{{ tl('main_site_cost') }}</span>
        <strong>{{ amountThousands(mainCost, 4) }}</strong>
      </div>
      <div class="flex items-center justify-between gap-3">
        <span class="whitespace-nowrap">{{ tl('site_cost') }}</span>
        <ElInput v-model="siteCostInput" class="!w-44" inputmode="decimal" />
      </div>
      <hr class="border-border" />
      <div class="flex items-center justify-between">
        <span>{{ tl('total_cost') }}</span>
        <strong>{{ amountThousands(totalCost, 4) }}</strong>
      </div>
      <div>
        <div class="mb-1">
          {{ tl('memo') }}
          <span class="text-muted-foreground text-xs">
            （{{ tl('limit_character').replace('{text}', '100') }}）
          </span>
        </div>
        <ElInput v-model="memo" :rows="3" maxlength="100" type="textarea" />
      </div>
    </div>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
