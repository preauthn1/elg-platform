<?php

declare(strict_types=1);

use App\Enums\LanguageEnum;

/**
 * languages 表的語系清單，供 SyncLanguagesSeeder 寫入。
 *
 * id 直接引用 LanguageEnum：整個系統（Context 的 LANGUAGE_ID、language_texts.language_id、
 * 各遊戲商 Driver 的語系對照）都以 id 為準，且 GameController 會用 LanguageEnum::from($languageId)，
 * enum 少了 case 會直接拋 ValueError，因此兩者不允許不同步。
 *
 * sort 不在此指定，由 seeder 依現有最大值往後接，新語系一律排在既有語系之後。
 * 遊戲商語系欄位（wcasino / pg / evo ...）沿用 migration 預設值：apis 目前沒有任何程式讀取這些欄位，
 * 各 Driver 都是用 LanguageEnum 對照表並帶 default 值。
 */
return [
    [
        'id' => LanguageEnum::KO->value,
        'name' => '한국어',
        'code' => 'ko',
        'status' => 1,
    ],
    [
        'id' => LanguageEnum::FR->value,
        'name' => 'Français',
        'code' => 'fr',
        'status' => 1,
    ],
];
