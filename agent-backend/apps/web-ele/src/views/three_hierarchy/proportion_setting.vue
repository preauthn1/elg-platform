<script lang="ts" setup>
import ProportionSetting from './components/proportion-setting.vue';

defineOptions({ name: 'ThreeHierarchyProportionSetting' });
</script>

<template>
  <ProportionSetting
    :type="1"
    title-key="menu_three_layers_proportion_setting"
  />
</template>
