<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElSelect,
} from 'element-plus';

import {
  getBindRewardGameTypesApi,
  getBindRewardViewApi,
  getVendorListApi,
  saveBindRewardApi,
  searchAgentOptionsApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const saving = ref(false);
const searching = ref(false);

const agentOptions = ref<Array<{ id: number | string; username: string }>>([]);
const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
let dropdownsLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    agent_id: '' as number | string,
    end_time: dayjs().format('YYYY-MM-DD 23:59:59'),
    flow_limit_multiple: 0,
    reward_name: '',
    reward_point: 0,
    reward_type: 1 as number | string,
    start_time: dayjs().format('YYYY-MM-DD 00:00:00'),
  };
}

/** 流水限制（遊戲商×遊戲類型）組合列 */
const restrictions = ref<
  Array<{ game_type_id: number | string; vendor_id: number | string }>
>([]);
const pickVendor = ref<number | string>(0);
const pickGameType = ref<number | string>(0);

function vendorName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return vendors.value.find((v) => Number(v.id) === Number(id))?.name ?? id;
}
function gameTypeName(id: number | string) {
  if (Number(id) === 0) return tl('unlimited');
  return gameTypes.value.find((g) => Number(g.id) === Number(id))?.name ?? id;
}

function addRestriction() {
  restrictions.value.push({
    game_type_id: pickGameType.value,
    vendor_id: pickVendor.value,
  });
}

function removeRestriction(index: number) {
  restrictions.value.splice(index, 1);
}

async function remoteAgent(query: string) {
  if (!query) return;
  searching.value = true;
  try {
    const res = await searchAgentOptionsApi(query).catch(() => null);
    agentOptions.value = (res?.data ?? []) as Array<{
      id: number;
      username: string;
    }>;
  } finally {
    searching.value = false;
  }
}

function validate(): boolean {
  if (form.start_time > form.end_time) {
    ElMessage.warning(`${tl('date_range')}${tl('error')}`);
    return false;
  }
  if (!form.agent_id) {
    ElMessage.warning(`${tl('agent_name')}${tl('error')}`);
    return false;
  }
  if (!form.reward_name) {
    ElMessage.warning(`${tl('bind_reward_name')}${tl('error')}`);
    return false;
  }
  if (!Number.isFinite(form.reward_point) || form.reward_point < 0) {
    ElMessage.warning(`${tl('bind_reward_point')}${tl('error')}`);
    return false;
  }
  if (
    !Number.isFinite(form.flow_limit_multiple) ||
    form.flow_limit_multiple < 0
  ) {
    ElMessage.warning(`${tl('event_flow_limit_multiple')}${tl('error')}`);
    return false;
  }
  return true;
}

