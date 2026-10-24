<script lang="ts" setup>
import type { EventProgressiveJackpotTierPayload } from '#/api';

import { reactive, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElInput,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import { tl } from '#/locales';

defineOptions({ name: 'EventProgressiveJackpotTierEditDialog' });

const emit = defineEmits<{
  confirm: [index: number, tier: EventProgressiveJackpotTierPayload];
}>();

const visible = ref(false);
const editingIndex = ref(-1);
/** 首儲檔最多一筆，已存在（且不是正在編輯的那一筆）就不能再選 */
const firstDepositTaken = ref(false);

const form = reactive({
  deposit_point: 0 as number | string,
  deposit_rule: 'accumulate' as 'accumulate' | 'first',
  expire_day: 0 as number | string,
  max_reward: 0 as number | string,
  no_limit: true,
  reward: 0 as number | string,
  /** 畫面輸入的是百分比數字（200 代表 200%） */
  reward_percentage: 0 as number | string,
  reward_type: 1 as number | string,
});

function open(
  index: number,
  tier?: EventProgressiveJackpotTierPayload,
  taken = false,
) {
  editingIndex.value = index;
  firstDepositTaken.value = taken;
  form.deposit_rule = tier?.is_first_deposit ? 'first' : 'accumulate';
  form.deposit_point = tier?.deposit_point ?? 0;
  form.reward_type = Number(tier?.reward_type ?? 1);
  form.reward = tier?.reward ?? 0;
  form.reward_percentage = (tier?.reward_percentage ?? 0) * 100;
  form.max_reward = tier?.max_reward ?? 0;
  form.expire_day = tier?.expire_day ?? 0;
  form.no_limit = !tier?.expire_day;
  visible.value = true;
}

function confirm() {
  const isFirst = form.deposit_rule === 'first';
  emit('confirm', editingIndex.value, {
    deposit_point: isFirst ? 0 : Number(form.deposit_point) || 0,
    expire_day: form.no_limit ? 0 : Number(form.expire_day) || 0,
    is_first_deposit: isFirst,
    max_reward: Number(form.max_reward) || 0,
    reward: Number(form.reward) || 0,
    reward_percentage: (Number(form.reward_percentage) || 0) / 100,
    reward_type: Number(form.reward_type),
  });
  visible.value = false;
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="tl('event_progressive_jackpot_tier_setting')"
    width="480px"
  >
    <div class="mb-3">
      <div class="text-muted-foreground mb-1 text-xs">
        {{ tl('event_progressive_jackpot_deposit_rule') }}
      </div>
      <ElSelect v-model="form.deposit_rule" class="!w-full">
        <ElOption
          :label="tl('event_progressive_jackpot_deposit_rule_accumulate')"
          value="accumulate"
        />
        <ElOption
          :disabled="firstDepositTaken"
          :label="tl('event_action.first_deposit')"
          value="first"
        />
      </ElSelect>
    </div>

    <div v-if="form.deposit_rule !== 'first'" class="mb-3">
      <div class="text-muted-foreground mb-1 text-xs">
        {{ tl('event_progressive_jackpot_threshold') }}
      </div>
      <ElInput v-model="form.deposit_point" />
    </div>

    <div class="mb-3">
      <div class="text-muted-foreground mb-1 text-xs">
        {{ tl('reward_type') }}
      </div>
      <ElRadioGroup v-model="form.reward_type">
        <ElRadio :value="1">{{ tl('fixed_amount_reward') }}</ElRadio>
        <ElRadio :value="2">{{ tl('tax_type_percentage') }}</ElRadio>
      </ElRadioGroup>
    </div>

    <div v-if="Number(form.reward_type) === 1" class="mb-3">
      <div class="text-muted-foreground mb-1 text-xs">
        {{ tl('event_progressive_jackpot_reward_amount') }}
      </div>
      <ElInput v-model="form.reward" />
    </div>

    <template v-else>
      <div class="mb-3">
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('event_progressive_jackpot_reward_multiplier') }}
        </div>
        <ElInput v-model="form.reward_percentage">
          <template #append>%</template>
        </ElInput>
      </div>
      <div class="mb-3">
        <div class="text-muted-foreground mb-1 text-xs">
          {{ tl('max_amount') }}
        </div>
        <ElInput v-model="form.max_reward" />
      </div>
    </template>

    <div>
      <div class="text-muted-foreground mb-1 text-xs">
        {{ tl('event_progressive_jackpot_time_limit') }}
      </div>
      <ElCheckbox
        v-model="form.no_limit"
        :label="tl('event_progressive_jackpot_no_limit')"
        class="mb-2"
      />
      <ElInput
        v-model="form.expire_day"
        :disabled="form.no_limit"
        :placeholder="tl('days')"
      />
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton type="primary" @click="confirm">{{ tl('confirm') }}</ElButton>
    </template>
  </ElDialog>
</template>
