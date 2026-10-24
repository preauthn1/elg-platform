<script setup lang="ts">
/**
 * 設定選單。設計稿沒有這一張，沿用 C17 內頁的語彙：
 * 深色標題列 ＋ 白卡列表，每列右邊一個藍色 `>`（與個人資料頁的 VIP 入口同一顆）。
 */
const {
  items,
} = useSettingMenu()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.common.action.set')"
      fallback="/member"
      :balance="false"
    />

    <div class="px-(--c17-pad-x) py-(--c17-gap-4)">
      <ul
        class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface"
        :style="{ boxShadow: 'var(--c17-shadow-panel)' }"
      >
        <li
          v-for="item in items"
          :key="item.key"
        >
          <NuxtLink
            :to="item.to"
            class="flex items-center gap-(--c17-gap-3) px-(--c17-gap-3) py-(--c17-gap-3)"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
          >
            <C17SettingIcon :name="item.key" />
            <span class="min-w-0 flex-1 truncate font-semibold">{{ item.i18n ? $t(item.i18n) : item.plain }}</span>
            <svg
              viewBox="0 0 8 12"
              class="h-3 w-2 shrink-0"
              fill="currentColor"
              :style="{ color: 'var(--c17-primary)' }"
              aria-hidden="true"
            >
              <path d="M1.9 0 .5 1.4 5.1 6 .5 10.6 1.9 12 7.9 6z" />
            </svg>
          </NuxtLink>
        </li>
      </ul>
    </div>
  </div>
</template>
