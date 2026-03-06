<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\OrderTimeline;
use App\Models\OrderLog;
use App\Enums\OrderStatus;
use Carbon\Carbon;

// Crear órdenes de prueba
$order1 = Order::create([
    'user_id' => 1, // Tester
    'external_id' => 'WC-2001',
    'status' => OrderStatus::EN_PROCESO,
    'metadata' => json_encode([
        'customer_name' => 'Juan Pérez',
        'total' => 150.00,
        'items' => [
            ['name' => 'Producto A', 'quantity' => 2, 'price' => 50.00],
            ['name' => 'Producto B', 'quantity' => 1, 'price' => 50.00]
        ]
    ])
]);

$order2 = Order::create([
    'user_id' => 2, // Admin User
    'external_id' => 'WC-2002',
    'status' => OrderStatus::EMPAQUETADO,
    'metadata' => json_encode([
        'customer_name' => 'María García',
        'total' => 75.50,
        'items' => [
            ['name' => 'Producto C', 'quantity' => 1, 'price' => 75.50]
        ]
    ])
]);

$order3 = Order::create([
    'user_id' => 3, // Vendedor Redes
    'external_id' => 'WC-2003',
    'status' => OrderStatus::DESPACHADO,
    'metadata' => json_encode([
        'customer_name' => 'Carlos López',
        'total' => 200.00,
        'items' => [
            ['name' => 'Producto D', 'quantity' => 4, 'price' => 50.00]
        ]
    ])
]);

// Crear timelines para las órdenes
OrderTimeline::create([
    'order_id' => $order1->id,
    'status' => OrderStatus::EN_PROCESO,
    'user_id' => 1,
    'notes' => 'Orden creada desde WooCommerce',
    'occurred_at' => Carbon::now()
]);

OrderTimeline::create([
    'order_id' => $order2->id,
    'status' => OrderStatus::EN_PROCESO,
    'user_id' => 2,
    'notes' => 'Orden creada',
    'occurred_at' => Carbon::now()->subMinutes(30)
]);

OrderTimeline::create([
    'order_id' => $order2->id,
    'status' => OrderStatus::EMPAQUETADO,
    'user_id' => 2,
    'notes' => 'Orden empacada y lista para envío',
    'occurred_at' => Carbon::now()->subMinutes(15)
]);

OrderTimeline::create([
    'order_id' => $order3->id,
    'status' => OrderStatus::EN_PROCESO,
    'user_id' => 3,
    'notes' => 'Orden creada',
    'occurred_at' => Carbon::now()->subHours(2)
]);

OrderTimeline::create([
    'order_id' => $order3->id,
    'status' => OrderStatus::EMPAQUETADO,
    'user_id' => 3,
    'notes' => 'Empacado completado',
    'occurred_at' => Carbon::now()->subHours(1)
]);

OrderTimeline::create([
    'order_id' => $order3->id,
    'status' => OrderStatus::DESPACHADO,
    'user_id' => 3,
    'notes' => 'Enviado a delivery',
    'occurred_at' => Carbon::now()->subMinutes(30)
]);

// Crear logs para las órdenes
OrderLog::create([
    'order_id' => $order1->id,
    'user_id' => 1,
    'action' => 'created',
    'new_status' => OrderStatus::EN_PROCESO,
    'description' => 'Orden creada desde WooCommerce',
    'ip_address' => '127.0.0.1'
]);

OrderLog::create([
    'order_id' => $order2->id,
    'user_id' => 2,
    'action' => 'created',
    'new_status' => OrderStatus::EN_PROCESO,
    'description' => 'Orden creada',
    'ip_address' => '127.0.0.1'
]);

OrderLog::create([
    'order_id' => $order2->id,
    'user_id' => 2,
    'action' => 'status_changed',
    'old_status' => OrderStatus::EN_PROCESO,
    'new_status' => OrderStatus::EMPAQUETADO,
    'description' => 'Estado cambiado a empaquetado',
    'ip_address' => '127.0.0.1'
]);

OrderLog::create([
    'order_id' => $order3->id,
    'user_id' => 3,
    'action' => 'created',
    'new_status' => OrderStatus::EN_PROCESO,
    'description' => 'Orden creada',
    'ip_address' => '127.0.0.1'
]);

OrderLog::create([
    'order_id' => $order3->id,
    'user_id' => 3,
    'action' => 'status_changed',
    'old_status' => OrderStatus::EN_PROCESO,
    'new_status' => OrderStatus::EMPAQUETADO,
    'description' => 'Empacado completado',
    'ip_address' => '127.0.0.1'
]);

OrderLog::create([
    'order_id' => $order3->id,
    'user_id' => 3,
    'action' => 'status_changed',
    'old_status' => OrderStatus::EMPAQUETADO,
    'new_status' => OrderStatus::DESPACHADO,
    'description' => 'Enviado a delivery',
    'ip_address' => '127.0.0.1'
]);

echo "Órdenes y datos de prueba creados exitosamente!\n";
echo "Orden 1 ID: {$order1->id}\n";
echo "Orden 2 ID: {$order2->id}\n";
echo "Orden 3 ID: {$order3->id}\n";