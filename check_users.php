<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$users = User::all();
echo "Usuarios disponibles:\n";
foreach ($users as $user) {
    echo "- ID: {$user->id}, Email: {$user->email}, Role: {$user->role->value}\n";
}