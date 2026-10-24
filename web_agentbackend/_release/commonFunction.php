<?php
function amount_thousands_on($amount, $decimals = 2, $color = '', $id = '')
{
    //decimals 先統一變成小數點第二位
    $str = '<span class="thousands_on" data-original="' . number_format($amount, $decimals) . '"';

    if ($color != '') {
        $str .= ' style="color:' . $color . '"';
    }
    if ($id != '') {
        $str .= ' id="' . $id . '"';
    }
    $str .= '>' . number_format(($amount / 1000), 2) . 'K</span>';

    echo $str;

}

function amount_thousands_off($amount, $decimals = 2, $color = '', $id = '')
{
    //decimals 先統一變成小數點第二位
    $str = '<span class="thousands_off" data-original="' . number_format($amount, $decimals) . '"';

    if ($color != '') {
        $str .= ' style="color:' . $color . '"';
    }
    if ($id != '') {
        $str .= ' id="' . $id . '"';
    }
    $str .= '>' . number_format($amount, $decimals) . '</span>';

    echo $str;

}
