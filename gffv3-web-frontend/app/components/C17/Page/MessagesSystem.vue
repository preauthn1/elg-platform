<script setup lang="ts">
/** C17 的畫面，邏輯在 useMessagesSystemPage。 */
const {
  selectSystemNotification,
  labels,
  page,
  items,
  pages,
  loading,
  loadFailed,
  statusClass,
} = useMessagesSystemPage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('messages.system.nav.notification')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <MessagesTabs />

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>
      <p
        v-else-if="loadFailed || items.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <ul
        v-else
        class="space-y-2"
      >
        <li
          v-for="item in items"
          :key="`${item.data_name}-${item.data_id}`"
        >
          <button
            type="button"
            class="block w-full rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-3 text-left"
            @click="selectSystemNotification(item)"
          >
            <div class="flex items-center justify-between text-sm">
              <span class="font-semibold">{{ labels.tagName(item) }}</span>
              <span class="text-(length:--c17-text-label) text-muted">{{ item.time }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between text-sm">
              <span>{{ labels.typeName(item) }}</span>
              <span :class="statusClass(item)">{{ labels.statusText(item) }}</span>
            </div>
            <div class="mt-1 text-lg font-semibold">
              {{ labels.contentText(item) }}
            </div>
          </button>
        </li>
      </ul>

      <UiPagination
        v-model:page="page"
        :pages="pages"
      />
    </div>
  </div>
</template>
