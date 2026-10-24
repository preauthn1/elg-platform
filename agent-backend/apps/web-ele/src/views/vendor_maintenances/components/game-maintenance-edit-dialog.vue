<script lang="ts" setup>
import type { SelectableGame } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCheckbox,
  ElCheckboxGroup,
  ElDatePicker,
  ElDialog,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
} from 'element-plus';

import {
  GAME_MAINTENANCE_UNLOCK_AUTO,
  getAgentViewApi,
  getGameMaintenanceGameListApi,
  getGameMaintenanceViewApi,
  getGameTypeManageListApi,
  getVendorDropdownByTypeApi,
  saveGameMaintenanceApi,
  searchAgentOptionsApi,
  vendorNameWithId,
} from '#/api';
import { tl } from '#/locales';

/**
 * 遊戲維護編輯（4120）
 *
 * 與遊戲商維護的差別：粒度到單一遊戲、一張單可跨遊戲商。
 * 遊戲商與類型為兩層過濾器（都可多選），只用來縮小下方的遊戲清單；
 * 真正存起來的只有勾選的 game_id。
 */
defineOptions({ name: 'GameMaintenanceEditDialog' });

const emit = defineEmits<{ saved: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const loading = ref(false);
const saving = ref(false);
const gameLoading = ref(false);
const editId = ref<null | number>(null);

const vendors = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const excludeOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);
const excludeLoading = ref(false);

/** 篩選器：不落地，僅用來撈可勾選的遊戲 */
const picker = reactive({
  keyword: '',
  typeIds: [] as number[],
  vendorIds: [] as number[],
});

/** 篩出的候選遊戲 */
const candidates = ref<SelectableGame[]>([]);
/** 已勾選的遊戲（跨批次保留，故與 candidates 分開存） */
const selected = ref<SelectableGame[]>([]);

const form = reactive({
  agent_id: 0 as number | string,
  end_time: '',
  exclude_agent_id: 0 as number | string,
  memo: '',
  start_time: '',
  status: 1 as number | string,
  unlock_type: GAME_MAINTENANCE_UNLOCK_AUTO as number,
});

const selectedIds = computed(() => selected.value.map((g) => g.id));

/** 勾選狀態以 id 陣列雙向綁定，變動時同步維護 selected 的完整資料 */
const checkedIds = computed({
  get: () => selectedIds.value,
  set: (ids: number[]) => {
    const idSet = new Set(ids);
    // 保留原本已選、但不在這批候選裡的（跨遊戲商／換篩選條件時不該被清掉）
    const kept = selected.value.filter(
      (g) => idSet.has(g.id) && !candidates.value.some((c) => c.id === g.id),
    );
    const picked = candidates.value.filter((c) => idSet.has(c.id));
    selected.value = [...kept, ...picked];
  },
});

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}

async function searchExclude(query: string) {
  if (!query) return;
  excludeLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) excludeOptions.value = res.data ?? [];
  } finally {
    excludeLoading.value = false;
  }
}

/** 依遊戲商＋類型撈候選遊戲；未選遊戲商時不撈（避免整站遊戲全列出） */
async function loadCandidates() {
  if (picker.vendorIds.length === 0) {
    candidates.value = [];
    return;
  }
  gameLoading.value = true;
  try {
    const res = await getGameMaintenanceGameListApi({
      keyword: picker.keyword || undefined,
      type_ids: picker.typeIds.length > 0 ? picker.typeIds : undefined,
      vendor_ids: picker.vendorIds,
    });
    candidates.value = Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    gameLoading.value = false;
  }
}

function toggleAllCandidates(checked: boolean) {
  const ids = new Set(candidates.value.map((c) => c.id));
  checkedIds.value = checked
    ? [...new Set([...selectedIds.value, ...ids])]
    : selectedIds.value.filter((id) => !ids.has(id));
}

function removeSelected(id: number) {
  selected.value = selected.value.filter((g) => g.id !== id);
}

