<script setup lang="ts">
import type { GameLike } from '#shared/utils/games'

/**
 * Android 點 VPower 遊戲時二選一（舊版 GameItem 的 showVpowerModal）：
 * HTML 走一般進場（$pushToGame），Android 叫起 VPower APP。
 * enterGame 回 vpower-notice 時由呼叫端掛上這個元件。
 */
const props = defineProps<{ game: GameLike }>()
const emit = defineEmits<{ close: [], blocked: [] }>()

const launcher = useGameLauncher()

async function playHtml() {
  try {
    await launcher.pushToGame(props.game)
    emit('close')
  }
  catch {
    emit('blocked')
  }
}

function playAndroid() {
  return launcher.launchVpowerAndroid(props.game.vendor_id)
}
</script>

<template>
  <UiModal
    :title="game.name ?? ''"
    @close="emit('close')"
  >
    <div class="flex flex-col gap-(--c17-gap-4) pt-(--c17-gap-2)">
      <div class="flex justify-center gap-10">
        <button
          type="button"
          aria-label="HTML"
          @click="playHtml"
        >
          <img
            src="/skins/c17/lobby/entry-html.webp"
            alt=""
            class="size-20 object-contain"
          >
        </button>
        <button
          type="button"
          aria-label="Android"
          @click="playAndroid"
        >
          <img
            src="/skins/c17/lobby/entry-android.webp"
            alt=""
            class="size-20 object-contain"
          >
        </button>
      </div>
      <UiButton @click="emit('close')">
        {{ $t('common.ui.action.cancel') }}
      </UiButton>
    </div>
  </UiModal>
</template>
