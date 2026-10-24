<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Support;

/**
 * 判斷目前的 DB 連線是不是正式庫。
 *
 * 判準是「DB 連線名」，不是 APP_ENV：正式庫的操作是在容器內以 `-e DB_CONNECTION=<白標>` 注入連線名執行，
 * APP_ENV 不會跟著變。連線名 mysql／dev／testing（不分大小寫）視為非正式，其餘
 * （VWorld、TPower、Me77、Yes2Win、Alfawin77、VPlus33，見 config/database.php）視為正式。
 *
 * 同一把尺已內聯在 AddAgentCashFlow、CopyCredentials、AcfDelete、AcfCascadeRestore、FixCashFlowOrphans、
 * NormalizeBanksByEnvironmentMainCurrency、StripDgPayDepositDynamicKeys、AddVendorInfo 八支命令的確認閘門裡；
 * 本類別是給資料檔（Data/providers/*.php）這種拿不到命令實例的地方用的同一份判準，改判準要兩邊一起改。
 */
final class DatabaseEnvironment
{
    /** @var list<string> 非正式庫的連線名，小寫比對 */
    private const NON_PRODUCTION_CONNECTIONS = ['mysql', 'dev', 'testing'];

    public static function isProductionConnection(?string $connection = null): bool
    {
        $connection ??= (string) config('database.default');

        return !in_array(strtolower($connection), self::NON_PRODUCTION_CONNECTIONS, true);
    }
}
