<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino\Datas;

use App\Vendors\Wcasino\Enums\NotifyTypeEnum;

class BetInfoData
{
    public string $appid;

    public string $username;

    public float $amount;

    public float $validBet = 0.00;

    public string $notifyid;

    public NotifyTypeEnum $type;

    public string $serialnumber;

    public string $sign;

    public array|string|null $bets;

    public string $infos;

    public string $createDateTime;

    public string $datetime;

    public string $gamecode = 'TP01';

    public static function from(array $data): self
    {
        $instance = new self;

        $instance->appid = $data['appid'];
        $instance->username = $data['username'];
        $instance->amount = (float) $data['amount'];
        $instance->notifyid = $data['notifyid'];
        $instance->type = NotifyTypeEnum::from((int) $data['type']);
        $instance->serialnumber = $data['serialnumber'];
        $instance->sign = $data['sign'];
        $instance->bets = $data['bets'] ?? [];
        $instance->infos = json_encode($data);

        if (is_string($instance->bets)) {
            $instance->bets = json_decode($instance->bets, true);
        }

        if (isset($instance->bets['settletime'])) {
            $datetime = date('Y-m-d H:i:s', $instance->bets['settletime']);
        } else {
            $datetime = date('Y-m-d H:i:s');
        }
        $instance->datetime = $datetime;

        if (isset($instance->bets['createtime'])) {
            $createDateTime = date('Y-m-d H:i:s', $instance->bets['createtime']);
        } else {
            $createDateTime = date('Y-m-d H:i:s');
        }
        $instance->createDateTime = $createDateTime;

        if (isset($instance->bets['commamount'])) {
            $instance->validBet = $instance->bets['commamount'];
        }

        return $instance;
    }
}
