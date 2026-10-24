<?php

namespace App\DTOs;

readonly class DepositInfoDTO
{
    public string $orderTypeId;

    public string $name;

    public string $cfId;

    public string $cfName;

    public string $imagePath;

    public string $popupText;

    public string $url;

    public function __construct(array $data)
    {
        $this->orderTypeId = $data['order_type_id'];
        $this->name = $data['name'] ?? '';
        $this->cfId = $data['cf_id'] ?? 0;
        $this->cfName = $data['cf_name'] ?? '';
        $this->imagePath = $data['image_path'] ?? '';
        $this->popupText = $data['popup_text'] ?? '';
        $this->url = $data['url'] ?? '';
    }
}
