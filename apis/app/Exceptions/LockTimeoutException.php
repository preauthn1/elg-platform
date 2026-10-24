<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 取用 MySQL named lock 逾時（併發序列化未取得鎖）時拋出。
 *
 * 專屬型別，讓套鎖入口的外層 catch 只承接「取鎖失敗」一種情況，
 * 不誤攔從 transaction body 逸出的其他 {@see ApiException}（那些維持冒至
 * callAction 的 Throwable 處理 → INTERNAL_ERROR，與套鎖前行為一致）。
 */
class LockTimeoutException extends ApiException {}
