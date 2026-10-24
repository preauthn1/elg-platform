<script lang="ts" setup>
import type { RestrictedMember, SelectableGame } from '#/api';

import { computed, reactive, ref } from 'vue';

import { useUserStore } from '@vben/stores';

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
  ElSwitch,
  ElTag,
} from 'element-plus';

import {
  getGameMaintenanceGameListApi,
  getGameTypeManageListApi,
  getMemberGameRestrictionViewApi,
  getMemberGroupDropdownApi,
  getVendorDropdownByTypeApi,
  MEMBER_RESTRICT_SCOPE_GROUP,
  MEMBER_RESTRICT_SCOPE_MEMBER,
  MEMBER_RESTRICT_TYPE_GAME,
  MEMBER_RESTRICT_TYPE_VENDOR,
  saveMemberGameRestrictionApi,
  searchRestrictionMembersApi,
  vendorNameWithId,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'GameRestrictionEditDialog' });

const emit = defineEmits<{ success: [] }>();

const userStore = useUserStore();

const visible = ref(false);
const editId = ref<number | string>('');
const isEdit = computed(() => Boolean(editId.value));
const loading = ref(false);
const submitting = ref(false);

const form = reactive({
  memo: '',
  restrict_type: MEMBER_RESTRICT_TYPE_VENDOR as number,
  status: 1 as number,
  target_scope: MEMBER_RESTRICT_SCOPE_MEMBER as number,
});

const isVendorType = computed(
  () => Number(form.restrict_type) === MEMBER_RESTRICT_TYPE_VENDOR,
);
const isMemberScope = computed(
  () => Number(form.target_scope) === MEMBER_RESTRICT_SCOPE_MEMBER,
);

// ── 下拉資料 ──
const vendorOptions = ref<Array<{ id: number; name: string }>>([]);
const gameTypes = ref<Array<{ id: number; name: string }>>([]);
const groupOptions = ref<Array<{ id: number; name: string }>>([]);

// ── 受限標的：遊戲商（多選） ──
const selectedVendorIds = ref<number[]>([]);

// ── 受限標的：遊戲（遊戲商→類型→名稱 三層篩選，候選/已選雙欄，比照 4120） ──
const picker = reactive({
  keyword: '',
  typeIds: [] as number[],
  vendorIds: [] as number[],
});
const candidates = ref<SelectableGame[]>([]);
const candidateLoading = ref(false);
/** 已選遊戲跨批次保留完整資料，切換篩選條件不會掉 label */
const selectedGames = ref<SelectableGame[]>([]);
const selectedGameIds = computed(() => selectedGames.value.map((g) => g.id));

// ── 受限對象：會員（遠端搜尋）／群組（一次載入） ──
const memberOptions = ref<RestrictedMember[]>([]);
const memberSearching = ref(false);
const selectedMembers = ref<RestrictedMember[]>([]);
const selectedMemberIds = computed({
  get: () => selectedMembers.value.map((m) => m.id),
  set: (ids: number[]) => {
    const idSet = new Set(ids);
    const kept = selectedMembers.value.filter(
      (m) => idSet.has(m.id) && !memberOptions.value.some((o) => o.id === m.id),
    );
    const picked = memberOptions.value.filter((o) => idSet.has(o.id));
    selectedMembers.value = [...kept, ...picked];
  },
});
const selectedGroupIds = ref<number[]>([]);

function resetForm() {
  Object.assign(form, {
    memo: '',
    restrict_type: MEMBER_RESTRICT_TYPE_VENDOR,
    status: 1,
    target_scope: MEMBER_RESTRICT_SCOPE_MEMBER,
  });
  selectedVendorIds.value = [];
  Object.assign(picker, { keyword: '', typeIds: [], vendorIds: [] });
  candidates.value = [];
  selectedGames.value = [];
  memberOptions.value = [];
  selectedMembers.value = [];
  selectedGroupIds.value = [];
}

/** 切換限制類型時清掉另一種的已選，避免存到不相干的 target_ids */
function onRestrictTypeChange() {
  selectedVendorIds.value = [];
  Object.assign(picker, { keyword: '', typeIds: [], vendorIds: [] });
  candidates.value = [];
  selectedGames.value = [];
}

