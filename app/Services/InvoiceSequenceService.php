<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

final class InvoiceSequenceService
{
    public function next(Company $company, string $series): int
    {
        return DB::transaction(function () use ($company, $series): int {
            InvoiceSequence::query()->firstOrCreate(
                ['company_id' => $company->id, 'series' => $series],
                ['next_number' => 1],
            );

            /** @var InvoiceSequence $sequence */
            $sequence = InvoiceSequence::query()
                ->where('company_id', $company->id)
                ->where('series', $series)
                ->lockForUpdate()
                ->firstOrFail();

            $number = (int) $sequence->next_number;
            $sequence->update(['next_number' => $number + 1]);

            return $number;
        });
    }
}
