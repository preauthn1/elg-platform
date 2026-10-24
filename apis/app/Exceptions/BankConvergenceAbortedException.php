<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * 銀行收斂在交易內主動中止（超量、殘留引用、改指筆數不符）時拋出。
 *
 * 專屬型別，不用 {@see RuntimeException} 直接承接：Laravel 的 QueryException 繼承
 * PDOException、PDOException 又繼承 RuntimeException，若外層 catch RuntimeException
 * 就會把 deadlock、SQL 或 schema 錯誤一併吞成「預期的中止」，掩蓋真正的故障與 stack。
 */
class BankConvergenceAbortedException extends RuntimeException {}