async function handleSave() {
  if (!validate()) return;
  saving.value = true;
  try {
    const res = await saveBindRewardApi({
      agent_id: form.agent_id,
      end_time: form.end_time,
      flow_limit_multiple: form.flow_limit_multiple,
      game_type_id_restriction: restrictions.value.map((r) => r.game_type_id),
      id: editId.value || undefined,
      reward_name: form.reward_name,
      reward_point: form.reward_point,
      reward_type: form.reward_type,
      start_time: form.start_time,
      vendor_id_restriction: restrictions.value.map((r) => r.vendor_id),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else if (Number(res.code) === 2002) {
      ElMessage.error(String(res.data ?? res.message));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

async function open(id: number | string = '') {
  editId.value = id || '';
  Object.assign(form, makeDefaults());
  restrictions.value = [];
  agentOptions.value = [];
  pickVendor.value = 0;
  pickGameType.value = 0;
  visible.value = true;
  if (!dropdownsLoaded) {
    const [v, g] = await Promise.all([
      getVendorListApi().catch(() => []),
      getBindRewardGameTypesApi().catch(() => []),
    ]);
    vendors.value = v ?? [];
    gameTypes.value = g ?? [];
    dropdownsLoaded = true;
  }
  if (isEdit.value) {
    const d = await getBindRewardViewApi(editId.value).catch(() => null);
    if (d) {
      form.agent_id = d.agent_id;
      agentOptions.value = [{ id: d.agent_id, username: d.agent_name }];
      if (d.start_time) {
        form.start_time = dayjs(d.start_time)
          .add(8, 'hour')
          .format('YYYY-MM-DD HH:mm:ss');
      }
      if (d.end_time) {
        form.end_time = dayjs(d.end_time)
          .add(8, 'hour')
          .format('YYYY-MM-DD HH:mm:ss');
      }
      form.reward_name = d.reward_name ?? '';
      form.reward_type = Number(d.type ?? 1);
      form.reward_point = Number(d.point ?? 0);
      try {
        const fl = JSON.parse(d.flow_limits || '{}');
        form.flow_limit_multiple = Number(fl?.flow_limit_multiple ?? 0);
        const vendorIds: Array<number | string> =
          fl?.vendor_id_restriction ?? [];
        const gameTypeIds: Array<number | string> =
          fl?.game_type_id_restriction ?? [];
        restrictions.value = vendorIds.map((vid, i) => ({
          game_type_id: gameTypeIds[i] ?? 0,
          vendor_id: vid,
        }));
      } catch {
        form.flow_limit_multiple = 0;
      }
    }
  } else {
    const info = userStore.userInfo as any;
    const selfId = info?.host_id ?? info?.id ?? '';
    form.agent_id = selfId;
    agentOptions.value = [
      { id: selfId, username: info?.realName ?? info?.username ?? '' },
    ];
    // 對齊舊版：新增時預設一列「不限/不限」
    restrictions.value = [{ game_type_id: 0, vendor_id: 0 }];
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_bind_reward_list_management')} - ${isEdit ? tl('edit') : tl('add')}`"
    width="720"
  >
    <ElFormItem :label="tl('agent_name')" label-width="130">
      <ElSelect
        v-model="form.agent_id"
        :debounce="500"
        :loading="searching"
        :remote-method="remoteAgent"
        class="!w-full"
        filterable
        remote
      >
        <ElOption
          v-for="a in agentOptions"
          :key="a.id"
          :label="a.username"
          :value="a.id"
        />
      </ElSelect>
    </ElFormItem>
    <ElFormItem :label="tl('label_start_time')" label-width="130">
      <ElDatePicker
        v-model="form.start_time"
        class="!w-full"
        format="YYYY-MM-DD HH:mm:ss"
        type="datetime"
        value-format="YYYY-MM-DD HH:mm:ss"
      />
    </ElFormItem>
    <ElFormItem :label="tl('label_end_time')" label-width="130">
      <ElDatePicker
        v-model="form.end_time"
        class="!w-full"
        format="YYYY-MM-DD HH:mm:ss"
        type="datetime"
        value-format="YYYY-MM-DD HH:mm:ss"
      />
    </ElFormItem>

    <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
      <ElFormItem label-width="0" required>
        <template #label>{{ tl('reward_name') }}</template>
        <ElInput v-model="form.reward_name" />
      </ElFormItem>
      <ElFormItem label-width="0" required>
        <template #label>{{ tl('reward_type') }}</template>
        <ElSelect v-model="form.reward_type" class="!w-full">
          <ElOption :label="tl('platform_point')" :value="1" />
          <ElOption :label="tl('rolling_chip')" :value="2" />
        </ElSelect>
      </ElFormItem>
      <ElFormItem label-width="0" required>
        <template #label>{{ tl('reward_point') }}</template>
        <ElInputNumber
          v-model="form.reward_point"
          :min="0"
          class="!w-full"
          controls-position="right"
        />
      </ElFormItem>
    </div>

    <ElFormItem :label="tl('event_flow_limit_multiple')" label-width="130">
      <ElInputNumber
        v-model="form.flow_limit_multiple"
        :min="0"
        class="!w-48"
        controls-position="right"
      />
    </ElFormItem>

    <div class="border-border mt-2 rounded border p-3">
      <div class="mb-2 flex flex-wrap items-center gap-2">
        <ElFormItem :label="tl('vendor_announcement')" class="!mb-0">
          <ElSelect v-model="pickVendor" class="!w-44" filterable>
            <ElOption :label="tl('unlimited')" :value="0" />
            <ElOption
              v-for="v in vendors"
              :key="v.id"
              :label="v.name"
              :value="v.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="tl('game_type')" class="!mb-0">
          <ElSelect v-model="pickGameType" class="!w-44" filterable>
            <ElOption :label="tl('unlimited')" :value="0" />
            <ElOption
              v-for="g in gameTypes"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElButton type="info" @click="addRestriction">+</ElButton>
      </div>
      <div
        v-for="(r, i) in restrictions"
        :key="i"
        class="mb-2 flex items-center gap-2"
      >
        <ElInput
          :model-value="String(vendorName(r.vendor_id))"
          class="!w-44"
          disabled
        />
        <ElInput
          :model-value="String(gameTypeName(r.game_type_id))"
          class="!w-44"
          disabled
        />
        <ElButton type="danger" @click="removeRestriction(i)">-</ElButton>
      </div>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
