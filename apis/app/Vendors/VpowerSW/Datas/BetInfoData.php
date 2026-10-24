<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Datas;

class BetInfoData
{
    public string $username;

    public string $gameId;

    public string $transactionId;

    public string $gameRoundId;

    public bool $completed;

    public string $datetime;

    public string $balance;

    public int $type;

    public string $infos;

    public static function from(array $data): self
    {
        $instance = new self;

        $instance->username = $data['username'];
        $instance->gameId = (string) $data['gameId'];
        $instance->transactionId = $data['transactionId'];
        $instance->gameRoundId = $data['gameRoundId'];
        $instance->completed = (bool) $data['completed'];
        $instance->type = (int) $data['type'];
        $instance->balance = (string) $data['balance'];
        $instance->datetime = date('Y-m-d H:i:s', (int) ($data['timestamp'] / 1000));

        $instance->infos = json_encode($data);

        return $instance;
    }
}
