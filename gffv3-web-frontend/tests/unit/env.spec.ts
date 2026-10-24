import { afterEach, describe, expect, it } from 'vitest'
import { readSecretEnv } from '../../server/utils/env'

describe('readSecretEnv：金鑰不能被 destr 轉成數字', () => {
  const NAME = 'WF_TEST_SECRET'
  afterEach(() => {
    Reflect.deleteProperty(process.env, NAME)
  })

  it('有環境變數時回原字串（16 位數字也保持字串）', () => {
    process.env[NAME] = '9240533016891799'
    expect(readSecretEnv(NAME, 9240533016891800)).toBe('9240533016891799')
  })

  it('沒有環境變數時退回 runtimeConfig 值並轉字串', () => {
    expect(readSecretEnv(NAME, 1234567890123456)).toBe('1234567890123456')
    expect(readSecretEnv(NAME, 'abc')).toBe('abc')
  })

  it('兩邊都沒有回空字串', () => {
    expect(readSecretEnv(NAME, undefined)).toBe('')
    expect(readSecretEnv(NAME, null)).toBe('')
  })
})
