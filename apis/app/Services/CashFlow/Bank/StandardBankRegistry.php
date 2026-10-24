<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

/**
 * 標準銀行分檔（app/Console/Commands/CashFlow/Data/banks/{幣別}.php）的兩種讀法：
 * cash-flow:sync 看到的條目，與寫進 standard_banks 的列。
 *
 * 多數條目兩邊相同。兩個欄位讓兩邊暫時分開，用在「要改銀行身分，但不能動到金流商代碼」的情況：
 *
 * - `retired_into`（填現在那家的 code）：這家已經合併或改名，併進了現在那家。
 *   寫進 standard_banks 時，這一列設成停用，它的名稱與別名併進現在那家的別名，
 *   bank:sync-mappings 因此會把舊名銀行列對到現在那家。
 *   cash-flow:sync 照舊用這個條目比對金流商的銀行名稱，所以金流商代碼掛在哪一列平台銀行不變。
 * - `sync_visible => false`：條目只寫進 standard_banks，cash-flow:sync 與 bank:fix-mismatch 看不到它。
 *   它的名稱與別名在 standard_banks 只歸它，會從同幣別其他列的別名移除。
 *   比對時這些名稱一律當成比對不到（見 hiddenLabels），金流商的這個碼在條目拿掉標記前不會掛到任何平台銀行。
 *   用在要從既有條目拆出來、但各金流商代碼還沒逐家查清的條目。
 *
 * 為什麼 cash-flow:sync 不能跟著改：sync 先把金流商所有 cash_flow_banks 列停用，再把比對得到的列重新啟用。
 * 一個名稱改對到另一列平台銀行，原本那列的 cash_flow_banks 就停在停用，綁那列的會員出款查不到碼。
 * 同一個金流商新舊名稱都列、各帶一個碼的情況很常見，要留哪個碼只能逐家查證，不能從名稱推。
 * 所以兩個欄位要等各站舊名銀行列收成一列之後才拿掉。
 * 拿掉時注意：seeder 只會把列寫成停用、不會寫回啟用，所以拿掉 `retired_into` 要連條目一起刪，
 * 不能只刪標記指望它恢復啟用。
 *
 * 只處理陣列、不碰 DB 與框架，seeder、比對 helper 與純 config 的單元測試共用。
 *
 * @knowledge rules/standard-bank-list
 */
final class StandardBankRegistry
{
    public const string RETIRED_INTO = 'retired_into';

    public const string SYNC_VISIBLE = 'sync_visible';

    /**
     * cash-flow:sync 與 bank:fix-mismatch 看到的條目：排除只寫進 standard_banks 的條目。
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public static function syncEntries(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry[self::SYNC_VISIBLE] ?? true) !== false,
        ));
    }

    /**
     * sync_visible=false 條目擁有的名稱（name、standard_name 去幣別前綴、別名），未正規化。
     *
     * cash-flow:sync 遇到這些名稱必須當成比對不到、照低信心度跳過，只濾掉條目不夠：
     * 一般戶條目的別名與關鍵字仍會把 UUS 名稱對到一般戶那列平台銀行，同一個金流商一般戶碼排在前、
     * UUS 碼排在後時，後寫的 UUS 碼會蓋掉一般戶碼（例：TopPay Kalbar 123 被 Kalbar UUS 1230 蓋掉）；
     * 一般戶的平台銀行名稱與標準名不同時，還會新建一列重複的平台銀行（例：Kaltim UUS）。
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    public static function hiddenLabels(array $entries): array
    {
        $labels = [];

        foreach ($entries as $entry) {
            if (($entry[self::SYNC_VISIBLE] ?? true) === false) {
                $labels = [...$labels, ...self::labels($entry)];
            }
        }

        return $labels;
    }

    /**
     * 寫進 standard_banks 的列。每列帶 `retired`（是否設成停用），別名依類別文件的兩條規則調整：
     *
     * 1. 現在那家的別名，加上併入它的舊條目的 name、standard_name（去幣別前綴）與別名。
     *    已經是同幣別其他現行列擁有的名稱，不會被併進來，避免同一個名稱有兩個擁有者。
     * 2. sync_visible=false 的條目擁有的名稱，從同幣別其他列的別名移除。
     *
     * @param  list<array<string, mixed>>  $entries  每筆須帶 currency 鍵；可多幣別混在一起
     * @return list<array<string, mixed>>
     */
    public static function dbEntries(array $entries): array
    {
        $hiddenOwner = [];
        $activeOwner = [];
        $carried = [];

        foreach ($entries as $entry) {
            $currency = (string) $entry['currency'];
            $code = (string) $entry['code'];

            if (($entry[self::SYNC_VISIBLE] ?? true) === false) {
                foreach (self::labels($entry) as $label) {
                    $hiddenOwner[$currency][self::normalize($label)] = $code;
                }
            }

            if (isset($entry[self::RETIRED_INTO])) {
                $carried[(string) $entry[self::RETIRED_INTO]] = [
                    ...$carried[(string) $entry[self::RETIRED_INTO]] ?? [],
                    ...self::labels($entry),
                ];

                continue;
            }

            foreach (self::labels($entry) as $label) {
                $activeOwner[$currency][self::normalize($label)][$code] = true;
            }
        }

        $rows = [];

        foreach ($entries as $entry) {
            $currency = (string) $entry['currency'];
            $code = (string) $entry['code'];
            $aliases = [];
            $seen = [self::normalize((string) $entry['name']) => true];

            foreach ($entry['aliases'] ?? [] as $alias) {
                $owner = $hiddenOwner[$currency][self::normalize((string) $alias)] ?? $code;

                if ($owner === $code) {
                    $aliases[] = $alias;
                    $seen[self::normalize((string) $alias)] = true;
                }
            }

            foreach ($carried[$code] ?? [] as $label) {
                $key = self::normalize($label);
                $owners = array_keys($activeOwner[$currency][$key] ?? []);

                if (isset($seen[$key]) || array_diff($owners, [$code]) !== [] || isset($hiddenOwner[$currency][$key])) {
                    continue;
                }

                $aliases[] = $label;
                $seen[$key] = true;
            }

            $rows[] = [...$entry, 'aliases' => $aliases, 'retired' => isset($entry[self::RETIRED_INTO])];
        }

        return $rows;
    }

    /**
     * 對齊 BankStandardMappingService::normalizeName：剝 `(CUR) - ` 前綴、trim、轉小寫。
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', trim($name))));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function labels(array $entry): array
    {
        $labels = [(string) $entry['name']];

        if (isset($entry['standard_name'])) {
            $labels[] = (string) preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', (string) $entry['standard_name']);
        }

        return [...$labels, ...array_map('strval', $entry['aliases'] ?? [])];
    }
}
