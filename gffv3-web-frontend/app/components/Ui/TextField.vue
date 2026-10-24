<script setup lang="ts">
/**
 * 表單輸入框。四種狀態對齊 XD 的 Form components：
 *   未啟用 灰框 #b4b4b4 ／ 聚焦 藍框 ／ 已填妥 綠框 ／ 錯誤 紅框＋紅字
 * 規則提示（hint）在設計稿上只在聚焦時出現，錯誤訊息則取代它。
 */
const model = defineModel<string>({ default: '' })

const props = withDefaults(defineProps<{
  /** 欄位標題；沒有就只顯示輸入框 */
  label?: string
  type?: string
  placeholder?: string
  /** 聚焦時顯示的規則提示，例如「(4~10 characters English number)」 */
  hint?: string
  /** 有值就是錯誤態，並取代 hint 顯示 */
  error?: string
  /** 已填妥且驗證過，顯示綠框 */
  done?: boolean
  disabled?: boolean
  required?: boolean
  /**
   * 必填標記：placeholder 前面多一顆**紅色**的 *（設計稿 Form components 的 `* Player ID`，
   * 星號 #FF4D4F、其餘字 #B4B4B4，都是 Plus Jakarta Sans SemiBold 12）。
   */
  requiredMark?: boolean
  autocomplete?: string
  inputmode?: 'text' | 'numeric' | 'tel' | 'email' | 'decimal'
  maxlength?: number
  /** type="date" / "number" 的上下界，其餘型別用不到 */
  min?: string | number
  max?: string | number
  /**
   * 強制顯示錯誤（例如按了送出）。平常錯誤要等這個欄位失焦過一次才顯示，
   * 不然使用者才打第一個字就整片紅。
   */
  showError?: boolean
}>(), {
  type: 'text',
})

const focused = ref(false)
const blurred = ref(false)

/** 真正要顯示的錯誤：有錯，而且（失焦過 或 呼叫端要求顯示）。 */
const visibleError = computed(() => ((blurred.value || props.showError) ? (props.error ?? '') : ''))

/** 錯誤 > 聚焦 > 已填妥 > 預設，與設計稿的優先順序一致。 */
const borderClass = computed(() => {
  if (visibleError.value) return 'border-field-line-error'
  if (focused.value) return 'border-field-line-focus'
  if (props.done) return 'border-field-line-done'
  return 'border-field-line'
})

const note = computed(() => visibleError.value || (focused.value ? props.hint : ''))

/**
 * 原生 placeholder 只能整段同一個顏色，染不出設計稿那顆紅星號，
 * 所以必填欄位的 placeholder 留空，改用覆蓋層自己畫（見 template）。
 */
const useOverlayPlaceholder = computed(() => props.requiredMark === true)
const showOverlayPlaceholder = computed(() => useOverlayPlaceholder.value && !model.value)
</script>

<template>
  <div class="space-y-1">
    <label class="block">
      <span
        v-if="label"
        class="mb-1 block text-sm font-semibold"
      >{{ label }}</span>
      <span class="relative flex items-center">
        <span
          v-if="$slots.prefix"
          class="absolute left-3 flex items-center"
        >
          <slot name="prefix" />
        </span>
        <!--
          設計稿 Form components：欄位字是 Plus Jakarta Sans SemiBold 12（不是 14 regular），
          錯誤態連輸入的字都轉紅，不是只有外框（看 sheet 上那筆 KINGDOM666）。
        -->
        <input
          v-model="model"
          :type="type"
          :placeholder="useOverlayPlaceholder ? '' : placeholder"
          :aria-label="useOverlayPlaceholder && !label ? placeholder : undefined"
          :disabled="disabled"
          :required="required"
          :autocomplete="autocomplete"
          :inputmode="inputmode"
          :maxlength="maxlength"
          :min="min"
          :max="max"
          class="h-(--c17-field-h) w-full rounded-(--c17-field-radius) border bg-field px-3 font-semibold placeholder:text-placeholder focus:ring-2 focus:ring-accent/25 focus:outline-none disabled:bg-disabled disabled:text-disabled-ink"
          :class="[
            borderClass,
            $slots.prefix ? 'pl-[3.125rem]' : '',
            $slots.suffix ? 'pr-[3.125rem]' : '',
            visibleError ? 'text-danger' : 'text-ink',
          ]"
          :style="{ fontSize: 'var(--c17-text-link)' }"
          @focus="focused = true"
          @blur="focused = false; blurred = true"
        >
        <!--
          必填欄位的 placeholder：星號 #FF4D4F、其餘字 #B4B4B4。
          pointer-events-none 讓點擊照樣落在 input 上，aria-hidden 是因為
          同一段文字已經由 input 的 aria-label 讀出來，不要讀兩次。
        -->
        <span
          v-if="showOverlayPlaceholder"
          class="pointer-events-none absolute font-semibold text-placeholder"
          :class="$slots.prefix ? 'left-[3.125rem]' : 'left-3'"
          :style="{ fontSize: 'var(--c17-text-link)' }"
          aria-hidden="true"
        ><span class="text-danger">*</span> {{ placeholder }}</span>

        <span
          v-if="$slots.suffix"
          class="absolute right-3 flex items-center"
        >
          <slot name="suffix" />
        </span>
      </span>
    </label>
    <p
      v-if="note"
      class="text-[length:var(--c17-text-label)] leading-tight"
      :class="visibleError ? 'text-danger' : 'text-ink'"
    >
      {{ note }}
    </p>
  </div>
</template>
