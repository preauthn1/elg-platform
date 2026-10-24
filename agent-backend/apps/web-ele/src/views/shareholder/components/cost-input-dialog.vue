<script lang="ts" setup>
import type { ShareholderCostRow } from '#/api';

import { ref, watch } from 'vue';

import { ElButton, ElDialog, ElInput } from 'element-plus';

import { tl } from '#/locales';

/** 輸入成本／連桶結算共用的成本輸入視窗：輸入正數（可含小數）時即時顯示為負值，0 或空白維持原樣。 */
defineOptions({ name: 'ShareholderCostInputDialog' });

interface CostInputResult {
  cost: number;
  memo: string;
}

const visible = ref(false);
const row = ref<null | ShareholderCostRow>(null);
const costInput = ref('');
const memo = ref('');
let resolveFn: ((result: CostInputResult | null) => void) | null = null;

/**
 * 輸入正數即時顯示為負值，0 或空白維持原樣，小數點正常輸入。
 * 與 sub-site-cost-dialog.vue 的 normalizeSiteCost() 同一套規則，兩邊行為必須一致。
 *
 * 🔴 禁止改回 `\D` 全砍（target/op_tickets#3247）：那會連小數點一起吃掉，使用者
 * 輸入 12.5 會被改成 -125——不只是「打不出小數點」，金額還被放大 10 倍。
 */
function normalizeCost(raw: string): string {
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

watch(costInput, (val) => {
  const normalized = normalizeCost(val);
  if (normalized !== val) costInput.value = normalized;
});

function open(target: ShareholderCostRow) {
  row.value = target;
  costInput.value = '';
  memo.value = '';
  visible.value = true;
  return new Promise<CostInputResult | null>((resolve) => {
    resolveFn = resolve;
  });
}

function finish(result: CostInputResult | null) {
  visible.value = false;
  resolveFn?.(result);
  resolveFn = null;
}

function onConfirm() {
  const n = Number(costInput.value || '0');
  finish({ cost: Number.isFinite(n) ? n : 0, memo: memo.value });
}

function onCancel() {
  finish(null);
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${row?.summary_time ?? ''} ${tl('cost')}`"
    width="420px"
    @close="onCancel"
  >
    <div class="space-y-3 text-sm">
      <div class="flex items-center justify-between gap-3">
        <span class="whitespace-nowrap">{{ tl('cost') }}</span>
        <ElInput v-model="costInput" class="!w-44" inputmode="decimal" />
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
      <ElButton @click="onCancel">{{ tl('cancel') }}</ElButton>
      <ElButton type="primary" @click="onConfirm">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
