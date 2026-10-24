import type { SetupVxeTable } from './types';

import { defineComponent, watch } from 'vue';

import { usePreferences } from '@vben/preferences';

import {
  VxeButton,
  VxeCheckbox,
  VxeIcon,
  VxeInput,
  VxeLoading,
  VxeModal,
  VxeNumberInput,
  VxePager,
  VxeRadioGroup,
  VxeSelect,
  VxeTooltip,
  VxeUI,
  VxeUpload,
} from 'vxe-pc-ui';
import enUS from 'vxe-pc-ui/lib/language/en-US'; // 导入默认的语言
import frFR from 'vxe-pc-ui/lib/language/fr-FR';
import koKR from 'vxe-pc-ui/lib/language/ko-KR';
import zhCN from 'vxe-pc-ui/lib/language/zh-CN';
import zhTW from 'vxe-pc-ui/lib/language/zh-TW';
import {
  VxeColgroup,
  VxeColumn,
  VxeGrid,
  VxeTable,
  VxeToolbar,
} from 'vxe-table';

import { injectPluginsOptions } from '../plugins-context';
import { extendsDefaultFormatter } from './extends'; // 是否加载过

// 是否加载过
let isInit = false;

let tableFormFactory: ((...args: any[]) => any) | undefined;

function normalizeVxeLocale<T extends Record<string, any>>(localeModule: T) {
  return (
    localeModule &&
    typeof localeModule === 'object' &&
    'default' in localeModule
      ? localeModule.default
      : localeModule
  ) as T;
}

export function useTableForm(...args: any[]) {
  const pluginsOptions = injectPluginsOptions();
  const contextFormFactory = pluginsOptions?.form?.useVbenForm;

  const factory = tableFormFactory || contextFormFactory;
  if (!factory) {
    throw new Error(
      'useTableForm is not initialized. Please provide useVbenForm via setupVbenVxeTable() or providePluginsOptions()',
    );
  }

  return factory(...args);
}

// 部分组件，如果没注册，vxe-table 会报错，这里实际没用组件，只是为了不报错，同时可以减少打包体积
const createVirtualComponent = (name = '') => {
  return defineComponent({
    name,
  });
};

export function initVxeTable() {
  if (isInit) {
    return;
  }

  VxeUI.component(VxeTable);
  VxeUI.component(VxeColumn);
  VxeUI.component(VxeColgroup);
  VxeUI.component(VxeGrid);
  VxeUI.component(VxeToolbar);

  VxeUI.component(VxeButton);
  // VxeUI.component(VxeButtonGroup);
  VxeUI.component(VxeCheckbox);
  // VxeUI.component(VxeCheckboxGroup);
  VxeUI.component(createVirtualComponent('VxeForm'));
  // VxeUI.component(VxeFormGather);
  // VxeUI.component(VxeFormItem);
  VxeUI.component(VxeIcon);
  VxeUI.component(VxeInput);
  // VxeUI.component(VxeList);
  VxeUI.component(VxeLoading);
  VxeUI.component(VxeModal);
  VxeUI.component(VxeNumberInput);
  // VxeUI.component(VxeOptgroup);
  // VxeUI.component(VxeOption);
  VxeUI.component(VxePager);
  // VxeUI.component(VxePulldown);
  // VxeUI.component(VxeRadio);
  // VxeUI.component(VxeRadioButton);
  VxeUI.component(VxeRadioGroup);
  VxeUI.component(VxeSelect);
  // VxeUI.component(VxeSwitch);
  // VxeUI.component(VxeTextarea);
  VxeUI.component(VxeTooltip);
  VxeUI.component(VxeUpload);

  isInit = true;
}

export function setupVbenVxeTable(setupOptions: SetupVxeTable) {
  const { configVxeTable, useVbenForm: useVbenFormFromParam } = setupOptions;

  initVxeTable();

  // 优先使用参数传入的 useVbenForm，否则清空让 context 注入生效
  if (useVbenFormFromParam) {
    tableFormFactory = useVbenFormFromParam;
  }
  const { isDark, locale } = usePreferences();

  const localMap = {
    'zh-CN': normalizeVxeLocale(zhCN),
    'zh-TW': normalizeVxeLocale(zhTW),
    'en-US': normalizeVxeLocale(enUS),
    ko: normalizeVxeLocale(koKR),
    fr: normalizeVxeLocale(frFR),
  };

  // 框架語系代碼 → vxe-pc-ui 語系代碼（vxe 使用完整 BCP-47 代碼）
  const vxeLocaleMap = {
    'zh-CN': 'zh-CN',
    'zh-TW': 'zh-TW',
    'en-US': 'en-US',
    ko: 'ko-KR',
    fr: 'fr-FR',
  } as const;

  watch(
    [() => isDark.value, () => locale.value],
    ([isDarkValue, localeValue]) => {
      VxeUI.setTheme(isDarkValue ? 'dark' : 'light');
      const vxeLocale = vxeLocaleMap[localeValue];
      VxeUI.setI18n(vxeLocale, localMap[localeValue]);
      VxeUI.setLanguage(vxeLocale);
    },
    {
      immediate: true,
    },
  );

  extendsDefaultFormatter(VxeUI);

  configVxeTable(VxeUI);
}
