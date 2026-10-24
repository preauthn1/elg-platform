<script lang="ts" setup>
import type { AgentRow } from '#/api';

import { computed, reactive, ref } from 'vue';

import {
  ElButton,
  ElCard,
  ElDialog,
  ElInput,
  ElMessage,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getAgentViewByIdApi,
  getCustomizedSettingApi,
  getShareholderSharePointApi,
  saveShareholderNicknameAndPointApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const agentId = ref(0);
const loading = ref(false);
const saving = ref(false);
const profile = ref<AgentRow>({} as AgentRow);
const nickname = ref('');
const sharePoint = reactive({
  parent: '' as number | string,
  self: '' as number | string,
});
const shareVisible = ref(true);
const shareEditable = ref(true);
const activeTab = ref('1');

function encMobile(m = '') {
  const a = [...m];
  return '*'.repeat(Math.max(0, a.length - 3)) + a.slice(-3).join('');
}
function encEmail(e = '') {
  const [local = '', domain = ''] = e.split('@');
  const a = [...local];
  return `${a.slice(0, 2).join('')}${'*'.repeat(Math.max(0, a.length - 2))}@${domain}`;
}

const mobileText = computed(() =>
  profile.value.mobile && String(profile.value.mobile).trim()
    ? encMobile(String(profile.value.mobile))
    : '--',
);
const emailText = computed(() =>
  profile.value.email && String(profile.value.email).trim()
    ? encEmail(String(profile.value.email))
    : '--',
);

/** 股份只允許整數（op_tickets#3650） */
const onlyInt = (v: number | string) => String(v).replaceAll(/\D/g, '');

async function load() {
  loading.value = true;
  try {
    const [agent, sp, settings] = await Promise.all([
      getAgentViewByIdApi(agentId.value),
      getShareholderSharePointApi(agentId.value),
      getCustomizedSettingApi(),
    ]);
    profile.value = (agent as AgentRow) ?? ({} as AgentRow);
    nickname.value = String((profile.value as any).nickname ?? '');
    if (Number(sp.code) === 1000) {
      sharePoint.parent = sp.data?.parent_share_point ?? '';
      sharePoint.self = sp.data?.self_share_point ?? '';
    }
    const find = (k: string) =>
      (settings ?? []).find((s: any) => String(s.setting_key) === k)?.value;
    shareVisible.value = Number(find('95004') ?? 1) === 1;
    shareEditable.value = Number(find('95005') ?? 1) === 1;
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const res = await saveShareholderNicknameAndPointApi({
      id: agentId.value,
      nickname: nickname.value,
      lower_share_point: sharePoint.self,
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else if (String(res.code) === '2002') {
      ElMessage.error(String(res.data ?? ''));
    } else {
      ElMessage.error(res.message);
    }
  } finally {
    saving.value = false;
  }
}

function open(id: number | string) {
  agentId.value = Number(id ?? 0);
  profile.value = {} as AgentRow;
  nickname.value = '';
  sharePoint.parent = '';
  sharePoint.self = '';
  activeTab.value = '1';
  visible.value = true;
  load();
}

defineExpose({ open });
</script>

<template>
  <ElDialog v-model="visible" :title="tl('details')" width="760">
    <ElCard v-loading="loading" shadow="never">
      <ElTabs v-model="activeTab">
        <ElTabPane :label="tl('account_details')" name="1">
          <div class="max-w-2xl space-y-4 pt-4">
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('type') }} :</label>
              <span>{{ tl('shareholder') }}</span>
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('username') }} :</label>
              <span>{{ profile.username }}</span>
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('nickname') }} :</label>
              <ElInput
                v-model="nickname"
                class="!w-64"
                placeholder="nickname"
              />
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('mobile') }} :</label>
              <span>{{ mobileText }}</span>
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('email') }} :</label>
              <span>{{ emailText }}</span>
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right"
                >{{ tl('last_login') }} :</label
              >
              <span>{{ (profile as any).last_login }}</span>
            </div>
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">{{ tl('status') }} :</label>
              <span>{{ tl(`status_${(profile as any).status}`) }}</span>
            </div>
          </div>
        </ElTabPane>

        <ElTabPane :label="tl('settings')" name="2">
          <div class="max-w-2xl space-y-4 pt-4">
            <div class="flex items-center">
              <label class="w-32 pr-4 text-right">
                {{ tl('login_account') }} :
              </label>
              <span>{{ profile.username }}</span>
            </div>
            <div class="flex items-start">
              <label class="w-32 pr-4 text-right">{{ tl('share') }} :</label>
              <table
                class="flex-1 border-collapse text-sm [&_td]:border [&_td]:p-2 [&_th]:border [&_th]:p-2"
              >
                <thead>
                  <tr>
                    <th class="w-3/5">{{ tl('gaming_platform_group') }}</th>
                    <th class="w-2/5">{{ tl('shares') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Settlement</td>
                    <td>
                      <template v-if="shareVisible">
                        <ElInput
                          v-model="sharePoint.self"
                          :formatter="onlyInt"
                          :parser="onlyInt"
                          :disabled="!shareEditable"
                          class="!w-32"
                          placeholder="share point"
                        />
                        / {{ sharePoint.parent }}
                      </template>
                      <span v-else>-</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('cancel') }}</ElButton>
      <ElButton :loading="saving" type="primary" @click="save">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
