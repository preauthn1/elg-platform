<?php

namespace App\Helpers\Traits;

trait MathTrait
{
    /**
     * 2個精度數字加法計算
     */
    public static function numAdd(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        if (is_int($left_number)) {
            $left_number = strval($left_number);
        }

        if (is_int($right_number)) {
            $right_number = strval($right_number);
        }

        $left_number = is_string($left_number) ? $left_number : number_format($left_number, $scale, '.', '');
        $right_number = is_string($right_number) ? $right_number : number_format($right_number, $scale, '.', '');

        return bcadd($left_number, $right_number, $scale);
    }

    /**
     * 2個精度數字減法計算
     */
    public static function numSub(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        if (is_int($left_number)) {
            $left_number = strval($left_number);
        }

        if (is_int($right_number)) {
            $right_number = strval($right_number);
        }

        $left_number = is_string($left_number) ? $left_number : number_format($left_number, $scale, '.', '');
        $right_number = is_string($right_number) ? $right_number : number_format($right_number, $scale, '.', '');

        return bcsub($left_number, $right_number, $scale);
    }

    /**
     * 2個精度數字乘法計算
     */
    public static function numMul(string|int|float $left_number, string|int|float $right_number, int $scale = 10): string
    {
        if (is_int($left_number)) {
            $left_number = strval($left_number);
        }

        if (is_int($right_number)) {
            $right_number = strval($right_number);
        }

        $left_number = is_string($left_number) ? $left_number : number_format($left_number, $scale, '.', '');
        $right_number = is_string($right_number) ? $right_number : number_format($right_number, $scale, '.', '');

        return bcmul($left_number, $right_number, $scale);
    }

    /**
     * 2個精度數字除法計算
     */
    public static function numDiv(string|int|float $dividend, string|int|float $divisor, int $scale = 10): string
    {
        if (is_int($dividend)) {
            $dividend = strval($dividend);
        }

        if (is_int($divisor)) {
            $divisor = strval($divisor);
        }

        $dividend = is_string($dividend) ? $dividend : number_format($dividend, $scale, '.', '');
        $divisor = is_string($divisor) ? $divisor : number_format($divisor, $scale, '.', '');

        return bcdiv($dividend, $divisor, $scale);
    }

    /**
     * 無條件捨去
     */
    public static function numFloor(string|int|float $number, int $scale = 10): string
    {
        if (is_int($number)) {
            $number = strval($number);
        }

        $number = is_string($number) ? bcadd($number, '0', $scale) : number_format($number, $scale, '.', '');

        return $number;
    }

    /**
     * 比較2個數字
     * 0: 相等, 1: 左邊大, -1: 右邊大
     *
     * @return int 0: 相等, 1: 左邊大, -1: 右邊大
     */
    public static function numCompare(string|int|float $left_number, string|int|float $right_number, int $scale = 10): int
    {
        if (is_int($left_number)) {
            $left_number = strval($left_number);
        }

        if (is_int($right_number)) {
            $right_number = strval($right_number);
        }

        $left_number = is_string($left_number) ? $left_number : number_format($left_number, $scale, '.', '');
        $right_number = is_string($right_number) ? $right_number : number_format($right_number, $scale, '.', '');

        return bccomp($left_number, $right_number, $scale);
    }

    /**
     * 根據最小可轉換單位，計算目前點數中「可以兌換的最大金額」，並將不足單位的尾數捨去。
     *
     * @param  string  $point  要截斷的點數
     * @param  string  $minUnit  最小可轉換的單位
     * @return string 截斷後的點數
     */
    public static function truncatePointsByUnit(string $point, string $minUnit): string
    {
        // 計算 point 除以 minUnit 的整數倍, 無條件捨去小數
        $times = bcdiv($point, $minUnit, 0);

        // times * minUnit 就是可兌換的總點數
        $usable = bcmul($times, $minUnit, 4);

        return $usable;
    }
}
