<script lang="ts" setup>
import ProportionSetting from './components/proportion-setting.vue';

defineOptions({ name: 'ThreeHierarchyLoseProportionSetting' });
</script>

<template>
  <ProportionSetting :type="2" title-key="menu_lose_proportion_setting" />
</template>
