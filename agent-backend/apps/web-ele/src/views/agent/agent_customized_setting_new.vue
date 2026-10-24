<script lang="ts" setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  getGroupCustomizedSettingsApi,
  updateGroupCustomizedSettingsApi,
} from '#/api';
import { tl } from '#/locales';

import SettingsGroupsPanel from './components/settings-groups-panel.vue';

defineOptions({ name: 'AgentCustomizedSettingNew' });

const props = defineProps<{ embedGroupId?: string }>();

const route = useRoute();
const groupId = props.embedGroupId || (route.query.group_id as string) || '';

const groupName = ref('');

/** 共用面板以 agentId 當 targetId 傳入 loadFn；此處即 group_id。 */
async function loadFn(id: number | string) {
  const res = await getGroupCustomizedSettingsApi(id);
  if (Number(res.code) === 1000 && res.data) {
    groupName.value = res.data.group_name ?? '';
  }
  return res;
}

/** 面板組好的 payload 以 agent_id 帶 targetId；群組版改用 group_id。 */
async function saveFn(payload: Record<string, any>) {
  const { agent_id, ...rest } = payload;
  return updateGroupCustomizedSettingsApi({ ...rest, group_id: agent_id });
}
</script>

<template>
  <Page
    :content-class="embedGroupId ? 'px-0' : ''"
    :title="`${tl('agnet_management')} > ${tl('menu_agent_advance_setting')}`"
  >
    <template #description>
      <b>{{ tl('name') }}</b
      >：{{ groupName }}
      <span class="text-muted-foreground">({{ groupId }})</span>
    </template>

    <SettingsGroupsPanel
      :agent-id="groupId"
      :load-fn="loadFn"
      :save-fn="saveFn"
    />
  </Page>
</template>
