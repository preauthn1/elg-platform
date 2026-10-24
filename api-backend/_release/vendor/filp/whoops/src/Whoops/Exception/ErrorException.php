<?php
/**
 * Whoops - php errors for cool kids
 * @author Filipe Dobreira <http://example.invalid/filp>
 */

namespace Whoops\Exception;

use ErrorException as BaseErrorException;

/**
 * Wraps ErrorException; mostly used for typing (at least now)
 * to easily cleanup the stack trace of redundant info.
 */
class ErrorException extends BaseErrorException
{
}
