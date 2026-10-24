<script setup lang="ts">
/** 依目前站台的皮套外殼（components/<皮>/Shell.vue），由唯一的 layout 使用。 */
const { template } = useSite()
const shell = computed(() => skinShell(template.value?.skin))
</script>

<template>
  <component :is="shell">
    <slot />
  </component>
</template>