async function open(id?: number) {
  visible.value = true;
  editId.value = id ?? null;
  loading.value = true;
  agentOptions.value = [];
  excludeOptions.value = [];
  candidates.value = [];
  selected.value = [];
  picker.vendorIds = [];
  picker.typeIds = [];
  picker.keyword = '';

  try {
    // 下拉只載一次；失敗不擋表單（下拉空著仍可看既有設定）
    if (vendors.value.length === 0) {
      vendors.value =
        (await getVendorDropdownByTypeApi(1).catch(() => [])) ?? [];
    }
    if (gameTypes.value.length === 0) {
      const types = await getGameTypeManageListApi().catch(() => []);
      gameTypes.value = (types ?? []).map((t: any) => ({
        id: Number(t.id),
        name: t.name ?? '',
      }));
    }

    if (id) {
      const res = await getGameMaintenanceViewApi(id);
      const v = res.data;
      if (v) {
        // 顯示為 +8 時區（與遊戲商維護一致，後端存 UTC）
        form.start_time = v.start_time
          ? dayjs(v.start_time).add(8, 'hour').format('YYYY-MM-DD HH:mm')
          : '';
        form.end_time = v.end_time
          ? dayjs(v.end_time).add(8, 'hour').format('YYYY-MM-DD HH:mm')
          : '';
        form.status = Number(v.status) === 1 ? 1 : 0;
        form.unlock_type =
          Number(v.unlock_type) || GAME_MAINTENANCE_UNLOCK_AUTO;
        form.memo = v.memo ?? '';
        form.agent_id = v.agent_id ?? 0;
        form.exclude_agent_id = Number(v.exclude_agent_id) || 0;
        selected.value = (v.games ?? []) as SelectableGame[];
        // 帶回已選遊戲所屬的商，讓使用者一開啟就看得到候選清單
        picker.vendorIds = [
          ...new Set(selected.value.map((g) => g.vendor_id)),
        ].filter(Boolean);
        if (picker.vendorIds.length > 0) await loadCandidates();
      }
    } else {
      form.start_time = `${dayjs().add(8, 'hour').format('YYYY-MM-DD')} 00:00`;
      form.end_time = `${dayjs().add(32, 'hour').format('YYYY-MM-DD')} 00:00`;
      form.status = 1;
      form.unlock_type = GAME_MAINTENANCE_UNLOCK_AUTO;
      form.memo = '';
      form.exclude_agent_id = 0;
      const hostId = Number((userStore.userInfo as any)?.host_id ?? 0);
      form.agent_id = hostId;
      if (hostId) {
        const host = (await getAgentViewApi(hostId).catch(() => null)) as any;
        agentOptions.value = [{ id: hostId, username: host?.username ?? '' }];
      }
    }
  } finally {
    loading.value = false;
  }
}

