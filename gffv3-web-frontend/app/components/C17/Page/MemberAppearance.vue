<script setup lang="ts">
import { MEMBER_PATH } from '#shared/utils/member'

/**
 * 外觀設定。對齊舊前台 C13/C16 的 AppearanceSettings。
 * 舊版切換後 window.location.reload()，這裡直接換 <html data-appearance>，不用重載。
 */

const { mode, hasAlt, apply } = useAppearance()

/**
 * 0 = 設計稿原本那套、1 = 後來追加的那套。
 * **文案要依這個皮的實際情況調**：基底是白藍、追加深色，就是下面這樣；
 * 若某個皮的基底是黑藍、追加的是淺色，這兩個 labelKey 要對調。
 */
const options = [
  { value: 0 as const, labelKey: 'member.appearance.option.lightMode' },
  { value: 1 as const, labelKey: 'member.appearance.option.darkMode' },
]
</script>

<template>
  <div class="space-y-(--c17-gap-4) px-(--c17-pad-x) py-(--c17-gap-4)">
    <h1
      class="font-extrabold"
      :style="{ fontFamily: 'var(--c17-font-label)', fontSize: 'var(--c17-text-lg)' }"
    >
      {{ $t('member.appearance.title.settings') }}
    </h1>

    <p
      v-if="!hasAlt"
      class="text-sm text-muted"
    >
      {{ $t('games.vendor.label.comingSoon') }}
    </p>

    <div
      v-else
      class="space-y-(--c17-gap-2)"
    >
      <button
        v-for="option in options"
        :key="option.value"
        type="button"
        class="flex h-(--c17-btn-h) w-full items-center justify-center rounded-(--c17-field-radius) border font-bold"
        :class="mode === option.value
          ? 'border-accent bg-accent text-inverse'
          : 'border-line bg-surface text-ink'"
        @click="apply(option.value)"
      >
        {{ $t(option.labelKey) }}
      </button>
    </div>

    <NuxtLink
      :to="MEMBER_PATH.setting"
      class="inline-block text-sm text-accent"
    >
      {{ $t('member.common.action.set') }}
    </NuxtLink>
  </div>
</template>
