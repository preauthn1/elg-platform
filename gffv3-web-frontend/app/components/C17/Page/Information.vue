<script setup lang="ts">
/** C17 的畫面，邏輯在 useInformationPage。 */
const {
  openBanner,
  isClickable,
  tabs,
  index,
  information,
  commonProblems,
  openProblem,
  whyChooseUs,
} = await useInformationPage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('site.nav.nav.information')"
      :fallback="'/member'"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div
        class="-mx-(--c17-pad-x) flex gap-(--c17-gap-2) overflow-x-auto px-(--c17-pad-x) [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        role="tablist"
      >
        <button
          v-for="(label, i) in tabs"
          :key="label"
          type="button"
          role="tab"
          :aria-selected="index === i"
          class="flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
          :style="{
            fontSize: 'var(--c17-text-link)',
            background: index === i ? 'var(--c17-primary)' : 'var(--c17-surface)',
            color: index === i ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
            borderColor: index === i ? 'var(--c17-primary)' : 'var(--c17-faint)',
          }"
          @click="index = i"
        >
          {{ label }}
        </button>
      </div>

      <section v-if="index === 0">
        <ul
          v-if="information.length > 0"
          class="flex flex-col gap-(--c17-tile-gap)"
        >
          <li
            v-for="item in information"
            :key="item.id"
          >
            <img
              v-if="item.image"
              :src="item.image"
              :alt="item.description ?? ''"
              class="w-full rounded-(--c17-radius-sm) shadow-[var(--c17-shadow-panel)]"
              :class="isClickable(item) ? 'cursor-pointer' : ''"
              @click="openBanner(item)"
            >
          </li>
        </ul>
        <p
          v-else
          class="text-muted"
        >
          {{ $t('common.ui.empty.noData') }}
        </p>
      </section>

      <section
        v-else-if="index === 1"
        class="flex flex-col gap-(--c17-tile-gap)"
      >
        <div
          v-for="(item, i) in commonProblems"
          :key="item.title"
          class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]"
        >
          <button
            type="button"
            class="flex w-full items-center gap-(--c17-gap-2) text-left font-bold"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
            :aria-expanded="openProblem === i"
            @click="openProblem = openProblem === i ? null : i"
          >
            <span class="min-w-0 flex-1">{{ item.title }}</span>
            <svg
              viewBox="0 0 12 8"
              class="h-2 w-3 shrink-0 transition-transform"
              :class="openProblem === i ? 'rotate-180' : ''"
              fill="currentColor"
              :style="{ color: 'var(--c17-primary)' }"
              aria-hidden="true"
            >
              <path d="M0 1.9 1.4.5 6 5.1 10.6.5 12 1.9 6 7.9z" />
            </svg>
          </button>
          <p
            v-if="openProblem === i"
            class="mt-(--c17-gap-2) whitespace-pre-line"
            :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
          >
            {{ item.content }}
          </p>
        </div>
      </section>

      <section
        v-else
        class="whitespace-pre-line rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-4) shadow-[var(--c17-shadow-panel)]"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
      >
        {{ whyChooseUs }}
      </section>
    </div>
  </div>
</template>
