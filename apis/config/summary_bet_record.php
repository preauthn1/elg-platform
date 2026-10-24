<?php

declare(strict_types=1);

use App\Console\Commands\SummaryBetRecord\Vendors;
use App\Enums\Vendor\VendorIdEnum;

/**
 * 遊戲下注記錄彙總設定表
 *
 * 列於此表的遊戲商走此整點排程補帳；其餘遊戲商以各自專屬補帳服務處理。
 *
 * 格式：vendor_id => [
 *     'third_party_code' => string,
 *     'time_field'       => string,
 *     'service_class'    => class-string,  // 選配；未指定則使用 SummaryBetRecordService
 *     'schedule_enabled' => bool,          // 選配，預設 true；false = 不納入整點排程，
 *                                          //   僅在明確指定 --vendor 時執行（後台補跑用）
 *     'reconcile_timezone' => int,         // 選配，預設 8；對帳時區（與 UTC 的時差小時數），
 *                                          //   以 docs/運維每日對帳/每日遊戲對帳.sql 的 @Timezone*Vendors 清單為準，
 *                                          //   Olympus 重跑注單彙總／拉取注單頁換遊戲商時以此為時區預設選項
 *     'next_day_full_rerun' => bool        // 選配，預設 false；true = 每日 UTC+8 08:05 以對帳時區
 *                                          //   重跑前一整天彙總（summary_bet_record:rerun-full-day）
 * ]
 *
 * 聚合遊戲商（多個子遊戲商共用同一三方 code，如 GMSW）以 array_fill_keys 群組化，
 * 共用設定只寫一次，新增子遊戲商時於該群組的 key 陣列補一行 enum 即可。
 * ⚠️ vendor_id 為整數 key，務必用 union (+) 合併，不可用 array spread (...)
 *    ——spread 會把整數 key 重新編號，導致 vendor_id 全錯。
 */
