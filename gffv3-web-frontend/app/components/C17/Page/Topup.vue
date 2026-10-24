<script setup lang="ts">
/**
 * C17 存款頁（設計稿 Wallet-page，390×844）。量到的規格：
 *
 *   標題列  390×52 @0,0    #1565FF→#1A1F36 260°（= --c17-header-bg）
 *           返回 8×12 @16,21、標題 PJS SemiBold 12 #F1F4F8 @36,19
 *           餘額膠囊 174×36 @200,8（= C17BalancePill）
 *   分段    358×32 @17,68  r8 底 #F1F4F8 邊框 #B4B4B4
 *           作用中那半 178×32 r8 #1565FF，字 PJS Bold 12；未選的字 #7A8599
 *
 * 標題列自己畫，所以不掛 AppHeader。進頁檢查與存款方式在 useTopupEntry。
 */
const router = useRouter()
const { loading, blocked, loadFailed, active, tabs } = useTopupEntry()
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('deposit.common.title.deposit')"
      fallback="/member"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pb-(--c17-gap-4) pt-(--c17-gap-4)">
      <template v-if="showSkeleton">
        <UiSkeleton class="aspect-[39/14] w-full rounded-lg" />
        <UiSkeleton :rows="5" />
      </template>

      <template v-else-if="!blocked">
        <p
          v-if="loadFailed || tabs.length === 0"
          class="text-sm text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
        <template v-else>
          <!-- 分段切換：整條是灰底外框，作用中的那一半疊一塊藍底上去 -->
          <nav
            class="grid h-8 rounded-(--c17-field-radius) border"
            :class="tabs.length > 1 ? 'grid-cols-2' : 'grid-cols-1'"
            :style="{ background: 'var(--c17-primary-ink)', borderColor: 'var(--c17-faint)' }"
          >
            <button
              v-for="tab in tabs"
              :key="tab.key"
              type="button"
              class="truncate rounded-(--c17-field-radius) px-2 font-bold"
              :style="{
                fontSize: 'var(--c17-text-link)',
                background: tab.key === active ? 'var(--c17-primary)' : 'transparent',
                color: tab.key === active ? 'var(--c17-primary-ink)' : 'var(--c17-muted)',
              }"
              @click="active = tab.key"
            >
              {{ tab.name }}
            </button>
          </nav>

          <DepositOnlinePayment v-if="active === 'quickRecharge'" />
          <DepositBankTransfer v-else-if="active === 'topup'" />
        </template>
      </template>

      <!-- 照舊前台 C13 Topup.vue：入口只看登入，has_deposit_record_page 只管紀錄頁裡的分頁 -->
      <NuxtLink
        to="/record/deposit"
        class="text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('records.tab.nav.deposit') }}
      </NuxtLink>
    </div>

    <DepositMessageBox
      v-if="blocked"
      :message="$t('deposit.entry.error.eventBlocked')"
      @close="router.back()"
    />

    <EventsPrizeReminder />
  </div>
</template>