/** 切換對象範圍時同理 */
function onScopeChange() {
  selectedMembers.value = [];
  selectedGroupIds.value = [];
}

/** 候選遊戲：未選遊戲商不查（整站上萬款，全量會凍死彈窗） */
async function loadCandidates() {
  if (picker.vendorIds.length === 0) {
    candidates.value = [];
    return;
  }
  candidateLoading.value = true;
  try {
    const res = await getGameMaintenanceGameListApi({
      keyword: picker.keyword || undefined,
      type_ids: picker.typeIds.length > 0 ? picker.typeIds : undefined,
      vendor_ids: picker.vendorIds,
    });
    candidates.value = Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    candidateLoading.value = false;
  }
}

function isPicked(id: number) {
  return selectedGames.value.some((g) => g.id === id);
}

function toggleGame(game: SelectableGame, checked: boolean) {
  selectedGames.value = checked
    ? [...selectedGames.value, game]
    : selectedGames.value.filter((g) => g.id !== game.id);
}

/** 全選/取消當前候選（不影響其他批次已選的） */
function toggleAllCandidates(checked: boolean) {
  const ids = new Set(candidates.value.map((c) => c.id));
  selectedGames.value = checked
    ? [
        ...selectedGames.value.filter((g) => !ids.has(g.id)),
        ...candidates.value,
      ]
    : selectedGames.value.filter((g) => !ids.has(g.id));
}

function removeGame(id: number) {
  selectedGames.value = selectedGames.value.filter((g) => g.id !== id);
}

async function searchMembers(keyword: string) {
  const q = (keyword ?? '').trim();
  if (!q) {
    memberOptions.value = [];
    return;
  }
  memberSearching.value = true;
  try {
    const res = await searchRestrictionMembersApi({ keyword: q });
    memberOptions.value = Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    memberSearching.value = false;
  }
}

async function loadDropdowns() {
  const [vendors, types, groups] = await Promise.all([
    getVendorDropdownByTypeApi(1).catch(() => []),
    getGameTypeManageListApi().catch(() => []),
    getMemberGroupDropdownApi().catch(() => null),
  ]);
  vendorOptions.value = (vendors as any) ?? [];
  gameTypes.value = ((types as any) ?? []).map((t: any) => ({
    id: Number(t.id),
    name: t.name,
  }));
  groupOptions.value =
    groups && Number(groups.code) === 200
      ? (groups.data ?? []).map((g) => ({
          id: Number(g.id),
          name: String(g.name ?? ''),
        }))
      : [];
}

async function loadEdit(id: number | string) {
  const res = await getMemberGameRestrictionViewApi(id).catch(() => null);
  if (!res || Number(res.code) !== 200 || !res.data) return;

  const d = res.data;
  form.restrict_type = Number(d.restrict_type);
  form.target_scope = Number(d.target_scope);
  form.status = Number(d.status);
  form.memo = d.memo ?? '';

  if (Number(d.restrict_type) === MEMBER_RESTRICT_TYPE_VENDOR) {
    selectedVendorIds.value = d.target_ids ?? [];
  } else {
    // 後端已帶遊戲名稱與所屬遊戲商，下拉為遠端搜尋、不重查也能正確顯示已選項
    const nameMap = new Map(
      (d.target_names ?? []).map((t) => [
        t.id,
        { name: t.name, vendorId: t.vendor_id, vendorName: t.vendor_name },
      ]),
    );
    selectedGames.value = (d.target_ids ?? []).map((gameId) => {
      const hit = nameMap.get(gameId);
      return {
        id: gameId,
        name: hit?.name ?? `#${gameId}`,
        type_id: 0,
        vendor_id: hit?.vendorId ?? 0,
        vendor_name: hit?.vendorName || null,
      };
    });

    // 自動勾上已選遊戲所屬的遊戲商並載入候選，
    // 否則進編輯時左側清單是空的、看不出哪些已被勾選
    const vendorIds = [
      ...new Set(
        selectedGames.value.map((g) => Number(g.vendor_id)).filter(Boolean),
      ),
    ];
    if (vendorIds.length > 0) {
      picker.vendorIds = vendorIds;
      await loadCandidates();
    }
  }

  // 會員清單後端已帶 username，直接作為選項供顯示
  selectedMembers.value = d.members ?? [];
  memberOptions.value = d.members ?? [];
  selectedGroupIds.value = d.member_group_ids ?? [];
}

