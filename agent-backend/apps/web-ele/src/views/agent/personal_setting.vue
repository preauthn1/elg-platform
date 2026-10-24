<script lang="ts" setup>
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  getAgentPersonalSettingsApi,
  updateAgentPersonalSettingsApi,
} from '#/api';
import { tl } from '#/locales';

import SettingsGroupsPanel from './components/settings-groups-panel.vue';

defineOptions({ name: 'AgentPersonalSetting' });

const route = useRoute();
const agentId = (route.query.agent_id as string) || undefined;
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_other_setting')}`"
  >
    <SettingsGroupsPanel
      :agent-id="agentId"
      :load-fn="getAgentPersonalSettingsApi"
      :save-fn="updateAgentPersonalSettingsApi"
    />
  </Page>
</template>
