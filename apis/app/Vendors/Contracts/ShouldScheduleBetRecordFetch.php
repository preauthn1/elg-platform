<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 排程 marker：宣告遊戲商加入 fetch_bet_record 自動排程拉注單；
 * 降級為 SupportsBetRecordFetch 即退出排程，仍可 --vendor 手動補拉
 *
 * @knowledge rules/bet-record-pull
 */
interface ShouldScheduleBetRecordFetch extends SupportsBetRecordFetch {}
