<script lang="ts" setup>
import type { VendorGameRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElCheckbox,
  ElCheckboxGroup,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElRadio,
  ElRadioGroup,
} from 'element-plus';

import {
  getVendorInfoGameApi,
  getVendorInfoViewApi,
  saveVendorInfoApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'VendorInfoEditDialog' });

const emit = defineEmits<{ saved: [] }>();

interface Group {
  game_type_name: string;
  items: VendorGameRow[];
  type_id: number;
}

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const editId = ref<null | number>(null);
const isSingleWallet = ref(false);

const groups = ref<Group[]>([]);
const selected = ref<number[]>([]);
/** 原本 status 為 null 的遊戲（新遊戲） */
const newGameIds = ref<Set<number>>(new Set());

const form = reactive({
  account_prefix: '',
  cost: '' as number | string,
  created_at: '',
  currency: '',
  info: '',
  name: '',
  prepared_account_number: '',
  rate: '' as number | string,
  rolling_chip_status: 1 as number | string,
  status: 1 as number | string,
  transfer_min_amount: '' as number | string,
  type: 1 as number | string,
  vendor_id: '' as number | string,
  vendor_name: '',
});

const allChecked = computed({
  get() {
    const all = groups.value.flatMap((g) => g.items.map((i) => i.id));
    return all.length > 0 && all.every((id) => selected.value.includes(id));
  },
  set(val: boolean) {
    selected.value = val
      ? groups.value.flatMap((g) => g.items.map((i) => i.id))
      : [];
  },
});

function buildGroups(games: VendorGameRow[]) {
  const map = new Map<number, Group>();
  const sel: number[] = [];
  const news = new Set<number>();
  for (const g of games) {
    const typeId = Number(g.type_id);
    if (!map.has(typeId)) {
      map.set(typeId, {
        game_type_name: g.game_type_name ?? '',
        items: [],
        type_id: typeId,
      });
    }
    map.get(typeId)?.items.push(g);
    if (g.status === null) news.add(g.id);
    else if (Number(g.status) === 1) sel.push(g.id);
  }
  groups.value = [...map.values()];
  selected.value = sel;
  newGameIds.value = news;
}

function resetForm() {
  form.account_prefix = '';
  form.cost = '';
  form.created_at = '';
  form.currency = '';
  example.invalid = '';
  form.name = '';
  form.prepared_account_number = '';
  form.rate = '';
  form.rolling_chip_status = 1;
  form.status = 1;
  form.transfer_min_amount = '';
  form.type = 1;
  form.vendor_id = '';
  form.vendor_name = '';
}

