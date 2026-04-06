<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\OperationalUsersProvisioner;
use Illuminate\Console\Command;

class SeedOperationalUsers extends Command
{
    protected $signature = 'users:seed-operational {--password= : Password to assign to the operational users}';

    protected $description = 'Create or update the default empaquetador, despachador and delivery accounts';

    public function handle(OperationalUsersProvisioner $provisioner): int
    {
        $results = $provisioner->provision($this->option('password'));

        $rows = array_map(static function (array $result): array {
            $user = $result['user'];

            return [
                'id' => $user->id,
                'role' => $user->role?->value,
                'name' => $user->name,
                'email' => $user->email,
                'action' => $result['created'] ? 'created' : 'updated',
            ];
        }, $results);

        $this->table(['ID', 'Role', 'Name', 'Email', 'Action'], $rows);
        $this->warn('La contrasena aplicada es la enviada por --password o, si se omite, OPERATIONAL_USERS_DEFAULT_PASSWORD/secret123.');

        return self::SUCCESS;
    }
}