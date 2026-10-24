<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Datas;

use App\Helpers\Common;

readonly class BetInfoData
{
    public string $transferId;

    public string $username;

    public string $roundId;

    public string $currency;

    public string $bet;

    public string $gameCode;

    public string $datetime;

    public array $infos;

    public function __construct(array $data)
    {
        $this->transferId = (string) $data['transferId'];
        $this->username = (string) $data['uid'];
        $this->roundId = (string) $data['roundId'];
        $this->currency = (string) $data['currency'];
        $this->bet = Common::numAdd('0', (string) $data['bet'], 4);
        $this->gameCode = (string) $data['gType'];
        $this->datetime = date('Y-m-d H:i:s', (int) ($data['ts'] / 1000));

        $this->infos = $data;
    }
}
