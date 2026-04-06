<?php

namespace app\dto;

class AddressSuggestionDto
{
    public int $id;
    public string $fullAddress;

    public function __construct(int $id, string $fullAddress)
    {
        $this->id           = $id;
        $this->fullAddress  = $fullAddress;
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'full_address'  => $this->fullAddress,
        ];
    }
}