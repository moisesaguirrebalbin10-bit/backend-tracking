<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Support\Facades\Hash;

// Crear usuarios de prueba
User::create([
    'name' => 'Admin User',
    'email' => 'admin@example.com',
    'password' => Hash::make('admin123'),
    'role' => UserRole::ADMIN,
    'is_admin' => true
]);

User::create([
    'name' => 'Vendedor Redes',
    'email' => 'vendedor@example.com',
    'password' => Hash::make('vendedor123'),
    'role' => UserRole::VENDEDOR_REDES
]);

User::create([
    'name' => 'Ventas Web',
    'email' => 'ventas@example.com',
    'password' => Hash::make('ventas123'),
    'role' => UserRole::VENTAS_WEB
]);

echo "Usuarios creados exitosamente!\n";