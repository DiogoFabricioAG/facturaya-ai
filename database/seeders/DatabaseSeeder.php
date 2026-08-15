<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $company = Company::query()->firstOrCreate(['ruc' => '20000000001'], [
            'legal_name' => 'FACTURAYA DEMO S.A.C.',
            'trade_name' => 'FacturaYa Demo',
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'address' => 'Av. Principal 123',
            'sunat_driver' => 'fake',
            'sunat_environment' => 'beta',
            'default_series' => 'F001',
            'active' => true,
        ]);

        $plainText = 'fya_demo_local_token';
        $company->apiTokens()->firstOrCreate([
            'token_hash' => hash('sha256', $plainText),
        ], [
            'name' => 'Token demostración local',
            'token_hint' => 'fya_demo_loc',
        ]);

        $this->command?->info('Empresa demo lista. Token: '.$plainText);
    }
}
