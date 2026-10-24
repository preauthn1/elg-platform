<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Datas;

use Carbon\Carbon;

class TransferInfoData
{
    public string $username;

    public float $amount;

    public string $gameCode;

    public string $orderId;

    public string $datetime;

    public float $bet = 0;

    public float $validBet = 0;

    public float $win = 0;

    public array $infos;

    public function __construct(array $data)
    {
        $transDetails = json_decode($data['trans_details'], true);
        $betformDetails = json_decode($data['betform_details'] ?? '{}', true);

        $this->username = $data['username'];
        $this->amount = (float) $data['amount'];
        $this->gameCode = $data['game_code'];
        $this->orderId = $transDetails['order_id'];

        if (!empty($betformDetails)) {
            $this->bet = (float) $betformDetails['bet'];
            $this->validBet = (float) $betformDetails['valid_bet'];
            $this->win = (float) $betformDetails['win'];
            $this->datetime = Carbon::parse($betformDetails['result_time'])
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s');
        } else {
            $this->datetime = Carbon::createFromTimestamp($transDetails['request_time'])
                ->format('Y-m-d H:i:s');
        }

        $this->infos = $data;
    }

    public static function from(array $data): self
    {
        return new self($data);
    }
}
