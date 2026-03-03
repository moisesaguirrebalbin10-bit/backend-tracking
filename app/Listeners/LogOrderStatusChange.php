<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderStatusChanged;

class LogOrderStatusChange
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event): void
    {
        // The OrderLog is already created in the OrderStatusService
        // This listener can be used for additional actions like:
        // - Sending notifications
        // - Broadcasting to WebSockets
        // - Triggering external integrations
        // - Analytics tracking

        \Log::channel('orders')->info('Order status changed', [
            'order_id' => $event->order->id,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'changed_by' => $event->changedBy,
        ]);
    }
}
