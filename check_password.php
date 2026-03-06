<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::where('email', 'admin@example.com')->first();
if ($user) {
    echo "Usuario encontrado: {$user->email}\n";
    echo "Password hash: {$user->password}\n";
    echo "Verificación de 'password': " . (Hash::check('password', $user->password) ? 'OK' : 'FAIL') . "\n";
} else {
    echo "Usuario no encontrado\n";
}