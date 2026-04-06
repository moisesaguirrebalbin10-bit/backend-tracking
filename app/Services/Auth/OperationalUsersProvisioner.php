<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;

class OperationalUsersProvisioner
{
    /**
     * @return array<int, array{user: User, created: bool}>
     */
    public function provision(?string $password = null): array
    {
        $resolvedPassword = $password !== null && trim($password) !== ''
            ? $password
            : (string) env('OPERATIONAL_USERS_DEFAULT_PASSWORD', 'secret123');

        $results = [];

        foreach ($this->accounts() as $account) {
            /** @var User|null $existing */
            $existing = User::query()->firstWhere('email', $account['email']);
            $created = $existing === null;

            $user = $existing ?? new User();
            $user->fill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => $resolvedPassword,
                'role' => $account['role'],
                'is_admin' => false,
                'last_seen_at' => null,
            ]);
            $user->save();

            $results[] = [
                'user' => $user->fresh(),
                'created' => $created,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{name: string, email: string, role: UserRole}>
     */
    private function accounts(): array
    {
        return [
            [
                'name' => 'Operaciones Empaquetado',
                'email' => (string) env('OPERATIONAL_PACKER_EMAIL', 'operaciones.empaquetador@tracking.local'),
                'role' => UserRole::EMPAQUETADOR,
            ],
            [
                'name' => 'Operaciones Despacho',
                'email' => (string) env('OPERATIONAL_DISPATCHER_EMAIL', 'operaciones.despachador@tracking.local'),
                'role' => UserRole::DESPACHADOR,
            ],
            [
                'name' => 'Delivery Pruebas',
                'email' => (string) env('OPERATIONAL_DELIVERY_EMAIL', 'delivery.pruebas@tracking.local'),
                'role' => UserRole::DELIVERY,
            ],
        ];
    }
}