return [
    // ── 獨立遊戲商：各自對應一個三方 code ──
    VendorIdEnum::JOKER_BACCARAT_SW->value => [
        'third_party_code' => 'JOKERBACCARATSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::PXPLAYSW->value => [
        'third_party_code' => 'PXPLAYSW',
        'time_field' => 'payout_time',
    ],
    // PTSW(144) 無其他自動補帳排程，須走整點 SBR
    VendorIdEnum::PTSW->value => [
        'third_party_code' => 'PTSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::E88SPORT->value => [
        'third_party_code' => 'E88SPORT',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::RGSW->value => [
        'third_party_code' => 'RGSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::SEXYSLOTSW->value => [
        'third_party_code' => 'SEXYSLOTSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::MEGAH5SW->value => [
        'third_party_code' => 'MEGAH5SW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::SLOT4DSW->value => [
        'third_party_code' => 'SLOT4DSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::DSTPLAYSW->value => [
        'third_party_code' => 'DSTPLAYSW',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::AIGAMINGSW->value => [
        'third_party_code' => 'AIGAMINGSW',
        'time_field' => 'payout_time',
    ],
    // SunCity 轉帳錢包：注單由 APIs fetch_bet_record 排程拉取（Play8oy 同平台但由舊系統拉單，見下方 PLAY8OY）
    VendorIdEnum::SUNCITY->value => [
        'third_party_code' => 'SUNCITY',
        'time_field' => 'payout_time',
    ],
    // Titan 聚合例外：C93 / L4 各有獨立注單表與三方 code（見 config/vendor.php）
    // 三方只給一個時間，注單與 TBR 都以 bet_time 為準
    VendorIdEnum::TITAN_C93->value => [
        'third_party_code' => 'C93',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::TITAN_L4->value => [
        'third_party_code' => 'L4',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],

    VendorIdEnum::N918->value => [
        'third_party_code' => 'N918',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::PPSW->value => [
        'third_party_code' => 'PPSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 0,
    ],
    VendorIdEnum::TFGAMINGSW->value => [
        'third_party_code' => 'TFGAMINGSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::J3->value => [
        'third_party_code' => 'J3',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::WESW->value => [
        'third_party_code' => 'WESW',
        'time_field' => 'payout_time',
        'service_class' => Vendors\WeswSummaryBetRecordService::class,
        'schedule_enabled' => false,
    ],
    VendorIdEnum::BORACAYFISH->value => [
        'third_party_code' => 'BORACAYFISH',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::MOTIVATIONSW->value => [
        'third_party_code' => 'MOTIVATIONSW',
        'time_field' => 'payout_time',
        'service_class' => Vendors\MotivationswSummaryBetRecordService::class,
        'schedule_enabled' => false,
    ],
    VendorIdEnum::WMLIVESW->value => [
        'third_party_code' => 'WMLIVESW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::BOLESW->value => [
        'third_party_code' => 'BOLESW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::CLOTSW->value => [
        'third_party_code' => 'CLOTSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::FFFSW->value => [
        'third_party_code' => 'FFFSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::CLOT_P2P_SW->value => [
        'third_party_code' => 'CLOTP2PSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::IMPSW->value => [
        'third_party_code' => 'IMPSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ONEBXSW->value => [
        'third_party_code' => 'ONEBXSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 7,
    ],
    VendorIdEnum::OPPO->value => [
        'third_party_code' => 'OPPO',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::VPLUSSW->value => [
        'third_party_code' => 'VPLUSSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::AFBSW->value => [
        'third_party_code' => 'AFBSW',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::Nex4DSW->value => [
        'third_party_code' => 'NEX4DSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::NEW4D2026->value => [
        'third_party_code' => 'NEW4D2026',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::CLOT_LEGEND_SLOT_SW->value => [
        'third_party_code' => 'CLOTLSSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::BESOFT->value => [
        'third_party_code' => 'BESOFT',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::QQPK->value => [
        'third_party_code' => 'QQPK',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::KISSH5SW->value => [
        'third_party_code' => 'KISSH5SW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::RECTANGLESW->value => [
        'third_party_code' => 'RECTANGLESW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::COSMOPLAYSW->value => [
        'third_party_code' => 'COSMOPLAYSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 0,
    ],
    VendorIdEnum::METAGAMINGSW->value => [
        'third_party_code' => 'METAGAMINGSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SEXYSLOTCQ9SW->value => [
        'third_party_code' => 'SEXYSLOTCQ9SW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],

    // ── 舊專案 SummaryBetRecordCommand 遷入：整點排程仍由舊專案負責，僅開放 --vendor 補跑 ──
    VendorIdEnum::AG->value => [
        'third_party_code' => 'AG',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::JOKER->value => [
        'third_party_code' => 'JOKER',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // 舊專案以整日 bet_time 彙總（日結）
    VendorIdEnum::SCR->value => [
        'third_party_code' => 'SCR',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 draw_date 日結；SP_transfer_bet_record_4D 的 payout_time = draw_date
    VendorIdEnum::HAOLONG->value => [
        'third_party_code' => 'HAOLONG',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_gw99 的 bet_time / payout_time 皆為 utc_created_time，兩欄同值
    VendorIdEnum::GreatWall->value => [
        'third_party_code' => 'GW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_ace333 的 bet_time / payout_time 皆為 created，兩欄同值
    VendorIdEnum::ACE333->value => [
        'third_party_code' => 'ACE',
        'time_field' => 'payout_time',
    ],
    VendorIdEnum::VPLAY->value => [
        'third_party_code' => 'VPLAY',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // 舊專案以整日 bet_time 彙總（日結）
    VendorIdEnum::MEGA888->value => [
        'third_party_code' => 'MEGA',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_xe88 的 bet_time / payout_time 皆為 utc_log_time，兩欄同值
    VendorIdEnum::XE88->value => [
        'third_party_code' => 'XE88',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::LUCKY->value => [
        'third_party_code' => 'LUCKY',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::EBET->value => [
        'third_party_code' => 'EBET',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 session 的 start_time（MIN(time)）切區間；SP_transfer_bet_record_isb 的 bet_time = MIN(time)
    VendorIdEnum::ISB->value => [
        'third_party_code' => 'ISB',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 0,
    ],
    // 舊專案以 endWager 的 createTime 切區間（結算時間）
    VendorIdEnum::YGG->value => [
        'third_party_code' => 'YGG',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 0,
    ],
    // 舊專案以 utc_end_time 切區間（結束時間）
    VendorIdEnum::WM->value => [
        'third_party_code' => 'WM',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => -4,
    ],
    // SP_transfer_bet_record_pt 的 bet_time / payout_time 皆為 utc_game_date，兩欄同值
    VendorIdEnum::PT->value => [
        'third_party_code' => 'PT',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_sbo 的 bet_time / payout_time 皆為交易 create_time，兩欄同值
    VendorIdEnum::SBO->value => [
        'third_party_code' => 'SBO',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_mt 的 bet_time / payout_time 皆為 utc_gameDate，兩欄同值
    VendorIdEnum::MT->value => [
        'third_party_code' => 'MT',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_pg 的 bet_time / payout_time 皆為 Time，兩欄同值
    VendorIdEnum::PG->value => [
        'third_party_code' => 'PG',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => 0,
    ],
    // 舊 SBR 以 utc_update_time 切區間；SP_transfer_bet_record_ia / new_ia 的 payout_time = (utc_)update_time
    VendorIdEnum::IA->value => [
        'third_party_code' => 'IA',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_vpower(_new) 的 bet_time / payout_time 皆為 utc_time，兩欄同值
    VendorIdEnum::VPOWER->value => [
        'third_party_code' => 'VPOWER',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊專案以整日 bet_time 彙總（日結）
    VendorIdEnum::PUSSY->value => [
        'third_party_code' => 'PUSSY',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::AES->value => [
        'third_party_code' => 'AES',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::IGS->value => [
        'third_party_code' => 'IGS',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::CT->value => [
        'third_party_code' => 'CT',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // SP_transfer_bet_record_sky1388 的 bet_time / payout_time 皆為 bet_time，兩欄同值
    VendorIdEnum::SKY1388->value => [
        'third_party_code' => 'SKY1388',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::DG->value => [
        'third_party_code' => 'DG',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::PLAY8OY->value => [
        'third_party_code' => 'PLAYBOY',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SKYBET->value => [
        'third_party_code' => 'SKYBET',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::RED_MARIO->value => [
        'third_party_code' => 'RM',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::TERRA->value => [
        'third_party_code' => 'TERRA',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ALLBET->value => [
        'third_party_code' => 'ALLBET',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::VSPOKER->value => [
        'third_party_code' => 'VSPOKER',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SA->value => [
        'third_party_code' => 'SA',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ESPORT->value => [
        'third_party_code' => 'ESPORT',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::FISH->value => [
        'third_party_code' => 'FISH',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::MENANG->value => [
        'third_party_code' => 'MENANG',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::NPT->value => [
        'third_party_code' => 'NPT',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::V8->value => [
        'third_party_code' => 'V8',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 SDate 日結；SP_transfer_bet_record_obet33 的 payout_time 與 summary_time 皆取 b.bet_time
    VendorIdEnum::OBET33->value => [
        'third_party_code' => 'OBET33',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => -4,
    ],
    // 舊專案以 winlost_datetime 日結
    VendorIdEnum::SABA->value => [
        'third_party_code' => 'SABA',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => -4,
    ],
    // SP_transfer_bet_record_bola2 的 bet_time / payout_time 皆為 bet_time，兩欄同值
    VendorIdEnum::BOLA->value => [
        'third_party_code' => 'BOLA',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 wp_time 切區間；SP_transfer_bet_record_plus100 的 payout_time = wp_time
    VendorIdEnum::PLUS->value => [
        'third_party_code' => 'PLUS',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SG->value => [
        'third_party_code' => 'SG',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::NS->value => [
        'third_party_code' => 'NS',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::BNG->value => [
        'third_party_code' => 'BNG',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::PGSSW->value => [
        'third_party_code' => 'PGSSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // BWG 走 PegasusSW 單錢包（與 PGSSW 同三方 code），每日早上以對帳時區重跑前一整天
    VendorIdEnum::BWG->value => [
        'third_party_code' => 'PGSSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'next_day_full_rerun' => true,
    ],
    VendorIdEnum::MGC->value => [
        'third_party_code' => 'MGC',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::YBET->value => [
        'third_party_code' => 'YBET',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 work_date 日結；SP_transfer_bet_record_vsport 的 payout_time 與 summary_time 皆取 b.bet_time
    VendorIdEnum::VSPORT->value => [
        'third_party_code' => 'VSPORT',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => -4,
    ],
    VendorIdEnum::PGSLOT->value => [
        'third_party_code' => 'PGSLOT',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::I1BETNOW->value => [
        'third_party_code' => 'LBB',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::DS88->value => [
        'third_party_code' => 'DS88',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::KY->value => [
        'third_party_code' => 'KY',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SMA->value => [
        'third_party_code' => 'SLOTMANIA',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::SPADEGAMINGSW->value => [
        'third_party_code' => 'SGSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::UUSLOTSW->value => [
        'third_party_code' => 'UUSSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 summary_time 切區間；API_External EpicWinController 的 TBR summary_time 取 payout_time
    VendorIdEnum::EPICSW->value => [
        'third_party_code' => 'EPICSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::LFC888->value => [
        'third_party_code' => 'LFC888',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ASTAR->value => [
        'third_party_code' => 'ASTAR',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
        'reconcile_timezone' => -4,
    ],
    VendorIdEnum::WBET->value => [
        'third_party_code' => 'WBET',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::M8BET->value => [
        'third_party_code' => 'M8BET',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    // 舊 SBR 以 draw_date 日結；SP_transfer_bet_record_bolakk4d 的 summary_time 取 payout_time
    VendorIdEnum::BOLAKK4D->value => [
        'third_party_code' => 'BOLAKK4D',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::GFG2->value => [
        'third_party_code' => 'GFG2',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::RCB988->value => [
        'third_party_code' => 'RCB988',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ACE333SW->value => [
        'third_party_code' => 'ACE333SW',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::ACEWIN->value => [
        'third_party_code' => 'ACEWIN',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::WCASINO->value => [
        'third_party_code' => 'WCASINO',
        'time_field' => 'bet_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::MEGAWINSW->value => [
        'third_party_code' => 'MEGAWINSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::BAISON->value => [
        'third_party_code' => 'BAISON',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::VPOWERSW->value => [
        'third_party_code' => 'VPOWERSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::WFSW->value => [
        'third_party_code' => 'WFSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
    VendorIdEnum::CRSW->value => [
        'third_party_code' => 'CRSW',
        'time_field' => 'payout_time',
        'schedule_enabled' => false,
    ],
]
    // ── 聚合遊戲商 GMSW：共用同一三方 code 與設定 ──
    + array_fill_keys(
        [
            VendorIdEnum::GMSW_PG->value,
            VendorIdEnum::GMSW_JILI->value,
            VendorIdEnum::GMSW_PP->value,
            VendorIdEnum::GMSW_OMG->value,
            VendorIdEnum::GMSW_MINIGAME->value,
            VendorIdEnum::GMSW_HACKSAW->value,
            VendorIdEnum::GMSW_TADA->value,
            VendorIdEnum::GMSW_CP->value,
            VendorIdEnum::GMSW_ASKME->value,
            VendorIdEnum::GMSW_JDB->value,
        ],
        [
            'third_party_code' => 'GMSW',
            'time_field' => 'payout_time',
        ],
    )
    // ── 聚合遊戲商 Titan（EVO）：注單由 fetch_bet_record 拉進 evo_bet_records 後轉入 TBR ──
    + array_fill_keys(
        [
            VendorIdEnum::TITAN_EVO->value,
            VendorIdEnum::TITAN_KMR->value,
            VendorIdEnum::TITAN_S12->value,
            VendorIdEnum::TITAN_SV3->value,
            VendorIdEnum::TITAN_JDB->value,
            VendorIdEnum::TITAN_FC->value,
            VendorIdEnum::TITAN_WC->value,
            VendorIdEnum::TITAN_AVP->value,
            VendorIdEnum::TITAN_END->value,
            VendorIdEnum::TITAN_NAG->value,
            VendorIdEnum::TITAN_RCB->value,
            VendorIdEnum::TITAN_M8->value,
            VendorIdEnum::TITAN_RI8->value,
            VendorIdEnum::TITAN_SBO->value,
            VendorIdEnum::TITAN_UUS->value,
            VendorIdEnum::TITAN_CLP->value,
            VendorIdEnum::TITAN_gfg->value,
            VendorIdEnum::TITAN_CQ9->value,
            VendorIdEnum::TITAN_KA->value,
            VendorIdEnum::TITAN_RGS->value,
            VendorIdEnum::TITAN_WF->value,
            VendorIdEnum::TITAN_RG->value,
            VendorIdEnum::TITAN_AC3->value,
            VendorIdEnum::TITAN_WS->value,
            VendorIdEnum::TITAN_WE->value,
            VendorIdEnum::TITAN_RET->value,
            VendorIdEnum::TITAN_AGL2->value,
            VendorIdEnum::TITAN_AGS2->value,
            VendorIdEnum::TITAN_BTG->value,
            VendorIdEnum::TITAN_CG->value,
            VendorIdEnum::TITAN_DGS->value,
            VendorIdEnum::TITAN_HAB->value,
            VendorIdEnum::TITAN_MGP->value,
            VendorIdEnum::TITAN_NET->value,
            VendorIdEnum::TITAN_NLC->value,
            VendorIdEnum::TITAN_NOV->value,
            VendorIdEnum::TITAN_AES->value,
            VendorIdEnum::TITAN_PGSOFT->value,
            VendorIdEnum::TITAN_PPLIVE->value,
            VendorIdEnum::TITAN_PPSLOT->value,
            VendorIdEnum::TITAN_SGT->value,
            VendorIdEnum::TITAN_JKR->value,
            VendorIdEnum::TITAN_NS->value,
            VendorIdEnum::TITAN_PLT->value,
            VendorIdEnum::TITAN_PLTC->value,
            VendorIdEnum::TITAN_YEB->value,
            VendorIdEnum::TITAN_SPR->value,
            VendorIdEnum::TITAN_CQ92->value,
            VendorIdEnum::TITAN_MGP2->value,
            VendorIdEnum::TITAN_AMG->value,
            VendorIdEnum::TITAN_BGM->value,
            VendorIdEnum::TITAN_FTG->value,
            VendorIdEnum::TITAN_VLT->value,
            VendorIdEnum::TITAN_OCT->value,
            VendorIdEnum::TITAN_OBET33->value,
            VendorIdEnum::TITAN_LFC->value,
            VendorIdEnum::TITAN_PEGASUS->value,
            VendorIdEnum::TITAN_VPLUS->value,
            VendorIdEnum::TITAN_MAGAH5->value,
            VendorIdEnum::TITAN_ALLBET->value,
            VendorIdEnum::TITAN_JILI->value,
        ],
        [
            'third_party_code' => 'EVO',
            // 三方只給一個時間，注單與 TBR 都以 bet_time 為準
            'time_field' => 'bet_time',
            // 整點排程仍由舊專案負責，本專案只開放後台／CLI 指定 --vendor 補跑
            'schedule_enabled' => false,
        ],
    )
    // TOP55SW 聚合單錢包：子遊戲商共用同一三方 code 與設定
    + array_fill_keys(
        [
            VendorIdEnum::TOP55SW_DG->value,
            VendorIdEnum::TOP55SW_MG->value,
            VendorIdEnum::TOP55SW_PL->value,
            VendorIdEnum::TOP55SW_SA->value,
            VendorIdEnum::TOP55SW_WG->value,
            VendorIdEnum::TOP55SW_NOLIMITCITY->value,
            VendorIdEnum::TOP55SW_ATG->value,
            VendorIdEnum::TOP55SW_KA->value,
            VendorIdEnum::TOP55SW_HACKSAW->value,
            VendorIdEnum::TOP55SW_POPIPLAY->value,
            VendorIdEnum::TOP55SW_EVOPLAY->value,
            VendorIdEnum::TOP55SW_MASCOT->value,
            VendorIdEnum::TOP55SW_POPOK->value,
            VendorIdEnum::TOP55SW_CQ9->value,
            VendorIdEnum::TOP55SW_TADA->value,
            VendorIdEnum::TOP55SW_JJBX->value,
            VendorIdEnum::TOP55SW_FACHAI->value,
            VendorIdEnum::TOP55SW_JDB->value,
            VendorIdEnum::TOP55SW_3OAKS->value,
            VendorIdEnum::TOP55SW_JILI->value,
            VendorIdEnum::TOP55SW_RUBY->value,
            VendorIdEnum::TOP55SW_PLAYSON->value,
            VendorIdEnum::TOP55SW_DREAMTECH->value,
            VendorIdEnum::TOP55SW_BOOONGO->value,
            VendorIdEnum::TOP55SW_PP->value,
            VendorIdEnum::TOP55SW_PGSOFT->value,
            VendorIdEnum::TOP55SW_HABANERO->value,
            VendorIdEnum::TOP55SW_AVIATOR->value,
            VendorIdEnum::TOP55SW_SPRIBE->value,
            VendorIdEnum::TOP55SW_INOUT->value,
            VendorIdEnum::TOP55SW_JDB_FISHING->value,
        ],
        [
            'third_party_code' => 'TOP55SW',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
        ],
    )
    // TTHSW 聚合單錢包：子遊戲商共用同一三方 code 與設定
    + array_fill_keys(
        [
            VendorIdEnum::TTHSW->value,
            VendorIdEnum::TTHSW_JILI->value,
            VendorIdEnum::TTHSW_EVO888->value,
            VendorIdEnum::TTHSW_VPLUS->value,
            VendorIdEnum::TTHSW_LM->value,
            VendorIdEnum::TTHSW_DGS->value,
            VendorIdEnum::TTHSW_PRGM->value,
            VendorIdEnum::TTHSW_BTI->value,
            VendorIdEnum::TTHSW_DG->value,
            VendorIdEnum::TTHSW_7MOJOS->value,
            VendorIdEnum::TTHSW_MASCOT->value,
            VendorIdEnum::TTHSW_ICONIC21->value,
            VendorIdEnum::TTHSW_BLUEPRINT->value,
            VendorIdEnum::TTHSW_HACKSAW->value,
            VendorIdEnum::TTHSW_RELAX_GAMING->value,
            VendorIdEnum::TTHSW_BIGPOT_GAMING->value,
            VendorIdEnum::TTHSW_VENUS->value,
            VendorIdEnum::TTHSW_IBC->value,
            VendorIdEnum::TTHSW_GALAXSYS->value,
            VendorIdEnum::TTHSW_CHOICE->value,
            VendorIdEnum::TTHSW_NEXTSPIN->value,
            VendorIdEnum::TTHSW_SPADE_GAMING->value,
            VendorIdEnum::TTHSW_JOKER->value,
            VendorIdEnum::TTHSW_WBET->value,
            VendorIdEnum::TTHSW_CT855->value,
            VendorIdEnum::TTHSW_SEXY_BACCARAT->value,
            VendorIdEnum::TTHSW_ALLBET->value,
            VendorIdEnum::TTHSW_PLAYTECH->value,
            VendorIdEnum::TTHSW_EVOLUTION_GAMING->value,
            VendorIdEnum::TTHSW_CLOTPLAY->value,
            VendorIdEnum::TTHSW_AVATARUX->value,
            VendorIdEnum::TTHSW_PEGASUS->value,
            VendorIdEnum::TTHSW_NO_LIMIT_CITY->value,
            VendorIdEnum::TTHSW_LFC->value,
            VendorIdEnum::TTHSW_FACHAI->value,
            VendorIdEnum::TTHSW_JDB->value,
            VendorIdEnum::TTHSW_UUSLOT->value,
            VendorIdEnum::TTHSW_RICH_GAME->value,
            VendorIdEnum::TTHSW_MEGA_H5->value,
            VendorIdEnum::TTHSW_RICH_GAMING->value,
            VendorIdEnum::TTHSW_CQ9->value,
            VendorIdEnum::TTHSW_ADVANT_PLAY->value,
            VendorIdEnum::TTHSW_NETENT->value,
            VendorIdEnum::TTHSW_RED_TIGER->value,
            VendorIdEnum::TTHSW_NAGA_GAMES->value,
            VendorIdEnum::TTHSW_HABANERO->value,
            VendorIdEnum::TTHSW_MICROGAMING->value,
            VendorIdEnum::TTHSW_BIGTIMEGAMING->value,
            VendorIdEnum::TTHSW_WF_GAMING->value,
            VendorIdEnum::TTHSW_RICH88->value,
            VendorIdEnum::TTHSW_BOOMINGGAMES->value,
            VendorIdEnum::TTHSW_WUDI->value,
            VendorIdEnum::TTHSW_FUMA->value,
            VendorIdEnum::TTHSW_IDEAL_GAMING->value,
        ],
        [
            'third_party_code' => 'TTHSW',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
        ],
    )
    // WUDISW：正常與免費場共用同一三方 code 與設定
    + array_fill_keys(
        [
            VendorIdEnum::WUDISW->value,
            VendorIdEnum::WUDISW_FREE->value,
        ],
        [
            'third_party_code' => 'WUDISW',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
        ],
    )
    // FUMASW：正常與免費場共用同一三方 code 與設定
    + array_fill_keys(
        [
            VendorIdEnum::FUMASW->value,
            VendorIdEnum::FUMASW_FREE->value,
        ],
        [
            'third_party_code' => 'FUMASW',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
        ],
    )
    // PragmaticPlay：轉帳錢包（PP）與 POP 共用同一三方 code。
    // 注單由 fetch_bet_record 拉進 pp_bet_records 後轉入 TBR，
    // TBR 的 payout_time 取注單的 end_time（見 SP_transfer_bet_record_pp）
    + [
        VendorIdEnum::PP->value => [
            'third_party_code' => 'PP',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
            'reconcile_timezone' => 0,
        ],
        // POP 對帳時區為 +8（與 PP 的 +0 不同，Tim 2026-09-15 確認），沿用預設不設 reconcile_timezone；
        // 每日早上以對帳時區重跑前一整天（轉帳錢包：先拉注單再彙總）
        VendorIdEnum::PPPOP->value => [
            'third_party_code' => 'PP',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
            'next_day_full_rerun' => true,
        ],
    ]
    // target 聚合：JILI / PGS_LIVE 與 target 共用同一三方 code（舊專案 getVendorsByThirdPartyCode('target')）
    + array_fill_keys(
        [
            VendorIdEnum::target->value,
            VendorIdEnum::JILI->value,
            VendorIdEnum::PGS_LIVE->value,
        ],
        [
            'third_party_code' => 'target',
            'time_field' => 'payout_time',
            'schedule_enabled' => false,
        ],
    )
    // DCT 聚合：RELAX / HS 與 DCT 共用同一三方 code（舊專案 getVendorsByThirdPartyCode('DCT')）；
    // 對帳 SQL 只把 DCT / RELAX 列在 +0 清單，HS 維持預設 +8
    + array_fill_keys(
        [
            VendorIdEnum::DCT->value,
            VendorIdEnum::RELAX->value,
        ],
        [
            'third_party_code' => 'DCT',
            'time_field' => 'bet_time',
            'schedule_enabled' => false,
            'reconcile_timezone' => 0,
        ],
    )
    + [
        VendorIdEnum::HS->value => [
            'third_party_code' => 'DCT',
            'time_field' => 'bet_time',
            'schedule_enabled' => false,
        ],
    ];
