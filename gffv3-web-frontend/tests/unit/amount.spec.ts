import { describe, expect, it } from 'vitest'
import {
  decAdd,
  decCmp,
  decDiv,
  decFixed,
  decFloorFixed,
  decFormat,
  decIsZero,
  decMax,
  decMin,
  decMul,
  decSub,
  decText,
  decToNumber,
  isDecimalText,
  toAmountLike,
} from '../../shared/utils/amount'

describe('amount：金額運算不吃浮點誤差', () => {
  it('浮點數會出錯的經典案例', () => {
    // 0.1 + 0.2 === 0.30000000000000004
    expect(decAdd('0.1', '0.2')).toBe('0.3')
    // 0.1 * 0.2 === 0.020000000000000004
    expect(decMul('0.1', '0.2')).toBe('0.02')
    // 1.005 * 100 === 100.49999999999999，四捨五入會少一分
    expect(decFixed('1.005', 2)).toBe('1.01')
    // 大數相加超過 Number.MAX_SAFE_INTEGER 仍精確
    expect(decAdd('9007199254740993', '1')).toBe('9007199254740994')
    // 4 位小數的後端金額連加不失真
    expect(decAdd(decAdd('0.0001', '0.0002'), '0.0003')).toBe('0.0006')
  })

  it('加減乘：輸出去尾零', () => {
    expect(decSub('1000', '999.99')).toBe('0.01')
    expect(decMul('3', '1.0000')).toBe('3')
    expect(decAdd('0.1', 0.2)).toBe('0.3')
    expect(decSub('0', '5')).toBe('-5')
  })

  it('除法無條件捨去，除數 0 回 0', () => {
    expect(decDiv('100', '3')).toBe('33.33')
    expect(decDiv('100', '3', 4)).toBe('33.3333')
    expect(decDiv('10', '4')).toBe('2.5')
    expect(decDiv('10', '0')).toBe('0')
    expect(decDiv('-10', '3')).toBe('-3.33')
  })

  it('比較、極值、零值', () => {
    expect(decCmp('50.00', 50)).toBe(0)
    expect(decCmp('49.99', '50')).toBe(-1)
    expect(decCmp('50.01', '50')).toBe(1)
    expect(decMax('1.5', '1.50')).toBe('1.5')
    expect(decMax('2', '10')).toBe('10')
    expect(decMin('2', '10')).toBe('2')
    expect(decIsZero('0.0000')).toBe(true)
    expect(decIsZero('0.0001')).toBe(false)
  })

  it('四捨五入保留位數，捨去版不會多給', () => {
    expect(decFixed('1.2')).toBe('1.20')
    expect(decFixed(null)).toBe('0.00')
    expect(decFixed('2.345', 2)).toBe('2.35')
    expect(decFloorFixed('2.349', 2)).toBe('2.34')
    expect(decFloorFixed('2.999', 2)).toBe('2.99')
  })

  it('非金額字串一律當 0，不丟例外', () => {
    expect(isDecimalText('abc')).toBe(false)
    expect(isDecimalText('')).toBe(false)
    expect(isDecimalText('1e5')).toBe(false)
    expect(isDecimalText('12.3400')).toBe(true)
    expect(decText('abc')).toBe('0')
    expect(decText(null)).toBe('0')
    expect(decText('1.5000')).toBe('1.5')
    expect(decAdd('abc', '5')).toBe('5')
  })

  it('千分位顯示，預設 2 位小數', () => {
    expect(decFormat('1234567.891', 2)).toBe('1,234,567.89')
    expect(decFormat('1000')).toBe('1,000.00')
    expect(decFormat('-1234.5', 2)).toBe('-1,234.50')
    expect(decFormat('1500', 0)).toBe('1,500')
    expect(decFormat('1234.5000', 'trim')).toBe('1,234.5')
    expect(decFormat('1000', 'trim')).toBe('1,000')
  })

  it('大數不會變成科學記號', () => {
    expect(decMul('1000000000000', '1000000000')).toBe('1000000000000000000000')
    expect(decText('0.00000001')).toBe('0.00000001')
  })

  it('decToNumber 只給非金額用途', () => {
    expect(decToNumber('12.5')).toBe(12.5)
    expect(decToNumber(null)).toBe(0)
  })

  it('已格式化的字串再丟回來也算得動（去千分位逗號）', () => {
    expect(decText('1,234.56')).toBe('1234.56')
    expect(decAdd('1,000', '1,000')).toBe('2000')
    expect(decFloorFixed('1,234.567', 2)).toBe('1234.56')
    expect(isDecimalText('1,234.56')).toBe(true)
  })

  it('捨去後只剩 0 時不顯示負號', () => {
    expect(decFloorFixed('-0.001', 2)).toBe('0.00')
    expect(decFloorFixed('-1.239', 2)).toBe('-1.23')
    expect(decFormat('-0.0001', 2)).toBe('0.00')
  })

  it('顯示一律捨去，比照舊前台 main.js 的 roundDown', () => {
    // 四捨五入會顯示出會員實際沒有的錢
    expect(decFormat('1234.999')).toBe('1,234.99')
    expect(decFormat(1234.567, 0)).toBe('1,234')
    expect(decFloorFixed('7', 2)).toBe('7.00')
    expect(decFloorFixed('0', 2)).toBe('0.00')
    expect(decFloorFixed('1.9999', 4)).toBe('1.9999')
    expect(decFormat('-1234.5678', 4)).toBe('-1,234.5678')
  })

  it('toAmountLike 只收字串與數字', () => {
    expect(toAmountLike('1.5')).toBe('1.5')
    expect(toAmountLike(3)).toBe(3)
    expect(toAmountLike(null)).toBeNull()
    expect(toAmountLike({})).toBeNull()
  })
})
