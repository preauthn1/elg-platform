<script setup lang="ts">
/**
 * 頁面依皮選畫面：pages/ 只放路由層的東西（definePageMeta、進頁守衛），
 * 畫面在 components/<皮>/Page/<name>.vue，邏輯在 composables。
 *
 * 這個皮沒有這一頁就導回首頁，照舊前台各皮 router 的 `path: "*" → redirect "/"`。
 */
const props = defineProps<{ name: string }>()

const { template } = useSite()
const component = computed(() => skinPage(template.value?.skin, props.name))

if (!component.value) {
  await navigateTo('/', { replace: true })
}
</script>

<template>
  <component
    :is="component"
    v-if="component"
  />
</template>
