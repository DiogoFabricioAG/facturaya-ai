<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyApiToken;
use Illuminate\Support\Str;

final class CompanyApiTokenService
{
    /**
     * @return array{token: CompanyApiToken, plain_text: string}
     */
    public function create(Company $company, string $name): array
    {
        $plainText = 'fya_'.Str::random(48);
        $token = $company->apiTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainText),
            'token_hint' => substr($plainText, 0, 12),
        ]);

        return ['token' => $token, 'plain_text' => $plainText];
    }
}
