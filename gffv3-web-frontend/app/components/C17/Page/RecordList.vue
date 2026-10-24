<script setup lang="ts">
import { formatRecordAmount } from '~/utils/records'

/** C17 紀錄列表。資料在 useRecordList，這裡只負責把選中的種類晶片捲回可視範圍。 */
const {
  store,
  tabs,
  walletPointText,
  gameTransferLogs,
  customerServiceLogs,
  redEnvelopeLogs,
  type,
  currentTab,
  isRedEnvelope,
  loading,
  loadFailed,
  listLength,
  statusText,
} = useRecordList()

/**
 * 每個紀錄種類是不同路由，切換時整頁重掛、晶片列的橫向捲動會歸零；
 * 掛上後把選中的晶片捲回可視範圍（置中），不然點後面的晶片會跳回最前面。
 */
const tabNav = ref<HTMLElement | null>(null)

function revealActiveTab() {
  const nav = tabNav.value
  const active = nav?.querySelector<HTMLElement>('[aria-current="page"]')
  if (!nav || !active) return
  nav.scrollLeft = active.offsetLeft - nav.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2
}

onMounted(revealActiveTab)
</script>

<template>
  <div>
    <C17PageHeader
      :title="currentTab ? $t(currentTab.titleKey) : $t('records.common.title.recordList')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <!-- 紀錄種類：可橫向捲動的晶片列，選中的是實心藍 -->
      <nav
        ref="tabNav"
        class="-mx-(--c17-pad-x) flex gap-(--c17-gap-2) overflow-x-auto px-(--c17-pad-x) [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <NuxtLink
          v-for="tab in tabs"
          :key="tab.type"
          :to="`/record/${tab.type}`"
          class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            background: tab.type === type ? 'var(--c17-primary)' : 'var(--c17-surface)',
            color: tab.type === type ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
            borderColor: tab.type === type ? 'var(--c17-primary)' : 'var(--c17-faint)',
          }"
        >
          {{ $t(tab.titleKey) }}
        </NuxtLink>
      </nav>

      <p
        v-if="!type"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <p
        v-else-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>

      <p
        v-else-if="loadFailed || listLength === 0"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>

      <template v-else>
        <ul
          v-if="type === 'deposit'"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in store.depositRecords"
            :key="record.order_id"
          >
            <NuxtLink
              :to="`/record/deposit/${record.order_id}`"
              class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
            >
              <img
                v-if="record.image_path"
                :src="record.image_path"
                alt=""
                class="h-5"
              >
              <div class="mr-auto">
                <div>{{ record.order_type }}</div>
                <div class="text-(length:--c17-text-label) text-muted">
                  {{ record.created_at }}
                </div>
              </div>
              <div class="text-right">
                <div>{{ formatRecordAmount(record.amount) }}</div>
                <div class="text-(length:--c17-text-label) text-muted">
                  {{ formatRecordAmount(record.point) }} {{ walletPointText }}
                </div>
              </div>
              <span class="w-16 text-right text-xs">{{ statusText(record.status) }}</span>
            </NuxtLink>
          </li>
        </ul>

        <ul
          v-else-if="type === 'event'"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in store.prizeRecords"
            :key="record.id"
          >
            <NuxtLink
              :to="`/record/event/${record.id}`"
              class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
            >
              <div class="mr-auto">
                <div>{{ record.event_name }}</div>
                <div class="text-(length:--c17-text-label) text-muted">
                  {{ record.updated_at }}
                </div>
              </div>
              <div class="text-right">
                <div>{{ record.name }}</div>
                <div class="text-(length:--c17-text-label) text-muted">
                  {{ record.point }}
                </div>
              </div>
              <span class="w-16 text-right text-xs">{{ statusText(record.status) }}</span>
            </NuxtLink>
          </li>
        </ul>

        <ul
          v-else-if="type === 'platform-transfer'"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in gameTransferLogs"
            :key="record.id"
            class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
          >
            <div class="mr-auto">
              <div>{{ record.vendor_name }}</div>
              <div class="text-(length:--c17-text-label) text-muted">
                {{ record.updated_at }}
              </div>
            </div>
            <div class="text-right">
              <div>{{ record.type === 1 ? $t('records.enum.transactionType.vendorTransferIn') : $t('records.enum.transactionType.vendorTransferOut') }}</div>
              <div class="text-(length:--c17-text-label) text-muted">
                {{ formatRecordAmount(record.point) }} {{ walletPointText }}
              </div>
            </div>
            <span class="w-16 text-right text-xs">{{ statusText(record.status) }}</span>
          </li>
        </ul>

        <ul
          v-else-if="type === 'withdrawal'"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in store.withdrawalRecords"
            :key="record.id"
          >
            <NuxtLink
              :to="`/record/withdrawal/${record.id}`"
              class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
            >
              <img
                v-if="record.bank_icon"
                :src="record.bank_icon"
                alt=""
                class="h-5"
              >
              <div class="mr-auto">
                <div>{{ record.bank_name }}</div>
                <div class="text-(length:--c17-text-label) text-muted">
                  {{ record.created_at }}
                </div>
              </div>
              <div>{{ formatRecordAmount(record.amount) }}</div>
              <span class="w-16 text-right text-xs">{{ statusText(record.icon_status) }}</span>
            </NuxtLink>
          </li>
        </ul>

        <ul
          v-else-if="type === 'customer-service'"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in customerServiceLogs"
            :key="record.id"
            class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
          >
            <div class="mr-auto">
              <div>{{ record.id }}</div>
              <div class="text-(length:--c17-text-label) text-muted">
                {{ record.created_at }}
              </div>
            </div>
            <div class="text-right">
              <div>{{ record.type === 1 ? $t('records.enum.transactionType.vendorTransferIn') : $t('records.enum.transactionType.vendorTransferOut') }}</div>
              <div class="text-(length:--c17-text-label) text-muted">
                {{ formatRecordAmount(record.point) }} {{ walletPointText }}
              </div>
            </div>
          </li>
        </ul>

        <ul
          v-else-if="isRedEnvelope"
          class="divide-y divide-line overflow-hidden rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)]"
        >
          <li
            v-for="record in redEnvelopeLogs"
            :key="record.id"
            class="flex items-center gap-3 px-(--c17-gap-3) py-(--c17-gap-2) text-(length:--c17-text-link)"
          >
            <div class="mr-auto">
              <div>{{ $t('records.redEnvelope.label.friend') }}: {{ record.nickname }}</div>
              <div class="text-(length:--c17-text-label) text-muted">
                {{ record.created_at }}
              </div>
            </div>
            <div>{{ formatRecordAmount(record.point) }} {{ walletPointText }}</div>
          </li>
        </ul>
      </template>
    </div>
  </div>
</template>
