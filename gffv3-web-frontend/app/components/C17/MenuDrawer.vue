<script setup lang="ts">
import { gameTypeLabelKey, rootTypeIds } from '#shared/utils/games'
import { decFormat } from '#shared/utils/amount'
import { depositDifference, progressPercent, toAmount } from '#shared/utils/vip'
import { MEMBER_PATH } from '#shared/utils/member'
import { unreadBadgeText } from '#shared/utils/messages'
import { C17_LANGUAGES, languageFlag } from '#shared/constants/languages'

/**
 * 主選單（設計稿 Menu）：蓋在**當前頁面**上的右側抽屜，左邊仍看得到底下的頁面。
 *
 * **不是路由。** 做成 /menu 頁面的話，點底部分頁列的 Menu 會整頁切走、底下變成一片灰底。
 * 由 C17Shell 掛載，開關在 useMenuDrawer；換頁時自動關。
 * /menu 保留一個轉址殼給書籤與舊連結。
 *
 * 除了導覽路徑以外都吃 API：member/detail、vip/info、vip/contents、wallet、
 * vendor/game-types（與首頁同一支）。
 */
const route = useRoute()
const { open, hide } = useMenuDrawer()
const { loggedIn } = useUserSession()
const { site, currencyLabel: currency } = useSite()
const member = useMember()
const wallet = useWalletStore()
const vipStore = useVipStore()
const messages = useMessagesStore()
const { logout } = useAuth()
const { fetchGameTypes } = useGames()
const { ensureInfo } = useVip()
const { locale, setLocale } = useI18n()

/**
 * **不能 await**：這是 C17Shell 裡的一般元件不是頁面，頂層 await 會讓它變成非同步元件，
 * 外面沒有 Suspense 就整個不渲染。default 撐初值，資料到了自動補上。
 */
const { data: gameTypes } = useAsyncData('menu-game-types', async () => {
  const res = await fetchGameTypes()
  return isApiOk(res) ? res.data : []
}, { default: () => [] })

onMounted(async () => {
  if (!loggedIn.value) return
  await Promise.all([member.ensureLoaded(), ensureInfo()])
})

/**
 * 設計稿的分類清單**只有這四項**。圖示是 Menu 另外匯出的小圖，跟首頁大磚不同組。
 *
 * **後台開了但設計稿沒列的類型不補**：全排進來會多出七列沒有圖示的文字，選單被撐到
 * 1600 高，把「聯絡客服／下載 APP／語系／登出」擠到畫面外。那些類型在 /vendors
 * 的分類列每個都有一顆磚，不會進不去。
 */
const MENU_TYPE_ORDER = [1, 18, 4, 5] as const
const TYPE_ICON: Record<number, string> = {
  1: 'live-casino',
  2: 'slot',
  4: 'lottery',
  5: 'fishing',
  18: 'sports',
}
const categories = computed(() => {
  const available = rootTypeIds(gameTypes.value)
  return MENU_TYPE_ORDER.filter(id => available.includes(id))
})

/** VIP 升級進度：目前累積存款 / 下一級門檻，都來自 vip/info + vip/contents。 */
const vip = computed(() => {
  const info = example.invalid
  if (!info) return null
  const next = vipStore.nextVip
  const current = toAmount(info.deposit_amount)
  const target = next ? toAmount(next.target_amount) : 0
  return {
    level: vipStore.level,
    nextLevel: next ? Number(next.level) : null,
    current,
    target,
    remaining: depositDifference(info, next),
    percent: target > 0 ? progressPercent(current, 0, target) : 100,
  }
})

const balance = computed(() => decFormat(wallet.balance, 2))

/** 六宮格快捷。路徑是前端決定的導覽，不是後端資料。 */
const shortcuts = [
  { key: 'home', to: '/', labelKey: 'site.nav.nav.home' },
  { key: 'slot', to: '/vendors?typeId=2', labelKey: 'games.enum.category.slot' },
  { key: 'vip', to: '/vip', labelKey: 'vip.overview.title.vip' },
  { key: 'invite', to: '/agent', labelKey: 'events.invite.action.inviteFriends' },
  { key: 'promo', to: '/promotions', labelKey: 'site.nav.nav.promotion' },
  { key: 'inbox', to: '/messages/mail', labelKey: 'messages.common.nav.inbox' },
]

/** 關閉就是關閉，不換頁——底下的頁面一直都在。 */
function close() {
  hide()
}

/** 抽屜裡的連結一點就換頁；換了頁抽屜要跟著收起來。 */
watch(() => route.fullPath, () => hide())

