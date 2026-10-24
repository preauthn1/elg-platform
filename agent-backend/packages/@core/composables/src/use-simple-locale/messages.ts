export type Locale = 'en-US' | 'fr' | 'ko' | 'zh-CN' | 'zh-TW';

export const messages: Record<Locale, Record<string, string>> = {
  'en-US': {
    cancel: 'Cancel',
    collapse: 'Collapse',
    confirm: 'Confirm',
    expand: 'Expand',
    prompt: 'Prompt',
    reset: 'Reset',
    submit: 'Submit',
    confirmTitle: 'Please Confirm',
  },
  fr: {
    cancel: 'Annuler',
    collapse: 'Réduire',
    confirm: 'Confirmer',
    expand: 'Développer',
    prompt: 'Information',
    reset: 'Réinitialiser',
    submit: 'Soumettre',
    confirmTitle: 'Veuillez confirmer',
  },
  ko: {
    cancel: '취소',
    collapse: '접기',
    confirm: '확인',
    expand: '펼치기',
    prompt: '알림',
    reset: '초기화',
    submit: '제출',
    confirmTitle: '확인해 주세요',
  },
  'zh-CN': {
    cancel: '取消',
    collapse: '收起',
    confirm: '确认',
    expand: '展开',
    prompt: '提示',
    reset: '重置',
    submit: '提交',
    confirmTitle: '请确认',
  },
  'zh-TW': {
    cancel: '取消',
    collapse: '收起',
    confirm: '確認',
    expand: '展開',
    prompt: '提示',
    reset: '重置',
    submit: '提交',
    confirmTitle: '請確認',
  },
};

export const getMessages = (locale: Locale) => messages[locale];
