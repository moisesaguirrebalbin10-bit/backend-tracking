<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\SyncOrdersRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderStatusService $statusService,
    ) {
    }

    /**
     * Get all orders with pagination.
     */
    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->with(['user', 'logs' => fn ($q) => $q->latest()->limit(3)])
            ->latest('id')
            ->paginate(50);

        return response()->json($orders);
    }

    /**
     * Get a single order with full history.
     */
    public function show(Order $order): JsonResponse
    {
        $order->load(['user', 'timelines', 'logs' => fn ($q) => $q->latest()]);

        return response()->json($order);
    }

    /**
     * Get audit logs for an order.
     */
    public function logs(Order $order): JsonResponse
    {
        $logs = $order->logs()->paginate(50);

        return response()->json($logs);
    }

    /**
     * Sync orders from WooCommerce.
     */
    public function sync(SyncOrdersRequest $request): JsonResponse
    {
        $this->orderService->syncFromWooCommerce($request);

        return response()->json(['message' => 'Sync started'], 202);
    }

    /**
     * Update order status with validation and audit logging.
     */
    public function updateStatus(
        Order $order,
        UpdateOrderStatusRequest $request
    ): JsonResponse {
        try {
            $user = auth()->user();

            // Handle image upload if provided
            $imagePath = null;
            if ($request->hasDeliveryImage()) {
                $imagePath = $request->file('delivery_image')
                    ->store('orders/deliveries', 'public');
            }

            // Update status with full validation
            $updatedOrder = $this->statusService->updateStatus(
                order: $order,
                newStatus: $request->getStatus(),
                user: $user,
                errorReason: $request->getErrorReason(),
                deliveryImagePath: $imagePath,
                ipAddress: $request->ip()
            );

            return response()->json([
                'message' => 'Order status updated successfully',
                'order' => $updatedOrder->load('logs'),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'INVALID_STATUS_TRANSITION',
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('Order status update error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while updating order status',
            ], 500);
        }
    }

    /**
     * Get order status transition history.
     */
    public function history(Order $order): JsonResponse
    {
        $history = $this->statusService->getHistory($order);

        return response()->json($history);
    }

    /**
     * Get available status transitions for current user.
     */
    public function availableTransitions(Order $order): JsonResponse
    {
        $user = auth()->user();
        $currentStatus = $order->status;

        // Get valid transitions from the current status
        $validTransitions = OrderStatus::validTransitions()[$currentStatus->value] ?? [];

        // Filter by user permissions
        $availableTransitions = collect($validTransitions)
            ->filter(fn ($status) => $user->canUpdateOrderStatus($status))
            ->map(fn ($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])
            ->values();

        return response()->json([
            'current_status' => [
                'value' => $currentStatus->value,
                'label' => $currentStatus->label(),
            ],
            'available_transitions' => $availableTransitions,
        ]);
    }
}