async function open(id?: number) {
  visible.value = true;
  editId.value = id ?? null;
  loading.value = true;
  groups.value = [];
  selected.value = [];
  newGameIds.value = new Set();
  isSingleWallet.value = false;
  resetForm();
  try {
    if (id) {
      const v = (await getVendorInfoViewApi(id)) as any;
      form.vendor_id = v?.vendor_id ?? '';
      form.vendor_name = v?.vendor_name ?? '';
      form.name = v?.name ?? '';
      form.type = [0, 1, 2].includes(Number(v?.type)) ? Number(v.type) : 1;
      form.cost =
        v?.cost === undefined || v?.cost === null ? '' : Number(v.cost);
      form.currency = v?.currency ?? '';
      form.account_prefix = v?.account_prefix ?? '';
      form.rate = v?.rate === undefined ? '' : Number(v.rate);
      form.transfer_min_amount =
        v?.transfer_min_amount === undefined
          ? ''
          : Number(v.transfer_min_amount);
      form.prepared_account_number = v?.prepared_account_number ?? '';
      form.status = Number(v?.status) === 1 ? 1 : 0;
      form.rolling_chip_status = Number(v?.rolling_chip_status) === 1 ? 1 : 0;
      example.invalid = v?.info ?? '';
      form.created_at = v?.created_at ?? '';
      isSingleWallet.value = Number(v?.is_single_wallet) === 1;

      const games =
        (await getVendorInfoGameApi({ id, vendor_id: form.vendor_id })) ?? [];
      buildGroups(games);
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  // cost 範圍驗證 0 ~ 1
  if (form.cost !== '' && form.cost !== null) {
    const c = Number(form.cost);
    if (Number.isNaN(c) || c < 0 || c > 1) {
      ElMessage.error(`${tl('cost')}：0 ~ 1`);
      return;
    }
  }
  saving.value = true;
  try {
    const payload: Record<string, any> = {
      account_prefix: form.account_prefix,
      cost: form.cost === '' ? '' : String(form.cost),
      created_at: form.created_at,
      currency: form.currency,
      info: example.invalid,
      name: form.name,
      prepared_account_number: form.prepared_account_number,
      rate: form.rate,
      rolling_chip_status: form.rolling_chip_status,
      status: form.status,
      transfer_min_amount: form.transfer_min_amount,
      type: form.type,
      vendor_id: form.vendor_id,
    };
    if (editId.value) {
      payload.id = editId.value;
      payload.games = selected.value;
      payload.newgames = selected.value.filter((id) =>
        newGameIds.value.has(id),
      );
    }
    const res = await saveVendorInfoApi(payload);
    if (Number(res.code) === 1000) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(
        Number(res.code) === 2002 ? String(res.data) : res.message,
      );
    }
  } finally {
    saving.value = false;
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('menu_vendor_info_management')} > ${tl('edit')}`"
    align-center
    width="860"
  >
    <ElForm v-loading="loading" :model="form" label-width="auto">
      <div class="grid grid-cols-1 gap-x-6 md:grid-cols-2">
        <ElFormItem :label="`${tl('label_vendor')}${tl('id')}`">
          <span v-if="editId" class="text-green-600">{{
            form.vendor_name
          }}</span>
          <ElInput v-else v-model="form.vendor_id" placeholder="vendor_id" />
        </ElFormItem>
        <ElFormItem :label="tl('vendor_info_name')">
          <ElInput v-model="form.name" />
        </ElFormItem>
        <ElFormItem :label="tl('type')">
          <ElRadioGroup v-model="form.type">
            <ElRadio :value="0">{{ tl('testing') }}</ElRadio>
            <ElRadio :value="1">{{ tl('production') }}</ElRadio>
            <ElRadio :value="2">{{ tl('event') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('cost')">
          <ElInput
            v-model="form.cost"
            :max="1"
            :min="0"
            placeholder="0 ~ 1"
            step="any"
            type="number"
          />
        </ElFormItem>
        <ElFormItem :label="tl('currency_code')">
          <ElInput v-model="form.currency" />
        </ElFormItem>
        <ElFormItem :label="tl('account_prefix')">
          <ElInput v-model="form.account_prefix" />
        </ElFormItem>
        <ElFormItem :label="tl('rate')">
          <ElInput v-model="form.rate" :disabled="!!editId" />
        </ElFormItem>
        <ElFormItem :label="tl('transfer_min_amount')">
          <ElInput v-model="form.transfer_min_amount" />
        </ElFormItem>
        <ElFormItem :label="tl('prepared_account')">
          <ElInput v-model="form.prepared_account_number" />
        </ElFormItem>
        <ElFormItem :label="tl('status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem v-if="isSingleWallet" :label="tl('rolling_chip_switch')">
          <ElRadioGroup v-model="form.rolling_chip_status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
      </div>

      <ElFormItem :label="tl('sms_gateway_info')">
        <ElInput v-model="example.invalid" :rows="3" type="textarea" />
      </ElFormItem>

      <template v-if="editId">
        <ElFormItem :label="tl('show_game')">
          <ElCheckbox v-model="allChecked">{{ tl('select_all') }}</ElCheckbox>
        </ElFormItem>
        <div v-for="g in groups" :key="g.type_id" class="mb-4">
          <h4 class="mb-2 border-b pb-1 font-bold">{{ g.game_type_name }}</h4>
          <ElCheckboxGroup v-model="selected">
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 md:grid-cols-4">
              <ElCheckbox
                v-for="item in g.items"
                :key="item.id"
                :value="item.id"
              >
                {{ item.name }}
              </ElCheckbox>
            </div>
          </ElCheckboxGroup>
        </div>
      </template>
    </ElForm>
    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
