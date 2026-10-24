<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use Exception;

trait MathTrait
{
    /**
     * 2個精度數字加法計算
     *
     * @throws Exception
     */
    public static function numAdd(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        return bcadd(self::toString($left_number), self::toString($right_number), $scale);
    }

    /**
     * 2個精度數字減法計算
     *
     * @throws Exception
     */
    public static function numSub(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        return bcsub(self::toString($left_number), self::toString($right_number), $scale);
    }

    /**
     * 2個精度數字乘法計算
     *
     * @throws Exception
     */
    public static function numMul(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        return bcmul(self::toString($left_number), self::toString($right_number), $scale);
    }

    /**
     * 2個精度數字除法計算
     *
     * @throws Exception
     */
    public static function numDiv(string|int|float $dividend, string|int|float $divisor, int $scale = 10): string
    {
        return bcdiv(self::toString($dividend), self::toString($divisor), $scale);
    }

    /**
     * 無條件捨去
     *
     * @throws Exception
     */
    public static function numFloor(string|int|float $number, int $scale = 10): string
    {
        return bcadd(self::toString($number), '0', $scale);
    }

    /**
     * 四捨五入到指定小數位（half away from zero：正數加半個單位、負數減半個單位後截斷）。
     * 結果為 0 時正規化為正零（避免 "-0.00"）。
     *
     * @example 73.595, 2 => 73.60
     * @example -57.505, 2 => -57.51
     * @example -0.004, 2 => 0.00
     *
     * @throws Exception
     */
    public static function numRound(string|int|float $number, int $scale = 10): string
    {
        $value = self::toString($number);
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);

        $rounded = bccomp($value, '0', max($scale + 1, 10)) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);

        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }

    /**
     * 比較2個數字
     * 0: 相等, 1: 左邊大, -1: 右邊大
     *
     * @return int 0: 相等, 1: 左邊大, -1: 右邊大
     *
     * @throws Exception
     */
    public static function numCompare(string|int|float $left_number, string|int|float $right_number, int $scale = 10): int
    {
        return bccomp(self::toString($left_number), self::toString($right_number), $scale);
    }

    /**
     * 根據最小可轉換單位，計算目前點數中「可以兌換的最大金額」，並將不足單位的尾數捨去。
     *
     * @param  string|float|int  $point  要截斷的點數
     * @param  string|float|int  $minUnit  最小可轉換的單位
     * @return string 截斷後的點數
     *
     * @example 1016.877, 0.01 => 1016.87
     * @example 1016.877, 10 => 1010
     * @example 1016.877, 5 => 1015
     */
    public static function truncatePointsByUnit(string|float|int $point, string|float|int $minUnit): string
    {
        $point = (string) $point;
        $minUnit = (string) $minUnit;
        // 計算 point 除以 minUnit 的整數倍, 無條件捨去小數
        $times = bcdiv($point, $minUnit, 0);

        // times * minUnit 就是可兌換的總點數
        $usable = bcmul($times, $minUnit, 4);

        return $usable;
    }

    /**
     * 將數字轉換為字串
     *
     * @throws Exception
     */
    private static function toString(string|int|float $number): string
    {
        if (is_string($number)) {
            return $number;
        }
        if (is_int($number)) {
            return strval($number);
        }
        if (is_float($number)) {
            // 轉換過程使用最大精度，避免精度丟失
            // 但不要用20位數, 140.8 會變成 140.47999999999998976818 導致前幾位數在無條件捨去時不準確
            return number_format($number, 10, '.', '');
        }

        throw new Exception('Invalid number type');
    }
}
