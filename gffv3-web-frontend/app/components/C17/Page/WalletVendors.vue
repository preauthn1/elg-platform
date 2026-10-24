<script setup lang="ts">
/** C17 會員的遊戲商清單。邏輯在 useMemberVendorList。 */
const {
  vendors,
  balances,
  loading,
  statusVendorId,
  transactionNo,
  statusResult,
  checking,
  queryBalance,
  onCheckStatus,
} = useMemberVendorList()
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">
      遊戲商餘額
    </h1>

    <section class="rounded border border-line bg-surface p-4 text-sm">
      <p
        v-if="loading"
        class="text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>
      <p
        v-else-if="vendors.length === 0"
        class="text-muted"
      >
        {{ $t('common.ui.empty.noData') }}
      </p>
      <table
        v-else
        class="w-full text-left"
      >
        <thead>
          <tr class="border-b border-line text-muted">
            <th class="py-2 pr-3">
              {{ $t('games.vendor.label.vendor') }}
            </th>
            <th class="py-2 pr-3 text-right">
              {{ $t('common.amount.label.balance') }}
            </th>
            <th class="py-2" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="v in vendors"
            :key="v.vendor_id"
            class="border-b border-line"
          >
            <td class="py-2 pr-3">
              {{ v.vendor_name }}
            </td>
            <td class="py-2 pr-3 text-right">
              {{ balances[v.vendor_id] ?? '—' }}
            </td>
            <td class="py-2 text-right">
              <button
                type="button"
                class="rounded border border-line px-3 py-1"
                @click="queryBalance(v.vendor_id)"
              >
                {{ $t('common.ui.action.search') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="space-y-3 rounded border border-line bg-surface p-4 text-sm">
      <h2 class="font-semibold">
        查詢轉帳狀態
      </h2>
      <form
        class="flex flex-wrap items-end gap-3"
        @submit.prevent="onCheckStatus"
      >
        <label class="flex flex-col gap-1">
          <span class="text-muted">{{ $t('games.vendor.label.vendor') }}</span>
          <select
            v-model.number="statusVendorId"
            class="rounded border border-line px-2 py-1"
          >
            <option :value="null">
              {{ $t('common.ui.placeholder.select') }}
            </option>
            <option
              v-for="v in vendors"
              :key="v.vendor_id"
              :value="v.vendor_id"
            >
              {{ v.vendor_name }}
            </option>
          </select>
        </label>
        <label class="flex flex-col gap-1">
          <span class="text-muted">轉帳單號</span>
          <input
            v-model="transactionNo"
            type="text"
            class="rounded border border-line px-2 py-1"
          >
        </label>
        <button
          type="submit"
          class="rounded bg-accent px-4 py-1 text-inverse disabled:opacity-50"
          :disabled="checking || !statusVendorId || !transactionNo.trim()"
        >
          {{ $t('common.ui.action.search') }}
        </button>
      </form>
      <p v-if="statusResult">
        {{ statusResult }}
      </p>
    </section>

    <NuxtLink
      to="/wallet"
      class="text-sm text-accent underline"
    >
      {{ $t('wallet.overview.title.wallet') }}
    </NuxtLink>
  </div>
</template>
