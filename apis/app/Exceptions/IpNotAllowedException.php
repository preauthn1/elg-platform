<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * 三方回調來源 IP 不在該遊戲商的白名單內。
 */
class IpNotAllowedException extends Exception {}