async function handleSave() {
  if (selected.value.length === 0) {
    ElMessage.warning(tl('game_maintenance_pick_game_first'));
    return;
  }
  saving.value = true;
  try {
    const res = await saveGameMaintenanceApi({
      agent_id: form.agent_id,
      // 後端要求 Y-m-d H:i:s；表單只到分鐘，秒固定補 00
      end_time: `${form.end_time}:00`,
      exclude_agent_id: form.exclude_agent_id || 0,
      game_ids: selectedIds.value,
      memo: form.memo,
      start_time: `${form.start_time}:00`,
      status: form.status,
      unlock_type: form.unlock_type,
      ...(editId.value ? { id: editId.value } : {}),
    });
    if (Number(res.code) === 200) {
      ElMessage.success(res.message);
      visible.value = false;
      emit('saved');
    } else {
      ElMessage.error(res.message);
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
    :title="tl('game_maintenance')"
    align-center
    width="900"
  >
    <ElForm v-loading="loading" label-width="110px" @submit.prevent>
      <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
        <!-- 類型固定關閉遊戲，依工單不給編輯 -->
        <ElFormItem :label="tl('type')">
          <ElInput :value="tl('game_maintenance_type_1')" disabled />
        </ElFormItem>
        <ElFormItem :label="tl('enable_status')">
          <ElRadioGroup v-model="form.status">
            <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
            <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="tl('label_start_time')">
          <ElDatePicker
            v-model="form.start_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('label_end_time')">
          <ElDatePicker
            v-model="form.end_time"
            class="!w-full"
            format="YYYY-MM-DD HH:mm"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm"
          />
        </ElFormItem>
        <ElFormItem :label="tl('maintenance_agent')">
          <ElSelect
            v-model="form.agent_id"
            :loading="agentLoading"
            :remote-method="searchAgent"
            class="w-full"
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
        <ElFormItem :label="tl('exclude_agent')">
          <ElSelect
            v-model="form.exclude_agent_id"
            :loading="excludeLoading"
            :remote-method="searchExclude"
            class="w-full"
            clearable
            filterable
            remote
          >
            <ElOption :label="tl('none')" :value="0" />
            <ElOption
              v-for="a in excludeOptions"
              :key="a.id"
              :label="a.username"
              :value="a.id"
            />
          </ElSelect>
        </ElFormItem>
      </div>

      <ElFormItem :label="tl('memo')">
        <ElInput v-model="form.memo" :rows="2" type="textarea" />
      </ElFormItem>

      <!--
        遊戲挑選：商與類型都可多選，僅作為過濾條件不落地；
        勾選的遊戲才是最終生效範圍，且可跨遊戲商。
      -->
      <div class="border-border mt-2 rounded border p-3">
        <div class="mb-2 text-sm font-medium">
          {{ tl('game_maintenance_pick_games') }}
        </div>

        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-3">
          <ElFormItem :label="tl('platform')" label-width="70px">
            <ElSelect
              v-model="picker.vendorIds"
              class="w-full"
              collapse-tags
              filterable
              multiple
              @change="loadCandidates"
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="vendorNameWithId(v)"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('type')" label-width="70px">
            <ElSelect
              v-model="picker.typeIds"
              class="w-full"
              collapse-tags
              clearable
              filterable
              multiple
              @change="loadCandidates"
            >
              <ElOption
                v-for="t in gameTypes"
                :key="t.id"
                :label="t.name"
                :value="t.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('name')" label-width="70px">
            <ElInput
              v-model="picker.keyword"
              clearable
              @change="loadCandidates"
            />
          </ElFormItem>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <!-- 候選清單 -->
          <div class="border-border rounded border">
            <div
              class="border-border flex items-center justify-between border-b px-2 py-1"
            >
              <span class="text-xs">
                {{ tl('game_maintenance_candidates') }}（{{
                  candidates.length
                }}）
              </span>
              <div v-if="candidates.length > 0" class="flex gap-1">
                <ElButton link size="small" @click="toggleAllCandidates(true)">
                  {{ tl('select_all') }}
                </ElButton>
                <ElButton link size="small" @click="toggleAllCandidates(false)">
                  {{ tl('cancel') }}
                </ElButton>
              </div>
            </div>
            <div v-loading="gameLoading" class="max-h-64 overflow-auto p-2">
              <ElCheckboxGroup
                v-if="candidates.length > 0"
                v-model="checkedIds"
              >
                <div v-for="g in candidates" :key="g.id" class="py-0.5">
                  <ElCheckbox :value="g.id">
                    <span class="text-xs">
                      {{ g.name }}
                      <span class="text-muted-foreground">
                        （{{ g.vendor_name }}）
                      </span>
                    </span>
                  </ElCheckbox>
                </div>
              </ElCheckboxGroup>
              <ElEmpty
                v-else
                :description="tl('game_maintenance_pick_vendor_first')"
                :image-size="60"
              />
            </div>
          </div>

          <!-- 已選清單：跨商累積，換篩選條件不會清空 -->
          <div class="border-border rounded border">
            <div class="border-border border-b px-2 py-1 text-xs">
              {{ tl('game_maintenance_selected') }}（{{ selected.length }}）
            </div>
            <div class="max-h-64 overflow-auto p-2">
              <div
                v-for="g in selected"
                :key="g.id"
                class="hover:bg-accent/50 flex items-center justify-between rounded px-1 py-0.5 text-xs"
              >
                <span>
                  {{ g.name }}
                  <span class="text-muted-foreground">
                    （{{ g.vendor_name }}）
                  </span>
                </span>
                <ElButton link size="small" @click="removeSelected(g.id)">
                  {{ tl('delete') }}
                </ElButton>
              </div>
              <ElEmpty
                v-if="selected.length === 0"
                :description="tl('no_record')"
                :image-size="60"
              />
            </div>
          </div>
        </div>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
