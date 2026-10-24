import { ref } from 'vue';

import { defineStore } from 'pinia';

/** 千位數顯示：金額除以 1000 並標註 K。個人偏好，只存瀏覽器。 */
export const useKUnitStore = defineStore(
  'k-unit',
  () => {
    const enabled = ref(false);

    function toggle() {
      enabled.value = !enabled.value;
    }

    /** resetAllStores 會逐一呼叫；顯示偏好不隨登出還原，故留空。 */
    function $reset() {
      // noop
    }

    return { $reset, enabled, toggle };
  },
  { persist: { pick: ['enabled'] } },
);
