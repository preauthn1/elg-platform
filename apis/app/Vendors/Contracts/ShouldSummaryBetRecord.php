<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 標記遊戲商 Driver 的注單走「整點 SBR 排程補帳」（summary_bet_records）。
 *
 * ⚠️ 目前僅為宣告用 marker，尚未參與任何判斷；
 *    補帳 vendor 選取仍以 config/summary_bet_record.php 為準
 *    （該 config 另帶 third_party_code / time_field / service_class 等資料，非本介面所能承載）。
 *    兩者對齊與 gate 改讀本介面，留待後續重構統一進行。
 */
interface ShouldSummaryBetRecord {}
