<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Auth\OperationalUsersProvisioner;
use Illuminate\Database\Seeder;

class OperationalUsersSeeder extends Seeder
{
    public function run(): void
    {
        app(OperationalUsersProvisioner::class)->provision();
    }
}