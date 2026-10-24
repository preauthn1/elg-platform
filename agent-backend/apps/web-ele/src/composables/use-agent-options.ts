import { computed, ref } from 'vue';

import { searchAgentOptionsApi } from '#/api';

export interface AgentOption {
  id: number | string;
  username: string;
}

/**
 * 代理帳號下拉選項；ElSelect 掛 remote + :remote-method="search"。
 * 一次只撈固定筆數，已選的代理要 pin() 釘住才顯示得出帳號。
 */
export function useAgentOptions() {
  const loading = ref(false);
  const pinned = ref<AgentOption[]>([]);
  const results = ref<AgentOption[]>([]);
  /** 只採用最後一次查詢的結果，先送後回的舊查詢丟掉 */
  let seq = 0;

  const options = computed<AgentOption[]>(() => {
    const ids = new Set(pinned.value.map((o) => String(o.id)));
    return [
      ...pinned.value,
      ...results.value.filter((o) => !ids.has(String(o.id))),
    ];
  });

  async function search(query: string) {
    const current = ++seq;
    loading.value = true;
    try {
      const res = await searchAgentOptionsApi(query).catch(() => null);
      if (current !== seq) return;
      results.value = Number(res?.code) === 1000 ? (res?.data ?? []) : [];
    } finally {
      if (current === seq) loading.value = false;
    }
  }

  function pin(rows: Array<AgentOption | null | undefined>) {
    const ids = new Set(pinned.value.map((o) => String(o.id)));
    const add: AgentOption[] = [];
    for (const row of rows) {
      if (!row?.id || ids.has(String(row.id))) continue;
      ids.add(String(row.id));
      add.push({ id: row.id, username: row.username });
    }
    if (add.length > 0) pinned.value = [...pinned.value, ...add];
  }

  function unpin() {
    pinned.value = [];
  }

  return { loading, options, pin, search, unpin };
}
