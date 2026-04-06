<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderTimeline;
use App\Models\User;
use App\Services\WooCommerceManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use InvalidArgumentException;
use RuntimeException;

class OrderStatusService
{
    public function __construct(
        private readonly WooCommerceManager $wooManager,
    ) {
    }

    /**
     * Update the order status with full validation and audit logging.
     *
     * @throws InvalidArgumentException
     */
    public function updateStatus(
        Order $order,
        OrderStatus $newStatus,
        User $user,
        ?int $deliveryUserId = null,
        ?string $errorReason = null,
        ?string $evidenceImagePath = null,
        ?string $ipAddress = null
    ): Order {
        $allowedTransitions = $this->allowedTransitions($order, $user);

        if (! in_array($newStatus, $allowedTransitions, true)) {
            throw new AccessDeniedHttpException(
                $this->buildUnauthorizedTransitionMessage($order, $newStatus, $user)
            );
        }

        // Validate state transition
        if (!$order->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition from {$order->status->value} to {$newStatus->value}"
            );
        }

        // Validate required fields
        if ($newStatus === OrderStatus::ERROR && empty($errorReason)) {
            throw new InvalidArgumentException('Error reason is required for error status');
        }

        $assignedDelivery = $this->resolveAssignedDelivery($newStatus, $deliveryUserId);

        $wooStatus = $this->mapToWooStatus($newStatus);
        $wooSynced = false;

        // Sync only for selected business transitions.
        if ($wooStatus !== null) {
            $this->syncWooOrderStatus($order, $wooStatus);
            $wooSynced = true;
        }

