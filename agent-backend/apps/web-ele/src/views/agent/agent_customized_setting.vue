<script lang="ts" setup>
import { useRoute } from 'vue-router';

import { Page } from '@vben/common-ui';

import {
  getAgentCustomizedSettingsApi,
  updateAgentCustomizedSettingsApi,
} from '#/api';
import { tl } from '#/locales';

import SettingsGroupsPanel from './components/settings-groups-panel.vue';

defineOptions({ name: 'AgentCustomizedSetting' });

const route = useRoute();
const agentId = (route.query.agent_id as string) || undefined;
</script>

<template>
  <Page
    :title="`${tl('agnet_management')} > ${tl('menu_agent_customized_setting')}`"
  >
    <SettingsGroupsPanel
      :agent-id="agentId"
      :load-fn="getAgentCustomizedSettingsApi"
      :save-fn="updateAgentCustomizedSettingsApi"
    />
  </Page>
</template>
