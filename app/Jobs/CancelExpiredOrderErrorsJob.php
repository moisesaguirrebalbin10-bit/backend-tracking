<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Orders\OrderStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CancelExpiredOrderErrorsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(OrderStatusService $orderStatusService): void
    {
        // Get or create system user for automation
        $systemUser = User::firstOrCreate(
            ['email' => 'system@tracking.local'],
            [
                'name' => 'System',
                'password' => bcrypt(uniqid()),
                'role' => 'admin',
            ]
        );

        $cancelledCount = $orderStatusService->cancelExpiredErrors($systemUser);

        if ($cancelledCount > 0) {
            \Log::info("Auto-cancelled {$cancelledCount} orders with expired errors");
        }
    }
}
