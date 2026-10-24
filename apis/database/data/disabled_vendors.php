<?php

declare(strict_types=1);

use App\Enums\Vendor\VendorIdEnum;

/**
 * 已停用遊戲商 vendor_id 清單
 *
 * 判定：全部白標站皆無任何 agent_group 開啟入口（vendor_customized_items 無 status=1），
 * 或從未建檔的保留編號。
 *
 * 來源：2026-08-05 六白標 vendor_customized_items 盤點（id < 100 全停用者）
 *       ＋ 2026-08-10 人工確認名單（75, 107, 108, 121, 122, 126, 143, 146, 148, 177, 182, 235）
 *       ＋ 2026-08-12 人工確認名單（49, 51——入口尚開但已無投注，APIS 無 driver）
 *       ＋ 2026-09-07 人工確認名單（XE88, YGG, MENANG——遊戲商不再使用，抓注單排程亦將關閉）
 *       ＋ 2026-09-22 人工確認名單（BOLA2——三站 vendors.status 皆為 0，補建帳號能力已隨
 *       target/tickets#4351 搬遷至 APIS，但目前無實際使用需求，暫列入停用）
 *       －2026-09-14 移出（target, target Live——與 JILI／FUNKY 共用 target 聚合 driver，須納入補建帳號範圍）。
 *       －2026-10-01 移出（PPSW——業務確認仍在使用，須納入補建帳號範圍）。
 *
 * 用途：停用商不納入強制規範檢查（如 VendorAccountsTableSchemaTest）；
 *       復用或重新啟用遊戲商時，須自本清單移除。
 */
return [
    VendorIdEnum::NOC->value,               // 沒有雞的鬥雞
    VendorIdEnum::VPLAY->value,             // Vplay
    VendorIdEnum::CF->value,                // Coke Fighting
    VendorIdEnum::LUCKY->value,             // Lucky
    VendorIdEnum::ISB->value,               // iSB
    VendorIdEnum::PT->value,                // PlayTech(X)
    VendorIdEnum::SBO->value,               // SBO（vendors 主檔名「停用」）
    VendorIdEnum::PG->value,                // Play'n GO
    VendorIdEnum::IGS->value,               // IGS
    VendorIdEnum::SKYBET->value,            // Skybet
    VendorIdEnum::N918->value,              // 918
    VendorIdEnum::TERRA->value,             // Terra
    VendorIdEnum::ALLBET->value,            // All
    VendorIdEnum::VSPOKER->value,           // VSPoker
    VendorIdEnum::ESPORT->value,            // Esport
    VendorIdEnum::FISH->value,              // PGS_Fishing
    VendorIdEnum::PLUS->value,              // 100Plus
    VendorIdEnum::BNG->value,               // LFC（code bng）
    VendorIdEnum::MGC->value,               // MGC
    VendorIdEnum::PGSLOT->value,            // PG_Soft
    VendorIdEnum::SL->value,                // SiamLotto
    VendorIdEnum::DS88->value,              // DS88
    VendorIdEnum::HS->value,                // hacksaw
    VendorIdEnum::KY->value,                // KY（六站皆未建檔）
    VendorIdEnum::TITAN_SBO->value,         // SBO Sport
    VendorIdEnum::TEST->value,              // Testgame
    107,                                    // 未建檔（保留編號，無 enum case）
    108,                                    // 未建檔（保留編號，無 enum case）
    VendorIdEnum::CRSW->value,              // CRSW
    VendorIdEnum::J3->value,                // J3
    VendorIdEnum::WMLIVESW->value,          // WM Live
    VendorIdEnum::FFFSW->value,             // FFF Marble League
    VendorIdEnum::BNGSW->value,             // BNG
    VendorIdEnum::IMPSW->value,             // IMPERIUM
    VendorIdEnum::JOKER_BACCARAT->value,    // JokerBaccarat
    VendorIdEnum::TOP55SW_DG->value,        // Dream Gaming(Top55SW)
    VendorIdEnum::JOKER_BACCARAT_SW->value, // Joker Baccarat SW
    VendorIdEnum::XE88->value,              // Xe88（2026-09-07 人工確認名單，先前漏補進陣列）
    VendorIdEnum::YGG->value,               // Ygg（同上）
    VendorIdEnum::MENANG->value,            // Menang88（同上）
    VendorIdEnum::BOLA->value,              // Bola2（三站無實際使用，補建帳號能力保留但暫不排程）
];
