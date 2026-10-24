<script setup lang="ts">
import { showsVendorBalanceInHeader } from '#shared/utils/games'

/**
 * C17 APP 場館：遊戲商內頁，頂部用有返回鍵的 C17PageHeader，膠囊顯示遊戲商餘額（照舊版 GameApp 的 Navbar）。
 * 載入順序與各動作在 useVendorApp。
 */
const store = useGamesStore()
const {
  vendorId,
  vendor,
  failed,
  loading,
  newPassword,
  copied,
  appInfo,
  canSubmit,
  openApp,
  download,
  copyUsername,
  submitPassword,
} = useVendorApp()
const showSkeleton = useSkeleton(() => loading.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="vendor?.name ?? ''"
      fallback="/vendors"
      :balance="showsVendorBalanceInHeader(vendorId)"
      balance-source="vendor"
      :giftcode="vendor?.giftcode"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <span
        v-if="vendor?.giftcode === 1"
        class="self-start rounded-(--c17-radius-sm) bg-surface px-(--c17-gap-2) py-(--c17-gap-1) text-(length:--c17-text-label) text-muted shadow-[var(--c17-shadow-panel)]"
      >{{ $t('games.vendor.label.rollingGames') }}</span>

      <p
        v-if="failed"
        class="text-(length:--c17-text-link) text-danger"
      >
        {{ $t('games.launch.error.network') }}
      </p>
      <div
        v-else-if="showSkeleton"
        class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
      >
        <UiSkeleton :rows="3" />
      </div>

      <template v-else-if="store.vendorInfo && appInfo">
        <section
          v-for="event in store.activeVendorEvents"
          :key="event.detail_id"
          class="flex flex-col gap-(--c17-gap-1) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        >
          <h2 class="text-(length:--c17-text-body) font-bold text-ink">
            {{ event.title }}
          </h2>
          <p class="text-(length:--c17-text-link) text-muted">
            {{ (event.games ?? []).map(g => g.name).join('、') }}
          </p>
        </section>

        <section class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
          <h2 class="text-(length:--c17-text-body) font-bold text-ink">
            {{ $t(appInfo.hasApp ? 'games.launch.action.open' : 'games.launch.action.downloadApp') }}
          </h2>
          <div
            v-if="appInfo.hasApp"
            class="grid grid-cols-2 gap-(--c17-gap-3)"
          >
            <UiButton @click="openApp">
              {{ $t('common.ui.action.open') }}
            </UiButton>
            <UiButton
              variant="secondary"
              @click="download(0)"
            >
              {{ $t('games.launch.action.download') }}
            </UiButton>
          </div>
          <div
            v-else
            class="grid grid-cols-2 gap-(--c17-gap-3)"
          >
            <UiButton
              v-for="(link, index) in appInfo.downloads"
              :key="link"
              variant="secondary"
              @click="download(index)"
            >
              {{ $t('games.launch.action.download') }} {{ index + 1 }}
            </UiButton>
          </div>
          <p class="whitespace-pre-wrap text-(length:--c17-text-link) text-muted">
            <template v-if="appInfo.hasApp">
              {{ $t('games.launch.hint.afterDownload') }}
              {{ $t('games.launch.hint.returnToScreen') }}
            </template>
            <template v-else>
              {{ $t('games.launch.hint.alreadyDownloaded') }}
              {{ $t('games.launch.hint.desktopAppIcon') }}
            </template>
          </p>
        </section>

        <section
          v-if="!appInfo.hasApp"
          class="flex flex-col gap-(--c17-gap-3) rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        >
          <dl class="grid grid-cols-[auto_1fr_auto] items-center gap-x-(--c17-gap-3) gap-y-(--c17-gap-2) text-(length:--c17-text-link)">
            <dt class="text-muted">
              {{ $t('auth.login.label.account') }}
            </dt>
            <dd class="font-mono text-ink">
              {{ store.vendorInfo.username }}
            </dd>
            <dd>
              <button
                type="button"
                class="font-bold text-accent"
                @click="copyUsername"
              >
                {{ $t(copied ? 'common.ui.msg.copied' : 'common.ui.action.copy') }}
              </button>
            </dd>
            <dt class="text-muted">
              {{ $t('auth.login.label.originalPassword') }}
            </dt>
            <dd class="col-span-2 font-mono text-ink">
              {{ store.vendorInfo.password ?? '' }}
            </dd>
          </dl>

          <p class="text-(length:--c17-text-link) text-ink">
            {{ $t('games.launch.hint.changePassword') }}<br>
            {{ $t('auth.password.hint.useNewPassword') }}
          </p>

          <form
            class="flex flex-col gap-(--c17-gap-3)"
            @submit.prevent="submitPassword"
          >
            <UiTextField
              v-model="newPassword"
              :label="$t('member.password.label.newPassword')"
              type="password"
              :maxlength="14"
              autocomplete="new-password"
              :hint="`${$t('validation.field.error.alphanumeric')} (6-14)`"
            />
            <UiButton
              type="submit"
              :disabled="!canSubmit"
            >
              {{ $t('member.password.title.changePassword') }}
            </UiButton>
          </form>
        </section>
      </template>
    </div>
  </div>
</template>