async function onLogout() {
  await logout()
  hide()
  await navigateTo('/')
}

const showLanguages = ref(false)

/** 下載 APP＝把網站加到主畫面；叫不出安裝視窗就顯示手動加入的說明。 */
const pwa = usePwaInstall()
const pwaHint = ref(false)
async function onInstallApp() {
  pwaHint.value = (await pwa.install()) === 'unsupported'
}
</script>

<template>
  <UiDrawer
    :open="open"
    :title="site?.raw.title ?? ''"
    @close="close"
  >
    <template #title>
      <img
        v-if="site?.raw.logo_mobile || site?.raw.logo_pc"
        :src="site?.raw.logo_mobile || site?.raw.logo_pc || ''"
        :alt="site?.raw.title ?? ''"
        class="h-[34px] w-auto max-w-[90px] object-contain"
      >
      <template v-else>
        {{ site?.raw.title ?? '' }}
      </template>
    </template>

    <div class="space-y-(--c17-gap-3)">
      <!--
        會員卡：底是**主色 10%**（不是 accent-soft）。兩列：帳號 + VIP 膠囊、
        目前等級 + 升級進度條。未登入時改顯示登入／註冊入口。
      -->
      <div
        v-if="loggedIn"
        class="space-y-(--c17-gap-1) rounded-(--c17-field-radius) p-(--c17-gap-2)"
        :style="{ background: 'color-mix(in srgb, var(--c17-primary) 10%, transparent)' }"
      >
        <div class="flex items-center gap-(--c17-gap-2)">
          <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-hover text-inverse">
            <svg
              viewBox="0 0 24 24"
              class="size-4"
              fill="currentColor"
            >
              <path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z" />
            </svg>
          </span>
          <span
            class="flex-1 truncate font-bold uppercase"
            :style="{ fontFamily: 'var(--c17-font-display)', fontSize: 'var(--c17-text-link)' }"
          >{{ member.profile.value?.username ?? '' }}</span>
          <span
            v-if="vip"
            class="shrink-0 rounded-(--c17-radius-xs) px-(--c17-gap-2) text-(length:--c17-text-link) font-bold text-ink uppercase"
            :style="{ backgroundColor: 'var(--c17-accent)' }"
          >VIP {{ vip.level }}</span>
        </div>

        <template v-if="vip && vip.nextLevel !== null">
          <div class="flex items-center gap-(--c17-gap-2)">
            <span
              class="shrink-0 rounded-(--c17-radius-xs) px-(--c17-gap-2) text-(length:--c17-text-link) font-bold text-ink uppercase"
              :style="{ backgroundColor: 'var(--c17-accent)' }"
            >VIP {{ vip.level }}</span>
            <div class="h-2.5 flex-1 overflow-hidden rounded-(--c17-radius-xs) bg-faint">
              <div
                class="h-full rounded-(--c17-radius-xs)"
                :style="{
                  width: `${vip.percent}%`,
                  background: 'linear-gradient(90deg, var(--c17-accent) 0%, var(--c17-accent-deep) 100%)',
                }"
              />
            </div>
            <span class="shrink-0 text-(length:--c17-text-label) text-muted uppercase">VIP {{ vip.nextLevel }}</span>
          </div>
          <p
            class="text-right font-bold text-accent"
            :style="{ fontSize: 'var(--c17-text-note)' }"
          >
            {{ decFormat(vip.current, 0) }} / {{ decFormat(vip.target, 0) }}
            {{ $t('deposit.entry.title.deposit') }}
          </p>
        </template>
      </div>

      <!--
        **上下排，不要並排。** 抽屜只有 245 寬，UiButton 又是固定高度的 w-full，
        兩顆並排時較長的標籤（繁中「立即註冊帳號」、英文「Register Now」）會折行，
        折了行高度撐不開就直接爆出按鈕外。14 個語系裡只要有一個字長就會壞，
        所以不靠縮字級或截斷硬塞。
      -->
      <div
        v-else
        class="flex flex-col gap-2"
      >
        <UiButton @click="navigateTo('/login')">
          {{ $t('auth.login.action.login') }}
        </UiButton>
        <UiButton
          variant="secondary"
          @click="navigateTo('/signup')"
        >
          {{ $t('auth.signup.action.signup') }}
        </UiButton>
      </div>

      <!-- 錢包面板：底是**白→螢光的漸層**（--c17-wallet-bg），不是整片實心螢光 -->
      <div
        v-if="loggedIn"
        class="flex items-center justify-between gap-(--c17-gap-2) rounded-(--c17-field-radius) p-(--c17-gap-2)"
        :style="{ background: 'var(--c17-wallet-bg)', boxShadow: 'var(--c17-shadow-card)' }"
      >
        <div class="min-w-0 flex-1">
          <!--
            幣別放在標籤那一行、金額自己一行，跟首頁 C17WalletBar 同一種排法：
            面板只有約 224 寬，W-Point(MYR) 跟金額擠同一行會被截斷或在 "W-" 斷行。兩者都不截斷。
          -->
          <p
            class="flex items-baseline gap-1 whitespace-nowrap text-ink"
            :style="{ fontSize: 'var(--c17-text-label)' }"
          >
            {{ $t('wallet.overview.label.balance') }}
            <span
              v-if="currency"
              :style="{ color: 'var(--c17-primary)' }"
            >· {{ currency }}</span>
          </p>
          <p
            class="whitespace-nowrap font-bold tabular-nums"
            :style="{ fontSize: 'var(--c17-text-body)', color: 'var(--c17-primary)' }"
          >
            {{ balance }}
          </p>
        </div>
        <NuxtLink
          to="/topup"
          class="size-10 shrink-0"
          :aria-label="$t('deposit.entry.title.deposit')"
        >
          <!--
            這頁的 public 圖示一律用 `:src="'/…'"`（動態綁定），不要用靜態 `src="/…"`。
            靜態 src 在某些 dev-server 的 HMR 累積狀態下會渲染失敗：這裡的 deposit、
            contact-us、download-app、language、logout、lucky-corner 六張會整組不見，
            檔案本身 200、動態綁定的六宮格與分類卻正常。動態綁定是純執行期屬性，不吃編譯時機。
          -->
          <img
            :src="'/skins/c17/menu/deposit.webp'"
            alt=""
            class="size-full object-contain"
          >
        </NuxtLink>
      </div>

      <!--
        六宮格快捷：磚寬靠 gap 21 排出來，用 gap 8 會被撐成 74.7、整塊大一圈。
        **白磚不加陰影**：md 那六個 RECT 只有 fill，算圖也量不到。
      -->
      <ul class="grid grid-cols-3 gap-[1.3125rem]">
        <li
          v-for="item in shortcuts"
          :key="item.key"
        >
          <NuxtLink
            :to="item.to"
            class="relative flex aspect-square flex-col items-center justify-center gap-(--c17-gap-1) rounded-(--c17-field-radius) bg-surface"
          >
            <img
              :src="`/skins/c17/menu/${item.key}.webp`"
              alt=""
              class="size-[26px] object-contain"
            >
            <!-- 站內信是通知中心的入口，掛三個未讀數的合計（同底部列選單那顆） -->
            <span
              v-if="item.key === 'inbox' && unreadBadgeText(messages.totalUnread)"
              class="absolute right-1 top-1 grid min-w-4 place-items-center rounded-full px-1 font-bold"
              :style="{ background: 'var(--c17-badge)', color: 'var(--c17-badge-ink)', fontSize: 'var(--c17-text-badge)' }"
            >{{ unreadBadgeText(messages.totalUnread) }}</span>
            <!-- 「Invite Friends」這種兩個字的標籤要能換行，clamp 成一行會被截成「Invite…」 -->
            <span
              class="line-clamp-2 w-full px-[2px] text-center font-semibold leading-tight"
              :style="{ fontSize: 'var(--c17-text-note)' }"
            >{{ $t(item.labelKey) }}</span>
          </NuxtLink>
        </li>
      </ul>

      <!--
        LUCKY CORNER **排在六宮格下面、連結清單上面**（不是錢包與六宮格之間）。
        點進去是設計稿第 16 張 Daily-check in-pop out，也就是每日簽到彈窗；
        紅包、輪盤、敲蛋由那張彈窗上的晶片互跳，不從這裡各開一個入口。
      -->
      <NuxtLink
        to="/events/checkin"
        class="block overflow-hidden rounded-(--c17-radius)"
      >
        <img
          :src="'/skins/c17/menu/lucky-corner.webp'"
          alt=""
          class="block w-full object-cover"
        >
      </NuxtLink>

      <!--
        遊戲分類 + 站台功能，直接排在抽屜的白底上。

        **沒有分隔線**：menu.md 這八列一個 LINE 節點都沒有（對照 vip.md 是明寫的）。
        不要看縮圖覺得有線就加 `divide-y`。

        **每一列字級要一致**：不要在 li 給 11pt 又在裡面包 `<span class="text-sm">`，
        內層會把 14px 蓋回去，前四列就比下面大一號。
      -->
      <ul>
        <li
          v-for="typeId in categories"
          :key="typeId"
        >
          <NuxtLink
            :to="{ path: '/vendors', query: { typeId } }"
            class="flex items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-(length:--c17-text-note)"
          >
            <img
              v-if="TYPE_ICON[typeId]"
              :src="`/skins/c17/menu/${TYPE_ICON[typeId]}.webp`"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            <span
              v-else
              class="size-[26px] shrink-0"
            />
            {{ $t(gameTypeLabelKey(typeId)) }}
          </NuxtLink>
        </li>

        <li>
          <NuxtLink
            to="/cs"
            class="flex items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-(length:--c17-text-note)"
          >
            <img
              :src="'/skins/c17/menu/contact-us.webp'"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            {{ $t('site.cs.label.customerService') }}
          </NuxtLink>
        </li>
        <!-- 不提供 APK 了，這一項是把網站加到主畫面（PWA） -->
        <li v-if="pwa.canShow.value">
          <button
            type="button"
            class="flex w-full items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-left text-(length:--c17-text-note)"
            @click="onInstallApp"
          >
            <img
              :src="'/skins/c17/menu/download-app.webp'"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            {{ $t('games.launch.action.downloadApp') }}
          </button>
          <p
            v-if="pwaHint"
            class="pb-(--c17-gap-2) text-(length:--c17-text-label) text-muted"
          >
            {{ $t('site.pwa.text.installUnsupported') }}
          </p>
        </li>

        <!--
          設計稿的 Menu 只有八列（Live Casino／Sports／Lottery／Fishing／Contact Us／
          Download App／Language／Logout），**沒有「新手教程」**，依指示移除入口。
          `/information` 這一頁還在，只是選單不再放它；要放回來請先跟設計師要圖示，
          不要拿客服那顆頂替（會讓兩列長得一模一樣）。
        -->
        <!-- 語言：清單來自 i18n 設定，不寫死 -->
        <li>
          <button
            type="button"
            class="flex w-full items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-left text-(length:--c17-text-note)"
            @click="showLanguages = !showLanguages"
          >
            <img
              :src="'/skins/c17/menu/language.webp'"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            {{ $t('site.language.label.language') }} | {{ locale.toUpperCase() }}
          </button>
          <!-- 只開放 C17_LANGUAGES 這 5 個（與活動詳情彈窗同一份），這裡切的是整站語系 -->
          <ul
            v-if="showLanguages"
            class="rounded-(--c17-radius-sm) bg-surface-2 py-(--c17-gap-1)"
          >
            <li
              v-for="lang in C17_LANGUAGES"
              :key="lang.code"
            >
              <button
                type="button"
                class="flex w-full items-center gap-(--c17-gap-3) px-(--c17-gap-3) py-(--c17-gap-2) text-left"
                :style="{
                  fontSize: 'var(--c17-text-body)',
                  color: lang.code === locale ? 'var(--c17-primary)' : 'var(--c17-ink)',
                }"
                :class="lang.code === locale ? 'font-bold' : ''"
                :aria-current="lang.code === locale ? 'true' : undefined"
                @click="setLocale(lang.code)"
              >
                <img
                  :src="languageFlag(lang.code)"
                  alt=""
                  class="size-6 shrink-0 object-contain"
                >
                {{ lang.name }}
              </button>
            </li>
          </ul>
        </li>

        <li v-if="loggedIn">
          <button
            type="button"
            class="flex w-full items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-left text-(length:--c17-text-note)"
            @click="onLogout"
          >
            <img
              :src="'/skins/c17/menu/logout.webp'"
              alt=""
              class="size-[26px] shrink-0 object-contain"
            >
            {{ $t('auth.logout.action.signOut') }}
          </button>
        </li>
        <!-- 設計稿沒有「設定」這一列，所以沒有圖示；留空位讓文字跟上面對齊 -->
        <li v-if="loggedIn">
          <NuxtLink
            :to="MEMBER_PATH.setting"
            class="flex items-center gap-(--c17-gap-2) py-(--c17-gap-1) text-(length:--c17-text-note)"
          >
            <span class="size-[26px] shrink-0" />
            {{ $t('member.common.action.set') }}
          </NuxtLink>
        </li>
      </ul>
    </div>
  </UiDrawer>
</template>