        return DB::transaction(function () use (
            $order,
            $newStatus,
            $user,
            $assignedDelivery,
            $errorReason,
            $evidenceImagePath,
            $wooStatus,
            $wooSynced,
            $ipAddress
        ): Order {
            $oldStatus = $order->status;
            $oldAssignedDelivery = $order->assignedDelivery;

            // Prepare update data
            $updateData = [
                'status' => $newStatus,
                'user_id' => $user->id,
            ];

            // Handle error status
            if ($newStatus === OrderStatus::ERROR) {
                $updateData['error_reason'] = $errorReason;
                $updateData['error_created_at'] = now();
            } elseif ($oldStatus === OrderStatus::ERROR) {
                // Clear error fields when transitioning away from error status
                $updateData['error_reason'] = null;
                $updateData['error_created_at'] = null;
            }

            if ($wooSynced) {
                $updateData['synced_at'] = now();
            }

            if ($newStatus === OrderStatus::DESPACHADO) {
                $updateData['assigned_delivery_user_id'] = $assignedDelivery?->id;
            }

            // Keep delivery image at order level only for delivered proof.
            if ($newStatus === OrderStatus::ENTREGADO && $evidenceImagePath) {
                $updateData['delivery_image_path'] = $evidenceImagePath;
            }

            // Update order
            $order->update($updateData);

            // Create audit log
            $this->logStatusChange(
                $order,
                $oldStatus,
                $newStatus,
                $user,
                $oldAssignedDelivery,
                $assignedDelivery,
                $errorReason,
                $evidenceImagePath,
                $wooStatus,
                $wooSynced,
                $ipAddress
            );

            OrderTimeline::query()->create([
                'order_id' => $order->id,
                'status' => $newStatus->value,
                'message' => $this->timelineMessage($newStatus, $assignedDelivery, $wooSynced, $wooStatus),
                'source' => $wooSynced ? 'system+woo' : 'system',
                'occurred_at' => now('UTC'),
            ]);

            // Dispatch event for real-time updates via WebSockets
            OrderStatusChanged::dispatch(
                $order,
                $oldStatus->value,
                $newStatus->value,
                $user->name
            );

            return $order->refresh();
        });
    }

    /**
     * Create an audit log entry for the status change.
     */
    private function logStatusChange(
        Order $order,
        OrderStatus $oldStatus,
        OrderStatus $newStatus,
        User $user,
        ?User $oldAssignedDelivery = null,
        ?User $assignedDelivery = null,
        ?string $errorReason = null,
        ?string $evidenceImagePath = null,
        ?string $wooStatus = null,
        bool $wooSynced = false,
        ?string $ipAddress = null
    ): OrderLog {
        $changes = [
            'status' => [
                'old' => $oldStatus->value,
                'new' => $newStatus->value,
            ],
            'actor' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
            ],
            'woo_sync' => [
                'applied' => $wooSynced,
                'status' => $wooStatus,
            ],
        ];

        if ($oldAssignedDelivery?->id !== $assignedDelivery?->id) {
            $changes['delivery_assignment'] = [
                'old' => $oldAssignedDelivery ? [
                    'id' => $oldAssignedDelivery->id,
                    'name' => $oldAssignedDelivery->name,
                    'email' => $oldAssignedDelivery->email,
                ] : null,
                'new' => $assignedDelivery ? [
                    'id' => $assignedDelivery->id,
                    'name' => $assignedDelivery->name,
                    'email' => $assignedDelivery->email,
                ] : null,
                'assigned_by' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ],
                'assigned_at' => now('UTC')->toIso8601String(),
            ];
        }

        // Track error reason if provided
        if ($errorReason) {
            $changes['error_reason'] = $errorReason;
        }

        if ($evidenceImagePath) {
            $changes['evidence_image_path'] = $evidenceImagePath;
        }

        $description = "{$user->name} ({$user->role->value}) moved order #{$order->id} from {$oldStatus->label()} to {$newStatus->label()}";
        if ($errorReason) {
            $description .= ". Reason: {$errorReason}";
        }
        if ($assignedDelivery !== null) {
            $description .= ". Assigned delivery: {$assignedDelivery->name}";
        }
        if ($wooSynced && $wooStatus !== null) {
            $description .= ". Woo status synced to {$wooStatus}";
        } else {
            $description .= '. Woo status not modified';
        }

        return OrderLog::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'action' => 'status_changed',
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'description' => $description,
            'changes' => $changes,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Get the transition history for an order.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHistory(Order $order)
    {
        return $order->logs;
    }

    /**
     * @return list<OrderStatus>
     */
    public function allowedTransitions(Order $order, User $user): array
    {
        if ($user->isAdmin()) {
            return OrderStatus::validTransitions()[$order->status->value] ?? [];
        }

        return match ($user->role) {
            UserRole::EMPAQUETADOR => $order->status === OrderStatus::EN_PROCESO
                ? [OrderStatus::EMPAQUETADO, OrderStatus::ERROR]
                : [],
            UserRole::DESPACHADOR => $order->status === OrderStatus::EMPAQUETADO
                ? [OrderStatus::DESPACHADO, OrderStatus::ERROR]
                : [],
            UserRole::DELIVERY => $this->deliveryTransitions($order, $user),
            default => [],
        };
    }

    public function canViewOrder(Order $order, User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return match ($user->role) {
            UserRole::EMPAQUETADOR => $order->status === OrderStatus::EN_PROCESO,
            UserRole::DESPACHADOR => $order->status === OrderStatus::EMPAQUETADO,
            UserRole::DELIVERY => $order->assigned_delivery_user_id === $user->id
                && in_array($order->status, [OrderStatus::DESPACHADO, OrderStatus::EN_CAMINO], true),
            default => false,
        };
    }

    /**
     * @return list<OrderStatus>
     */
    private function deliveryTransitions(Order $order, User $user): array
    {
        if ($order->assigned_delivery_user_id !== $user->id) {
            return [];
        }

        return match ($order->status) {
            OrderStatus::DESPACHADO => [OrderStatus::EN_CAMINO, OrderStatus::ERROR],
            OrderStatus::EN_CAMINO => [OrderStatus::ENTREGADO, OrderStatus::ERROR],
            default => [],
        };
    }

    private function resolveAssignedDelivery(OrderStatus $newStatus, ?int $deliveryUserId): ?User
    {
        if ($newStatus !== OrderStatus::DESPACHADO) {
            return null;
        }

        if ($deliveryUserId === null) {
            throw new InvalidArgumentException('delivery_user_id is required when marking an order as despachado.');
        }

        /** @var User|null $delivery */
        $delivery = User::query()->find($deliveryUserId);

        if ($delivery === null || ! $delivery->hasRole(UserRole::DELIVERY)) {
            throw new InvalidArgumentException('The selected delivery_user_id must belong to a user with role delivery.');
        }

        return $delivery;
    }

    private function buildUnauthorizedTransitionMessage(Order $order, OrderStatus $newStatus, User $user): string
    {
        return sprintf(
            'El usuario %s (%s) no puede pasar el pedido #%d de %s a %s.',
            $user->name,
            $user->role->value,
            $order->id,
            $order->status->value,
            $newStatus->value,
        );
    }

    private function timelineMessage(OrderStatus $newStatus, ?User $assignedDelivery, bool $wooSynced, ?string $wooStatus): string
    {
        $message = match ($newStatus) {
            OrderStatus::DESPACHADO => $assignedDelivery !== null
                ? "Pedido despachado y asignado a {$assignedDelivery->name}"
                : 'Pedido despachado',
            OrderStatus::EN_CAMINO => 'Pedido marcado en camino',
            OrderStatus::ENTREGADO => 'Pedido entregado',
            OrderStatus::EMPAQUETADO => 'Pedido empaquetado',
            OrderStatus::ERROR => 'Pedido marcado con error',
            default => 'Estado actualizado solo en sistema interno',
        };

        if ($wooSynced && $wooStatus !== null) {
            $message .= " y sincronizado a Woo ({$wooStatus})";
        }

        return $message;
    }

    private function mapToWooStatus(OrderStatus $status): ?string
    {
        return match ($status) {
            OrderStatus::EN_PROCESO => 'processing',
            OrderStatus::ENTREGADO => 'completed',
            // Business rule: marking with X/error cancels in Woo.
            OrderStatus::ERROR => 'cancelled',
            default => null,
        };
    }

    private function syncWooOrderStatus(Order $order, string $wooStatus): void
    {
        $storeSlug = (string) ($order->store_slug ?? '');
        if ($storeSlug === '') {
            throw new InvalidArgumentException('Order has no store_slug, cannot sync status to WooCommerce.');
        }

        $externalId = (string) ($order->external_id ?? '');
        if ($externalId === '') {
            throw new InvalidArgumentException('Order has no external_id, cannot sync status to WooCommerce.');
        }

        try {
            $this->wooManager
                ->store($storeSlug)
                ->put('orders', $externalId, ['status' => $wooStatus]);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Could not update WooCommerce order status: '.$e->getMessage());
        }
    }

    /**
     * Check if an order error has exceeded the timeout (1 day).
     */
    public function hasErrorExpired(Order $order): bool
    {
        if ($order->status !== OrderStatus::ERROR || !$order->error_created_at) {
            return false;
        }

        return $order->error_created_at->addDay()->isPast();
    }

    /**
     * Automatically cancel orders with expired errors.
     */
    public function cancelExpiredErrors(User $systemUser): int
    {
        $expiredOrders = Order::where('status', OrderStatus::ERROR->value)
            ->whereNotNull('error_created_at')
            ->where('error_created_at', '<=', now()->subDay())
            ->get();

        $count = 0;
        foreach ($expiredOrders as $order) {
            try {
                $this->updateStatus(
                    $order,
                    OrderStatus::CANCELADO,
                    $systemUser,
                    'Automatically cancelled due to error timeout (1 day)',
                    null,
                    '127.0.0.1'
                );
                $count++;
            } catch (InvalidArgumentException $e) {
                // Log the error but continue processing
                \Log::warning("Could not auto-cancel order {$order->id}: {$e->getMessage()}");
            }
        }

        return $count;
    }
}
