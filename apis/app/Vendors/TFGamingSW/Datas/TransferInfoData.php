<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Datas;

use Spatie\LaravelData\Data;

class TransferInfoData extends Data
{
    public string $username;

    public float $amount;

    public bool $isPlaceBet;

    public string $ticketNum;

    public string $ticketId;

    public string $description;

    public string $datetime;

    public string $settleTime;

    public string $infos;

    public function __construct(array $data)
    {
        $this->username = $data['loginName'];
        $this->amount = $data['amount'];
        $this->isPlaceBet = $data['placeBet'];
        $this->ticketNum = $data['ticketNum'];
        $this->description = $data['description'];
        $this->ticketId = $data['ticketDetail']['id'];
        $this->datetime = date('Y-m-d H:i:s', strtotime($data['ticketDetail']['date_created']));

        if (empty($data['ticketDetail']['settlement_datetime'])) {
            $this->settleTime = $this->datetime;
        } else {
            $this->settleTime = date('Y-m-d H:i:s', strtotime($data['ticketDetail']['settlement_datetime']));
        }

        $this->infos = json_encode($data);
    }
}
