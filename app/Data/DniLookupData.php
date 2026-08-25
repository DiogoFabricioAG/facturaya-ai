<?php

namespace App\Data;

final readonly class DniLookupData
{
    public function __construct(
        public string $dni,
        public string $names,
        public string $paternalSurname,
        public string $maternalSurname,
        public string $fullName,
        public string $provider = 'api_peru',
        public ?string $verificationCode = null,
        public ?string $verificationLetter = null,
    ) {}
}
