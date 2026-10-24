<script lang="ts" setup>
import type { CheckinPrize } from '#/api';

import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCheckbox,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import {
  getCheckinDetailApi,
  getGeneralAgentDropdownApi,
  saveCheckinApi,
} from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const editId = ref('');
const isEdit = computed(() => Boolean(editId.value));

const agents = ref<Array<{ id: number | string; username: string }>>([]);
let agentsLoaded = false;

/** 獎勵類型 1=平台點數 / 2=洗碼量 */
const PRIZE_TYPES = [
  { label: tl('platform_point'), value: 1 },
  { label: tl('rolling_chip'), value: 2 },
];

function defaultForm() {
  return {
    account_block: false,
    agent_id: '' as number | string,
    flow_limit_multiple: 0 as number | string,
    ip_block: false,
    phone_block: false,
    prizes: [] as CheckinPrize[],
    time_range: [
      dayjs().format('YYYY-MM-DD 00:00'),
      dayjs().format('YYYY-MM-DD 23:59'),
    ] as string[],
  };
}

const form = reactive(defaultForm());

const draft = reactive({
  day: '' as number | string,
  prize_amount: '' as number | string,
  prize_type: 1 as number | string,
});

function addPrize() {
  if (!draft.day) return;
  form.prizes.push({
    day: draft.day,
    prize_amount: draft.prize_amount,
    prize_type: draft.prize_type,
  });
  draft.day = '';
  draft.prize_amount = '';
  draft.prize_type = 1;
}
function removePrize(i: number) {
  form.prizes.splice(i, 1);
}
function prizeTypeText(t?: number | string) {
  return (
    PRIZE_TYPES.find((p) => Number(p.value) === Number(t))?.label ??
    String(t ?? '')
  );
}

async function open(id: number | string = '') {
  editId.value = String(id || '');
  Object.assign(form, defaultForm());
  draft.day = '';
  draft.prize_amount = '';
  draft.prize_type = 1;
  visible.value = true;
  loading.value = true;
  try {
    if (!agentsLoaded) {
      agents.value = ((await getGeneralAgentDropdownApi().catch(() => [])) ??
        []) as any;
      agentsLoaded = true;
    }
    if (editId.value) {
      const res = await getCheckinDetailApi(editId.value);
      if (Number(res.code) === 200 && res.data) {
        const d: any = res.data;
        form.agent_id = d.agent_id ?? '';
        if (d.start_date && d.end_date)
          form.time_range = [d.start_date, d.end_date];
        form.flow_limit_multiple = d.flow_limit_multiple ?? 0;
        form.ip_block = Number(d.ip_block) === 1;
        form.phone_block = Number(d.phone_block) === 1;
        form.account_block = Number(d.account_block) === 1;
        form.prizes = (d.checkin_prizes ?? []).map((p: CheckinPrize) => ({
          ...p,
        }));
      }
    }
  } finally {
    loading.value = false;
  }
}

async function submit() {
  const [start, end] = form.time_range ?? [];
  submitting.value = true;
  try {
    const res = await saveCheckinApi(
      {
        ...(isEdit.value ? { id: editId.value } : {}),
        account_block: form.account_block ? 1 : 0,
        agent_id: form.agent_id,
        day: form.prizes.map((p) => p.day),
        end_date: end ?? '',
        flow_limit_multiple: form.flow_limit_multiple || 0,
        ip_block: form.ip_block ? 1 : 0,
        phone_block: form.phone_block ? 1 : 0,
        prize_amount: form.prizes.map((p) => p.prize_amount),
        prize_type: form.prizes.map((p) => p.prize_type),
        start_date: start ?? '',
      },
      isEdit.value,
    );
    if (Number(res.code) === 200) {
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
    :title="isEdit ? tl('edit') : tl('event_create')"
    align-center
    width="820"
  >
    <ElForm v-loading="loading" label-position="top">
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <ElFormItem :label="tl('agent_account')">
          <ElSelect v-model="form.agent_id" class="!w-full" filterable>
            <ElOption
              v-for="a in agents"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('between')">
          <DateRangeFields
            v-model="form.time_range"
            :quick="false"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('event_flow_limit_multiple')">
          <ElInput v-model="form.flow_limit_multiple" />
        </ElFormItem>
      </div>
      <ElFormItem>
        <ElCheckbox v-model="form.ip_block">
          {{ tl('block_duplicate_ip') }}
        </ElCheckbox>
        <ElCheckbox v-model="form.phone_block">
          {{ tl('block_duplicate_phone') }}
        </ElCheckbox>
        <ElCheckbox v-model="form.account_block">
          {{ tl('block_duplicate_account') }}
        </ElCheckbox>
      </ElFormItem>

      <div class="mb-2 font-medium">{{ tl('checkin_reward_days') }}</div>
      <div class="mb-3 grid grid-cols-1 items-end gap-2 md:grid-cols-4">
        <ElFormItem :label="tl('checkin_reward_days')" class="!mb-0">
          <ElInput v-model="draft.day" />
        </ElFormItem>
        <ElFormItem :label="tl('reward_type')" class="!mb-0">
          <ElSelect v-model="draft.prize_type" class="!w-full">
            <ElOption
              v-for="p in PRIZE_TYPES"
              :key="p.value"
              :label="p.label"
              :value="p.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('reward_point')" class="!mb-0">
          <ElInput v-model="draft.prize_amount" />
        </ElFormItem>
        <ElButton type="primary" @click="addPrize">+</ElButton>
      </div>
      <ElTable :data="form.prizes" border size="small">
        <ElTableColumn :label="tl('checkin_reward_days')" prop="day" />
        <ElTableColumn :label="tl('reward_type')">
          <template #default="{ row }">
            {{ prizeTypeText(row.prize_type) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="tl('reward_point')" prop="prize_amount" />
        <ElTableColumn :label="tl('features')" align="center" width="90">
          <template #default="{ $index }">
            <ElButton size="small" type="danger" @click="removePrize($index)">
              -
            </ElButton>
          </template>
        </ElTableColumn>
        <template #empty>{{ tl('no_record') }}</template>
      </ElTable>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="success" @click="submit">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
