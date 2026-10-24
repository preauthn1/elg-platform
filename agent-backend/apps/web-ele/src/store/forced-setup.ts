import { computed, ref } from 'vue';

import { defineStore } from 'pinia';

/** 首登強制設定佇列（reset_password / set_payment_password）。 */

const KEY = 'agent-forced-setup-queue';

function load(): string[] {
  try {
    const raw = localStorage.getItem(KEY);
    const arr = raw ? JSON.parse(raw) : [];
    return Array.isArray(arr) ? arr.filter((p) => typeof p === 'string') : [];
  } catch {
    return [];
  }
}

export const useForcedSetupStore = defineStore('forced-setup', () => {
  const queue = ref<string[]>(load());

  /** 目前必須停留的強制頁（佇列第一個）；無則空字串。 */
  const current = computed(() => queue.value[0] ?? '');

  function persist() {
    if (queue.value.length > 0) {
      localStorage.setItem(KEY, JSON.stringify(queue.value));
    } else {
      localStorage.removeItem(KEY);
    }
  }

  function setQueue(paths: string[]) {
    queue.value = [...paths];
    persist();
  }

  /** 完成某頁 → 移出佇列。 */
  function complete(path: string) {
    queue.value = queue.value.filter((p) => p !== path);
    persist();
  }

  function clear() {
    queue.value = [];
    persist();
  }

  function $reset() {
    clear();
  }

  return { $reset, clear, complete, current, queue, setQueue };
});
