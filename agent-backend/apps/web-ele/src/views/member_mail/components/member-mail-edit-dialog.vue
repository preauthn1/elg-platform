<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElMessage,
  ElOption,
  ElSelect,
  ElSwitch,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getAgentViewByIdApi,
  getLanguagesApi,
  getMemberGroupDropdownApi,
  getMemberMailViewApi,
  getOrganizationDropdownApi,
  MAIL_LANGUAGE_EN,
  MAIL_SEND_TYPE_AGENT,
  MAIL_SEND_TYPE_GROUP,
  MAIL_SEND_TYPE_MEMBER,
  MAIL_SEND_TYPE_ORGANIZATION,
  saveMemberMailApi,
  searchAgentOptionsApi,
  searchMailMembersApi,
} from '#/api';
import { tl } from '#/locales';

defineOptions({ name: 'MemberMailEditDialog' });

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const editId = ref<number | string>('');
const loading = ref(false);
const submitting = ref(false);
/** 已到發布時間：整封信唯讀，僅能查看 */
const locked = ref(false);

const form = reactive({
  hierarchy_agent_id: '' as number | string,
  organization_id: '' as number | string,
  memo: '',
  publish_time: '',
  send_type: MAIL_SEND_TYPE_MEMBER as number,
  status: 1 as number,
});

// ── 多語系內容：語系清單開彈窗載一次，各語系獨立暫存 ──
const languages = ref<Array<{ id: number; name: string }>>([]);
const activeLanguage = ref(String(MAIL_LANGUAGE_EN));
const contents = reactive<Record<number, { content: string; title: string }>>(
  {},
);

function contentOf(languageId: number) {
  if (!contents[languageId]) {
    contents[languageId] = { content: '', title: '' };
  }
  return contents[languageId];
}

/** 分頁籤標記：已填內容的語系加點，一眼看出填了哪些 */
function isFilled(languageId: number): boolean {
  const c = contents[languageId];
  return Boolean(c && c.title.trim() && c.content.trim());
}

// ── 對象：指定會員（遠端搜尋多選） ──
const memberOptions = ref<Array<{ id: number; username: string }>>([]);
const memberSearching = ref(false);
const selectedMembers = ref<Array<{ id: number; username: string }>>([]);
const selectedMemberIds = computed({
  get: () => selectedMembers.value.map((m) => m.id),
  set: (ids: number[]) => {
    const idSet = new Set(ids);
    // 遠端搜尋切換關鍵字後選項會被替換，已選項要另存才不掉 label
    const kept = selectedMembers.value.filter(
      (m) => idSet.has(m.id) && !memberOptions.value.some((o) => o.id === m.id),
    );
    const picked = memberOptions.value.filter((o) => idSet.has(o.id));
    selectedMembers.value = [...kept, ...picked];
  },
});

async function searchMembers(keyword: string) {
  const q = (keyword ?? '').trim();
  if (!q) {
    memberOptions.value = [];
    return;
  }
  memberSearching.value = true;
  try {
    const res = await searchMailMembersApi({ keyword: q });
    memberOptions.value = Number(res.code) === 200 ? (res.data ?? []) : [];
  } finally {
    memberSearching.value = false;
  }
}

// ── 對象：會員群組（下拉多選） ──
const groupOptions = ref<Array<{ id: number; name: string }>>([]);

// ── 對象：全平臺（組織下拉，比照群發公告） ──
const orgOptions = ref<Array<{ id: number; name: string }>>([]);
const selectedGroupIds = ref<number[]>([]);

// ── 對象：代理及子代理（遠端搜尋單選） ──
const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const agentSearching = ref(false);

async function searchAgents(keyword: string) {
  const q = (keyword ?? '').trim();
  if (!q) {
    return;
  }
  agentSearching.value = true;
  try {
    const res = await searchAgentOptionsApi(q);
    agentOptions.value = res.data ?? [];
  } finally {
    agentSearching.value = false;
  }
}

function resetForm() {
  Object.assign(form, {
    hierarchy_agent_id: '',
    organization_id: '',
    memo: '',
    publish_time: '',
    send_type: MAIL_SEND_TYPE_MEMBER,
    status: 1,
  });
  for (const key of Object.keys(contents)) {
    delete contents[Number(key)];
  }
  activeLanguage.value = String(MAIL_LANGUAGE_EN);
  memberOptions.value = [];
  selectedMembers.value = [];
  selectedGroupIds.value = [];
  agentOptions.value = [];
  locked.value = false;
}

/** 切換發送類型時清掉其他類型的對象殘值，避免存到不相干的欄位 */
function onSendTypeChange() {
  form.organization_id = '';
  selectedMembers.value = [];
  memberOptions.value = [];
  selectedGroupIds.value = [];
  form.hierarchy_agent_id = '';
}

