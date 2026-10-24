<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Datas;

class SettleInfoData
{
    public string $transferId;

    public string $username;

    public string $roundId;

    public int $freewin;

    public int $lastfree;

    public float $win;

    public string $gameCode;

    public string $datetime;

    public array $infos;

    public function __construct(array $data)
    {
        $this->transferId = (string) $data['transferId'];
        $this->username = $data['uid'];
        $this->roundId = (string) $data['roundId'];
        $this->freewin = $data['freewin'] ?? 0;
        $this->lastfree = $data['lastfree'] ?? 0;
        $this->win = $data['win'];
        $this->gameCode = (string) $data['gType'];
        $this->datetime = date('Y-m-d H:i:s', (int) ($data['ts'] / 1000));

        $this->infos = $data;
    }
}
