<script setup lang="ts">
/**
 * 欄位前置圖示，素材在 public/skins/c17/field/。
 * 後端下發的 field_key 對不到的就不放圖示，不要拿別的頂替。
 */
import type { CountryItem } from '#shared/api/endpoints/site'
import {
  enabledPasswordRules,
  hasRegisterField,
  isMobileAsUsername,
  isRenderedRegisterField,
  type MemberFieldSetting,
  mobileLengthRange,
  normalizePhoneInput,
  type PasswordRule,
  type RegisterFieldIssue,
  registerLengthConfig,
} from '#shared/utils/auth'

const FIELD_ICON: Record<string, string> = {
  username: 'account',
  full_name: 'fullname',
  fullname: 'fullname',
  real_name: 'fullname',
  password: [REDACTED]',
  password_confirmation: 'password-confirm',
  confirm_password: [REDACTED]',
  referral_code: 'refer-code',
  mobile: 'country',
  email: 'account',
  sms_verify_code: 'verify-code',
  email_verify_code: 'verify-code',
}

/**
 * register-v2 的動態欄位渲染。**畫什麼、什麼順序、必填與否、字數、分幾步，
 * 全部由後端 member_field_settings.register 決定**（對齊舊皮 registerFields.js），
 * 這裡只負責用 C17 的 Form components 把它畫出來。
 *
 * 設計稿（Form components）的欄位沒有上方標題，只有 placeholder，必填在前面加 *，
 * 字數規則在聚焦時顯示在下方。
 */
const props = defineProps<{
  /** 這一步要畫的欄位（已依 field_group / sort 排好） */
  fields: MemberFieldSetting[]
  /** 全部欄位，字數 fallback 與「有沒有 mobile」之類的判斷要看全集 */
  allFields: MemberFieldSetting[]
  /**
   * 即時的驗證問題。**綠框（DONE）看這個**：有值又沒問題就是完成。
   * 紅字要不要顯示另外由 showErrors 控制，避免一進來就滿江紅。
   */
  issues: RegisterFieldIssue[]
  /** 使用者按過送出／下一步之後才顯示紅字 */
  showErrors?: boolean
  /** 後端錯誤碼定位到的欄位，畫紅框 */
  errorField?: string | null
  countries: CountryItem[]
  /** agent/downline-code 開啟時的代理清單；空陣列代表不顯示下拉 */
  agentList?: { nickname: string, code: string }[]
}>()

const values = defineModel<Record<string, string>>('values', { required: true })
const phone = defineModel<string>('phone', { required: true })
const countryCode = defineModel<string>('countryCode', { required: true })
/** 邀請碼：手填框與代理下拉共用同一個值（舊皮兩者並存，打字就切回手填） */
const invitationCode = defineModel<string>('invitationCode', { required: true })
const invitationCodeType = defineModel<1 | 2>('invitationCodeType', { required: true })

const { t } = useI18n()

const LABEL_KEYS: Record<string, string> = {
  username: 'auth.common.label.username',
  password: [REDACTED]',
  confirm_password: [REDACTED]',
  last_name: 'member.realname.label.realName',
  mobile: 'auth.common.label.phoneNumber',
  email: 'auth.common.label.email',
  birthday: 'member.profile.label.birthday',
  referral_code: 'member.referralCode.label.code',
}

/** 欄位提示：只有後台明確給了對應文案的欄位才有（舊皮 FIELD_HELP）。 */
const HELP_KEYS: Record<string, string> = {
  last_name: 'member.realname.hint.matchBankAccount',
}

const PASSWORD_RULE_KEYS: Record<PasswordRule, string> = {
  alphabet: 'validation.password.unit.letters',
  number: 'validation.password.unit.numbers',
  symbol: 'validation.password.unit.symbols',
  uppercase: 'validation.password.unit.uppercase',
}

const passwordRules = computed(() => enabledPasswordRules(props.allFields))
const mobileAsUsername = computed(() => isMobileAsUsername(props.allFields))
const mobileRange = computed(() => mobileLengthRange(props.allFields))
const visibleFields = computed(() => props.fields.filter(f => isRenderedRegisterField(f.field_key)))

function labelOf(key: string): string {
  return t(LABEL_KEYS[key] ?? key)
}

/**
 * placeholder 只回欄位名。必填那顆 * 由 UiTextField 的 required-mark 畫，
 * 因為設計稿的星號是紅的（#FF4D4F），串在字串裡沒辦法只染那一個字。
 */
function placeholderOf(field: MemberFieldSetting): string {
  return labelOf(field.field_key)
}

/**
 * 聚焦時顯示的字數規則，數字來自後台 extra_config；
 * 後台沒設就不顯示（不要拼出「請輸入6-位」這種半截句子）。
 */
function hintOf(field: MemberFieldSetting): string {
  const cfg = registerLengthConfig(props.allFields, field)
  const help = HELP_KEYS[field.field_key]
  if (cfg.min_length == null || cfg.max_length == null) return help ? t(help) : ''
  return rangeText(field.field_key, cfg.min_length, cfg.max_length)
}