async function loadDropdowns() {
  const [langs, groups, orgs] = await Promise.all([
    getLanguagesApi().catch(() => []),
    getMemberGroupDropdownApi().catch(() => null),
    getOrganizationDropdownApi().catch(() => []),
  ]);
  orgOptions.value = ((orgs as any) ?? []).map((o: any) => ({
    id: Number(o.id),
    name: String(o.name ?? ''),
  }));
  // 英文排最前：它是 fallback 底線、必填
  languages.value = ((langs as any) ?? [])
    .map((l: any) => ({ id: Number(l.id), name: String(l.name ?? '') }))
    .toSorted(
      (a: any, b: any) =>
        Number(b.id === MAIL_LANGUAGE_EN) - Number(a.id === MAIL_LANGUAGE_EN),
    );
  groupOptions.value =
    groups && Number(groups.code) === 200
      ? (groups.data ?? []).map((g) => ({
          id: Number(g.id),
          name: String(g.name ?? ''),
        }))
      : [];
}

async function loadEdit(id: number | string) {
  const res = await getMemberMailViewApi(id).catch(() => null);
  if (!res || Number(res.code) !== 200 || !res.data) return;

  const d = res.data;
  form.send_type = Number(d.send_type);
  form.publish_time = d.publish_time;
  form.status = Number(d.status);
  form.memo = d.memo ?? '';
  locked.value = Boolean(d.is_published);

  for (const row of d.languages ?? []) {
    contents[Number(row.language_id)] = {
      content: row.content,
      title: row.title,
    };
  }

  if (Number(d.send_type) === MAIL_SEND_TYPE_MEMBER) {
    selectedMembers.value = d.members ?? [];
    memberOptions.value = d.members ?? [];
  } else if (Number(d.send_type) === MAIL_SEND_TYPE_GROUP) {
    selectedGroupIds.value = d.member_group_ids ?? [];
  } else if (Number(d.send_type) === MAIL_SEND_TYPE_ORGANIZATION) {
    form.organization_id = Number(d.organization_id);
  } else if (Number(d.send_type) === MAIL_SEND_TYPE_AGENT) {
    form.hierarchy_agent_id = Number(d.hierarchy_agent_id);
    // 只存了代理 id，反查帳號讓下拉顯示名稱而非數字
    const agent = await getAgentViewByIdApi(Number(d.hierarchy_agent_id)).catch(
      () => null,
    );
    agentOptions.value = agent
      ? [
          {
            id: Number(d.hierarchy_agent_id),
            username: String(
              (agent as any).username ?? `#${d.hierarchy_agent_id}`,
            ),
          },
        ]
      : [
          {
            id: Number(d.hierarchy_agent_id),
            username: `#${d.hierarchy_agent_id}`,
          },
        ];
  }
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
  // 英文必填：前台語系 fallback 的底線，沒英文會有會員看到空白信
  if (!isFilled(MAIL_LANGUAGE_EN)) {
    ElMessage.warning(tl('member_mail_english_required'));
    activeLanguage.value = String(MAIL_LANGUAGE_EN);
    return;
  }
  if (!form.publish_time) {
    ElMessage.warning(tl('member_mail_publish_time_required'));
    return;
  }
  if (
    form.send_type === MAIL_SEND_TYPE_MEMBER &&
    selectedMemberIds.value.length === 0
  ) {
    ElMessage.warning(tl('member_mail_members_required'));
    return;
  }
  if (
    form.send_type === MAIL_SEND_TYPE_GROUP &&
    selectedGroupIds.value.length === 0
  ) {
    ElMessage.warning(tl('member_mail_groups_required'));
    return;
  }
  if (form.send_type === MAIL_SEND_TYPE_ORGANIZATION && !form.organization_id) {
    ElMessage.warning(tl('member_mail_organization_required'));
    return;
  }
  if (form.send_type === MAIL_SEND_TYPE_AGENT && !form.hierarchy_agent_id) {
    ElMessage.warning(tl('member_mail_agent_required'));
    return;
  }

  const filledLanguages = languages.value
    .filter((l) => isFilled(l.id))
    .map((l) => {
      const c = contentOf(l.id);
      return {
        content: c.content,
        language_id: l.id,
        title: c.title.trim(),
      };
    });

  submitting.value = true;
  try {
    const res = await saveMemberMailApi({
      ...(editId.value ? { id: editId.value } : {}),
      languages: filledLanguages,
      memo: form.memo || undefined,
      publish_time: form.publish_time,
      send_type: form.send_type,
      status: form.status,
      ...(form.send_type === MAIL_SEND_TYPE_MEMBER
        ? { member_ids: selectedMemberIds.value }
        : {}),
      ...(form.send_type === MAIL_SEND_TYPE_GROUP
        ? { member_group_ids: selectedGroupIds.value }
        : {}),
      ...(form.send_type === MAIL_SEND_TYPE_ORGANIZATION
        ? { organization_id: form.organization_id }
        : {}),
      ...(form.send_type === MAIL_SEND_TYPE_AGENT
        ? { hierarchy_agent_id: form.hierarchy_agent_id }
        : {}),
    });
    if (Number(res.code) === 200) {
      ElMessage.success(tl('operation_success'));
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
    :close-on-click-modal="false"
    :title="locked ? tl('view') : editId ? tl('edit') : tl('add')"
    width="720px"
  >
    <ElForm v-loading="loading" :disabled="locked" label-width="110px">
      <ElFormItem :label="tl('member_mail_send_type')" required>
        <ElSelect
          v-model="form.send_type"
          class="w-full"
          @change="onSendTypeChange"
        >
          <ElOption :label="tl('member_mail_type_member')" :value="1" />
          <ElOption :label="tl('member_mail_type_group')" :value="2" />
          <ElOption :label="tl('member_mail_type_organization')" :value="3" />
          <ElOption :label="tl('member_mail_type_agent')" :value="4" />
        </ElSelect>
      </ElFormItem>

      <!-- 對象區塊：依類型切換 -->
      <ElFormItem
        v-if="form.send_type === MAIL_SEND_TYPE_MEMBER"
        :label="tl('member_mail_target')"
        required
      >
        <ElSelect
          v-model="selectedMemberIds"
          :loading="memberSearching"
          :placeholder="tl('member_mail_member_search_placeholder')"
          :remote-method="searchMembers"
          class="w-full"
          filterable
          multiple
          remote
          reserve-keyword
        >
          <ElOption
            v-for="m in memberOptions"
            :key="m.id"
            :label="m.username"
            :value="m.id"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem
        v-if="form.send_type === MAIL_SEND_TYPE_GROUP"
        :label="tl('member_mail_target')"
        required
      >
        <ElSelect v-model="selectedGroupIds" class="w-full" filterable multiple>
          <ElOption
            v-for="g in groupOptions"
            :key="g.id"
            :label="g.name"
            :value="g.id"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem
        v-if="form.send_type === MAIL_SEND_TYPE_ORGANIZATION"
        :label="tl('member_mail_target')"
        required
      >
        <ElSelect v-model="form.organization_id" class="w-full" filterable>
          <ElOption
            v-for="o in orgOptions"
            :key="o.id"
            :label="o.name"
            :value="o.id"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem
        v-if="form.send_type === MAIL_SEND_TYPE_AGENT"
        :label="tl('member_mail_target')"
        required
      >
        <ElSelect
          v-model="form.hierarchy_agent_id"
          :loading="agentSearching"
          :placeholder="tl('member_mail_agent_search_placeholder')"
          :remote-method="searchAgents"
          class="w-full"
          filterable
          remote
          reserve-keyword
        >
          <ElOption
            v-for="a in agentOptions"
            :key="a.id"
            :label="a.username"
            :value="a.id"
          />
        </ElSelect>
      </ElFormItem>

      <ElFormItem :label="tl('member_mail_publish_time')" required>
        <ElDatePicker
          v-model="form.publish_time"
          :placeholder="tl('member_mail_publish_time')"
          class="w-full"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm:ss"
        />
      </ElFormItem>

      <ElFormItem :label="tl('status')">
        <ElSwitch v-model="form.status" :active-value="1" :inactive-value="0" />
      </ElFormItem>

      <ElFormItem :label="tl('memo')">
        <ElInput
          v-model="form.memo"
          :rows="2"
          maxlength="500"
          type="textarea"
        />
      </ElFormItem>

      <!-- 多語系內容：英文必填（fallback 底線），其餘選填、未填語系前台自動退英文 -->
      <ElFormItem :label="tl('member_mail_content')" required>
        <ElTabs v-model="activeLanguage" class="w-full">
          <ElTabPane
            v-for="lang in languages"
            :key="lang.id"
            :name="String(lang.id)"
          >
            <template #label>
              <span>
                {{ lang.name }}
                <span v-if="isFilled(lang.id)" class="text-emerald-500">●</span>
                <span
                  v-else-if="lang.id === MAIL_LANGUAGE_EN"
                  class="text-red-500"
                  >*</span
                >
              </span>
            </template>
            <div class="flex flex-col gap-2">
              <ElInput
                v-model="contentOf(lang.id).title"
                :placeholder="tl('member_mail_title')"
                maxlength="200"
              />
              <ElInput
                v-model="contentOf(lang.id).content"
                :placeholder="tl('member_mail_content')"
                :rows="6"
                maxlength="20000"
                type="textarea"
              />
            </div>
          </ElTabPane>
        </ElTabs>
      </ElFormItem>
    </ElForm>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton
        v-if="!locked"
        :loading="submitting"
        type="primary"
        @click="submit"
      >
        {{ tl('confirm') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
