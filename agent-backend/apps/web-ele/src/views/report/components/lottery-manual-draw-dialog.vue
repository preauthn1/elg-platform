<script lang="ts" setup>
import type { LotteryPeriodRow, LotteryVariant } from '#/api';

import { computed, reactive, ref } from 'vue';

import { ElButton, ElDialog, ElInput, ElMessage } from 'element-plus';

import { lotteryManualDrawApi } from '#/api';
import { tl } from '#/locales';

const props = defineProps<{ variant: LotteryVariant }>();
const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
/** 0=輸入號碼、1=確認資訊 */
const step = ref(0);
const submitting = ref(false);
const period = ref<LotteryPeriodRow | null>(null);

// sr16: 6 碼 + 1 碼
const sr16 = reactive({ a: '', b: '' });
// sr17_6d: 單一 6 碼
const sixd = reactive({ a: '' });
// sr17: 一/二/三獎各 4 碼 + 特別獎 10×4 碼 + 安慰獎 10×4 碼
const wj = reactive({
  rno_1: '',
  rno_2: '',
  rno_3: '',
  rno_4: Array.from({ length: 10 }, () => ''),
  rno_5: Array.from({ length: 10 }, () => ''),
});

const periodTitle = computed(() =>
  period.value ? tl('periods').replace('{N}', String(period.value.no)) : '',
);

function reset() {
  step.value = 0;
  sr16.a = '';
  sr16.b = '';
  sixd.a = '';
  wj.rno_1 = '';
  wj.rno_2 = '';
  wj.rno_3 = '';
  wj.rno_4 = Array.from({ length: 10 }, () => '');
  wj.rno_5 = Array.from({ length: 10 }, () => '');
}

function open(row: LotteryPeriodRow) {
  period.value = row;
  reset();
  visible.value = true;
}

/** 驗證輸入格式，通過則進到確認步驟 */
function toConfirm() {
  if (props.variant === 'sr16') {
    if (!/^\d{6}$/.test(sr16.a) || !/^\d$/.test(sr16.b)) {
      ElMessage.error('format error');
      return;
    }
  } else if (props.variant === 'sr17_6d') {
    if (!/^\d{6}$/.test(sixd.a)) {
      ElMessage.error('format error');
      return;
    }
  } else {
    const all = [wj.rno_1, wj.rno_2, wj.rno_3, ...wj.rno_4, ...wj.rno_5];
    if (all.some((v) => !/^\d{4}$/.test(v))) {
      ElMessage.error('format error');
      return;
    }
  }
  step.value = 1;
}

function buildPayload(): Record<string, any> {
  const period_id = period.value?.id;
  if (props.variant === 'sr16') {
    return { period_id, period_no: `${sr16.a}-${sr16.b}` };
  }
  if (props.variant === 'sr17_6d') {
    return { period_id, period_no: sixd.a };
  }
  // sr17：特別獎/安慰獎以逗號串拼接送出
  return {
    period_id,
    period_no: wj.rno_1,
    period_no_2: wj.rno_2,
    period_no_3: wj.rno_3,
    period_no_4: wj.rno_4.join(','),
    period_no_5: wj.rno_5.join(','),
  };
}

async function submit() {
  submitting.value = true;
  try {
    const res = await lotteryManualDrawApi(props.variant, buildPayload());
    if (Number(res.code) === 1000) {
      ElMessage.success(`${tl('manual_draw')} ${tl('success')}`);
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(`${tl('manual_draw')} ${tl('failure')}`);
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
    :title="`${periodTitle} ${tl('manual_draw')}`"
    width="640"
  >
    <!-- 步驟一：輸入號碼 -->
    <div v-if="step === 0">
      <!-- sr16：6 碼 + 1 碼 -->
      <div v-if="variant === 'sr16'" class="flex items-center gap-2">
        <ElInput v-model="sr16.a" maxlength="6" style="width: 160px" />
        <span>—</span>
        <ElInput v-model="sr16.b" maxlength="1" style="width: 70px" />
      </div>

      <!-- sr17_6d：單一 6 碼 -->
      <div v-else-if="variant === 'sr17_6d'">
        <ElInput v-model="sixd.a" maxlength="6" style="width: 160px" />
      </div>

      <!-- sr17：一二三獎 + 特別獎 + 安慰獎 -->
      <div v-else class="space-y-3">
        <div class="flex items-center gap-3">
          <span class="w-28">{{ tl('first_prize') }}</span>
          <ElInput v-model="wj.rno_1" maxlength="4" style="width: 120px" />
        </div>
        <div class="flex items-center gap-3">
          <span class="w-28">{{ tl('second_prize') }}</span>
          <ElInput v-model="wj.rno_2" maxlength="4" style="width: 120px" />
        </div>
        <div class="flex items-center gap-3">
          <span class="w-28">{{ tl('third_prize') }}</span>
          <ElInput v-model="wj.rno_3" maxlength="4" style="width: 120px" />
        </div>
        <div>
          <div class="mb-1">{{ tl('special_prize') }}</div>
          <div class="grid grid-cols-5 gap-2">
            <ElInput
              v-for="(_, i) in wj.rno_4"
              :key="`s${i}`"
              v-model="wj.rno_4[i]"
              maxlength="4"
            />
          </div>
        </div>
        <div>
          <div class="mb-1">{{ tl('consolation_prize') }}</div>
          <div class="grid grid-cols-5 gap-2">
            <ElInput
              v-for="(_, i) in wj.rno_5"
              :key="`c${i}`"
              v-model="wj.rno_5[i]"
              maxlength="4"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- 步驟二：確認資訊 -->
    <div v-else class="space-y-1 leading-7">
      <div>{{ tl('period') }}: {{ periodTitle }}</div>
      <div>{{ tl('event_draw_date') }}: {{ period?.draw_time }}</div>
      <template v-if="variant === 'sr17'">
        <div>{{ tl('sr17_award_name_1') }}: {{ wj.rno_1 }}</div>
        <div>{{ tl('sr17_award_name_2') }}: {{ wj.rno_2 }}</div>
        <div>{{ tl('sr17_award_name_3') }}: {{ wj.rno_3 }}</div>
        <div>{{ tl('sr17_award_name_4') }}: {{ wj.rno_4.join(', ') }}</div>
        <div>{{ tl('sr17_award_name_5') }}: {{ wj.rno_5.join(', ') }}</div>
      </template>
      <div v-else>
        {{ tl('winning_numbers') }}:
        {{ variant === 'sr16' ? `${sr16.a}-${sr16.b}` : sixd.a }}
      </div>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton v-if="step === 0" type="primary" @click="toConfirm">
        {{ tl('confirm') }}
      </ElButton>
      <ElButton v-else :loading="submitting" type="danger" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