async function open(id?: number | string) {
  editId.value = id ?? '';
  visible.value = true;
  loading.value = true;
  resetForm();
  try {
    await loadDropdowns();
    if (id) await loadEdit(id);
  } finally {
    loading.value = false;
  }
}

async function submit() {
  const targetIds = isVendorType.value
    ? selectedVendorIds.value
    : selectedGameIds.value;

  if (targetIds.length === 0) {
    ElMessage.warning(
      isVendorType.value
        ? tl('game_restriction_pick_vendor')
        : tl('game_restriction_pick_game'),
    );
    return;
  }
  if (isMemberScope.value && selectedMemberIds.value.length === 0) {
    ElMessage.warning(tl('game_restriction_pick_member'));
    return;
  }
  if (!isMemberScope.value && selectedGroupIds.value.length === 0) {
    ElMessage.warning(tl('game_restriction_pick_group'));
    return;
  }

  submitting.value = true;
  try {
    const res = await saveMemberGameRestrictionApi({
      agent_id: Number((userStore.userInfo as any)?.id ?? 0),
      ...(editId.value ? { id: editId.value } : {}),
      member_group_ids: isMemberScope.value ? [] : selectedGroupIds.value,
      member_ids: isMemberScope.value ? selectedMemberIds.value : [],
      memo: form.memo,
      restrict_type: form.restrict_type,
      status: form.status,
      target_ids: targetIds,
      target_scope: form.target_scope,
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('operation_failed'));
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
    :title="isEdit ? tl('edit') : tl('add')"
    width="820px"
  >
    <ElForm v-loading="loading" label-width="110px">
      <!-- 限制對象 -->
      <div class="border-border mb-3 rounded border p-3">
        <div class="mb-2 text-sm font-medium">
          {{ tl('game_restriction_section_target') }}
        </div>
        <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
          <ElFormItem :label="tl('game_restriction_scope')">
            <ElSelect
              v-model="form.target_scope"
              class="w-full"
              @change="onScopeChange"
            >
              <ElOption
                :label="tl('game_restriction_scope_member')"
                :value="MEMBER_RESTRICT_SCOPE_MEMBER"
              />
              <ElOption
                :label="tl('game_restriction_scope_group')"
                :value="MEMBER_RESTRICT_SCOPE_GROUP"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="tl('status')">
            <ElSwitch
              v-model="form.status"
              :active-text="tl('enable')"
              :active-value="1"
              :inactive-text="tl('disable')"
              :inactive-value="0"
            />
          </ElFormItem>
        </div>

        <!-- 會員：遠端搜尋（下線動輒數萬筆，不預載） -->
        <ElFormItem
          v-if="isMemberScope"
          :label="tl('game_restriction_scope_member')"
        >
          <ElSelect
            v-model="selectedMemberIds"
            :loading="memberSearching"
            :remote-method="searchMembers"
            class="w-full"
            collapse-tags
            collapse-tags-tooltip
            filterable
            multiple
            remote
            reserve-keyword
          >
            <ElOption
              v-for="m in [
                ...selectedMembers.filter(
                  (s) => !memberOptions.some((o) => o.id === s.id),
                ),
                ...memberOptions,
              ]"
              :key="m.id"
              :label="m.username"
              :value="m.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-else :label="tl('game_restriction_scope_group')">
          <ElSelect
            v-model="selectedGroupIds"
            class="w-full"
            collapse-tags
            collapse-tags-tooltip
            filterable
            multiple
          >
            <ElOption
              v-for="g in groupOptions"
              :key="g.id"
              :label="g.name"
              :value="g.id"
            />
          </ElSelect>
        </ElFormItem>
      </div>

      <!-- 限制內容 -->
      <div class="border-border rounded border p-3">
        <div class="mb-2 text-sm font-medium">
          {{ tl('game_restriction_section_content') }}
        </div>
        <ElFormItem :label="tl('game_restriction_restrict_type')">
          <ElSelect
            v-model="form.restrict_type"
            class="w-full md:w-1/2"
            @change="onRestrictTypeChange"
          >
            <ElOption
              :label="tl('game_restriction_type_vendor')"
              :value="MEMBER_RESTRICT_TYPE_VENDOR"
            />
            <ElOption
              :label="tl('game_restriction_type_game')"
              :value="MEMBER_RESTRICT_TYPE_GAME"
            />
          </ElSelect>
        </ElFormItem>

        <!-- 遊戲商類型：整批灰化該商，多選即可 -->
        <ElFormItem
          v-if="isVendorType"
          :label="tl('game_restriction_type_vendor')"
        >
          <ElSelect
            v-model="selectedVendorIds"
            class="w-full"
            collapse-tags
            collapse-tags-tooltip
            filterable
            multiple
          >
            <ElOption
              v-for="v in vendorOptions"
              :key="v.id"
              :label="vendorNameWithId({ id: v.id, name: v.name })"
              :value="v.id"
            />
          </ElSelect>
        </ElFormItem>

        <!-- 遊戲類型：遊戲商→類型→名稱 三層篩選，候選/已選雙欄（比照 4120） -->
        <template v-else>
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
                  v-for="v in vendorOptions"
                  :key="v.id"
                  :label="vendorNameWithId({ id: v.id, name: v.name })"
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
                  {{ tl('game_restriction_candidates') }}（{{
                    candidates.length
                  }}）
                </span>
                <ElCheckbox
                  :disabled="candidates.length === 0"
                  :model-value="
                    candidates.length > 0 &&
                    candidates.every((c) => isPicked(c.id))
                  "
                  @change="(v: any) => toggleAllCandidates(Boolean(v))"
                >
                  <span class="text-xs">{{ tl('select_all') }}</span>
                </ElCheckbox>
              </div>
              <div
                v-loading="candidateLoading"
                class="overflow-y-auto p-2"
                style="height: 220px"
              >
                <div
                  v-if="picker.vendorIds.length === 0"
                  class="text-xs text-gray-400"
                >
                  {{ tl('game_restriction_pick_vendor_first') }}
                </div>
                <div
                  v-else-if="candidates.length === 0"
                  class="text-xs text-gray-400"
                >
                  {{ tl('no_record') }}
                </div>
                <div v-for="g in candidates" :key="g.id" class="py-0.5">
                  <ElCheckbox
                    :model-value="isPicked(g.id)"
                    @change="(v: any) => toggleGame(g, Boolean(v))"
                  >
                    <span class="text-xs">
                      {{ g.name }}
                      <span class="text-gray-400">（{{ g.vendor_name }}）</span>
                    </span>
                  </ElCheckbox>
                </div>
              </div>
            </div>

            <!-- 已選清單 -->
            <div class="border-border rounded border">
              <div class="border-border border-b px-2 py-1">
                <span class="text-xs">
                  {{ tl('game_restriction_selected') }}（{{
                    selectedGames.length
                  }}）
                </span>
              </div>
              <div class="overflow-y-auto p-2" style="height: 220px">
                <div
                  v-if="selectedGames.length === 0"
                  class="text-xs text-gray-400"
                >
                  {{ tl('no_record') }}
                </div>
                <!-- 冠上遊戲商：各商常有同名遊戲，只列遊戲名分不出來源 -->
                <ElTag
                  v-for="g in selectedGames"
                  :key="g.id"
                  class="m-0.5"
                  closable
                  size="small"
                  @close="removeGame(g.id)"
                >
                  {{ g.vendor_name ? `(${g.vendor_name}) ${g.name}` : g.name }}
                </ElTag>
              </div>
            </div>
          </div>
        </template>

        <ElFormItem :label="tl('memo')" class="mt-3">
          <ElInput
            v-model="form.memo"
            :rows="2"
            maxlength="500"
            type="textarea"
          />
        </ElFormItem>
      </div>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="submit">
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
