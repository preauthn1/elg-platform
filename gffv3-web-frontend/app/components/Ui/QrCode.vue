<script setup lang="ts">
/**
 * QR Code 圖。與舊前台同一個 `qrcode` 套件（`_source2` 的 QrcodeImg 也是它），
 * 差別是這裡在 onMounted 才動態載入——QR 只有瀏覽器要畫，SSR 端不需要，
 * 動態 import 也讓它不會被打進 server bundle。
 *
 * 畫出來的是 data URL，所以「保存到相簿」只要在 <a download> 上指同一個 src。
 */
const props = withDefaults(defineProps<{
  value: string
  /** 顯示邊長（px）；點陣圖以兩倍解析度產生，高 DPI 螢幕才不糊 */
  size?: number
}>(), { size: 200 })

const src = defineModel<string>('src', { default: '' })
const failed = ref(false)

async function render() {
  src.value = ''
  failed.value = false
  if (!props.value || !import.meta.client) return
  try {
    const { toDataURL } = await import('qrcode')
    src.value = await toDataURL(props.value, { width: props.size * 2, margin: 2 })
  }
  catch {
    failed.value = true
  }
}

onMounted(render)
watch(() => [props.value, props.size], render)
</script>

<template>
  <img
    v-if="src"
    :src="src"
    :alt="$t('agent.promote.label.qrCode')"
    class="block rounded-(--c17-radius-xs)"
    :style="{ width: `${size}px`, height: `${size}px` }"
  >
  <span
    v-else
    class="grid place-items-center rounded-(--c17-radius-xs) bg-surface-2"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: 'var(--c17-text-label)', color: 'var(--c17-muted)' }"
  >
    {{ failed ? $t('common.ui.empty.noData') : $t('common.ui.label.loading') }}
  </span>
</template>