/**
 * 字數規則文字。後台設了密碼字元規則（例如須含符號）就不能再寫「英文數字」，
 * 改成「字數＋須含哪些」，用戶才知道要輸入什麼。
 */
function rangeText(key: string, min: number, max: number): string {
  if (!isPassword(key) || passwordRules.value.length === 0) {
    return t('validation.field.placeholder.charsetRange', { min, max })
  }
  const rules = passwordRules.value.map(rule => t(PASSWORD_RULE_KEYS[rule])).join('、')
  return t('validation.password.hint.length', { min, max }) + t('validation.password.hint.mustInclude', { rules })
}

function maxLengthOf(field: MemberFieldSetting): number | undefined {
  return registerLengthConfig(props.allFields, field).max_length
}

function typeOf(key: string): string {
  if (isPassword(key)) return shown.value.has(key) ? 'text' : 'password'
  if (key === 'birthday') return 'date'
  if (key === 'email') return 'email'
  return 'text'
}

function isPassword(key: string): boolean {
  return key.includes('password')
}

/**
 * 顯示／隱藏密碼。設計稿**每一個密碼欄右邊都有眼睛**。
 * 欄位是後端動態下發的，所以用 key 記哪幾個目前是明碼，不要每個欄位各開一個 ref。
 */
const shown = ref(new Set<string>())
function toggleShown(key: string) {
  const next = new Set(shown.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  shown.value = next
}

/** 該欄位目前的錯誤文字（就地顯示），沒有回空字串。 */
function errorOf(key: string): string {
  const issue = props.issues.find(i => i.field === key)
  if (!issue) return ''
  switch (issue.kind) {
    case 'required':
      return t('validation.field.error.required', { field: labelOf(key) })
    case 'length':
      return issue.min != null && issue.max != null
        ? rangeText(key, issue.min, issue.max)
        : t('validation.field.error.required', { field: labelOf(key) })
    case 'alnum':
      return t('validation.field.error.columnAlphanumeric', { column: labelOf(key) })
    case 'lowercase':
      return t('validation.account.error.lowercaseOnly')
    case 'mismatch':
      return t('validation.password.error.mismatch')
    case 'mobileLength':
      return t('validation.phone.hint.lengthRange', { min: issue.min, max: issue.max })
    case 'codeRequired':
      return t('auth.otp.placeholder.code')
    default:
      return ''
  }
}

/**
 * DONE（綠框）：這個欄位有值而且目前沒有任何驗證問題。
 * 與紅字無關——即使還沒按過送出，填對了就先變綠（對齊設計稿的 DONE 狀態）。
 */
function isDone(key: string): boolean {
  const value = key === 'mobile' ? phone.value : (values.value[key] ?? '')
  if (!value) return false
  return !props.issues.some(i => i.field === key)
}

/** 帳號取自手機號時，欄位唯讀並在標題後註明來源。 */
const usernameNote = computed(() => (mobileAsUsername.value ? t('auth.signup.hint.usernameFromPhone') : ''))

/** 有 mobile 欄位就一定要簡訊驗證（舊皮：驗證碼併在手機欄位裡，不另外看設定）。 */
const needsSmsVerify = computed(() => hasRegisterField(props.allFields, 'mobile'))
const needsEmailVerify = computed(() => hasRegisterField(props.allFields, 'email'))

const mobileValid = computed(() => {
  const digits = phone.value.replace(/\D/g, '')
  return digits.length >= mobileRange.value.min && digits.length <= mobileRange.value.max
})

function onPhoneInput(value: string) {
  phone.value = normalizePhoneInput(value)
}

function onAgentSelect(event: Event) {
  invitationCode.value = (event.target as HTMLSelectElement).value
  invitationCodeType.value = 1
}

function fullMobile(): string {
  return `${countryCode.value}${phone.value}`
}
</script>

<template>
  <div class="space-y-(--c17-gap-3)">
    <template
      v-for="field in visibleFields"
      :key="field.field_key"
    >
      <!-- 手機：國碼 + 號碼 + 內嵌簡訊驗證碼 -->
      <div
        v-if="field.field_key === 'mobile'"
        class="space-y-(--c17-gap-2)"
        :class="errorField === 'mobile' ? 'rounded-(--c17-field-radius) ring-1 ring-danger' : ''"
      >
        <div class="flex gap-2">
          <!-- 與忘記密碼、綁定手機同一顆國碼欄（樣式說明在 UiCountryCodeSelect） -->
          <UiCountryCodeSelect
            v-model="countryCode"
            :countries="countries"
          />
          <div class="flex-1">
            <UiTextField
              :model-value="phone"
              type="tel"
              inputmode="numeric"
              :placeholder="placeholderOf(field)"
              :required-mark="field.is_required === 1"
              :hint="mobileRange.fromConfig ? $t('validation.phone.hint.lengthRange', { min: mobileRange.min, max: mobileRange.max }) : $t('validation.phone.hint.lengthLimit')"
              :error="errorOf('mobile')"
              :show-error="showErrors"
              :done="isDone('mobile')"
              :maxlength="mobileRange.max"
              @update:model-value="onPhoneInput"
            />
          </div>
        </div>

        <div
          v-if="needsSmsVerify"
          class="flex items-start gap-2"
        >
          <div class="flex-1">
            <UiTextField
              v-model="values.sms_verify_code"
              inputmode="numeric"
              :maxlength="6"
              :placeholder="$t('auth.otp.label.smsCode')"
              :error="errorOf('sms_verify_code')"
              :show-error="showErrors"
              :done="isDone('sms_verify_code')"
            >
              <template #prefix>
                <UiFieldIcon name="verify-code" />
              </template>
            </UiTextField>
          </div>
          <AuthSendCodeButton
            flush
            :type="12"
            :verify-by="1"
            :mobile="fullMobile()"
            :disabled="!mobileValid"
          />
        </div>
      </div>

      <!-- 信箱：號碼 + 內嵌郵件驗證碼 -->
      <div
        v-else-if="field.field_key === 'email'"
        class="space-y-(--c17-gap-2)"
        :class="errorField === 'email' ? 'rounded-(--c17-field-radius) ring-1 ring-danger' : ''"
      >
        <UiTextField
          v-model="values.email"
          type="email"
          :placeholder="placeholderOf(field)"
          :required-mark="field.is_required === 1"
          :error="errorOf('email')"
          :show-error="showErrors"
          :done="isDone('email')"
        />
        <div
          v-if="needsEmailVerify"
          class="flex items-start gap-2"
        >
          <div class="flex-1">
            <UiTextField
              v-model="values.email_verify_code"
              inputmode="numeric"
              :maxlength="6"
              :placeholder="$t('auth.common.label.emailVerifyCode')"
              :error="errorOf('email_verify_code')"
              :show-error="showErrors"
              :done="isDone('email_verify_code')"
            >
              <template #prefix>
                <UiFieldIcon name="verify-code" />
              </template>
            </UiTextField>
          </div>
          <AuthSendCodeButton
            flush
            :type="12"
            :verify-by="2"
            :email="values.email"
          />
        </div>
      </div>

      <!-- 邀請碼：下拉與手填並存，打字就切回手填 -->
      <div
        v-else-if="field.field_key === 'referral_code'"
        class="space-y-(--c17-gap-2)"
        :class="errorField === 'referral_code' ? 'rounded-(--c17-field-radius) ring-1 ring-danger' : ''"
      >
        <label
          v-if="agentList && agentList.length"
          class="block"
        >
          <select
            :value="invitationCodeType === 1 ? invitationCode : ''"
            class="h-(--c17-field-h) w-full rounded-(--c17-field-radius) border border-field-line bg-field px-3 text-sm text-ink"
            @change="onAgentSelect"
          >
            <option value="">
              {{ $t('common.ui.placeholder.select') }}
            </option>
            <option
              v-for="a in agentList"
              :key="a.code"
              :value="a.code"
            >
              {{ a.nickname }} ({{ a.code }})
            </option>
          </select>
        </label>
        <UiTextField
          :model-value="invitationCode"
          :placeholder="placeholderOf(field)"
          :required-mark="field.is_required === 1"
          :error="errorOf('referral_code')"
          :show-error="showErrors"
          :done="!!invitationCode"
          @update:model-value="(v) => { invitationCode = v; invitationCodeType = 2 }"
        >
          <template #prefix>
            <UiFieldIcon name="refer-code" />
          </template>
        </UiTextField>
      </div>

      <!-- 一般欄位 -->
      <div
        v-else
        :class="errorField === field.field_key ? 'rounded-(--c17-field-radius) ring-1 ring-danger' : ''"
      >
        <UiTextField
          v-model="values[field.field_key]"
          :type="typeOf(field.field_key)"
          :placeholder="placeholderOf(field)"
          :required-mark="field.is_required === 1"
          :hint="hintOf(field)"
          :error="errorOf(field.field_key)"
          :show-error="showErrors"
          :done="isDone(field.field_key)"
          :maxlength="maxLengthOf(field)"
          :disabled="field.field_key === 'username' && mobileAsUsername"
          :autocomplete="field.field_key === 'username' ? 'username' : field.field_key.includes('password') ? 'new-password' : undefined"
        >
          <template
            v-if="FIELD_ICON[field.field_key]"
            #prefix
          >
            <UiFieldIcon :name="FIELD_ICON[field.field_key]!" />
          </template>
          <template
            v-if="isPassword(field.field_key)"
            #suffix
          >
            <button
              type="button"
              class="flex items-center"
              :aria-label="$t('auth.login.label.originalPassword')"
              @click="toggleShown(field.field_key)"
            >
              <UiFieldIcon :name="shown.has(field.field_key) ? 'eye-show' : 'eye-hide'" />
            </button>
          </template>
        </UiTextField>
        <p
          v-if="field.field_key === 'username' && usernameNote"
          class="mt-1 text-(length:--c17-text-label) text-muted"
        >
          {{ usernameNote }}
        </p>
      </div>
    </template>
  </div>
</template>
