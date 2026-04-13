<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Notifications;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class OrderActivityNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || in_array($user->role, [UserRole::DESPACHADOR, UserRole::DELIVERY], true)),
            403,
            'No autorizado para ver estas notificaciones.'
        );

        $perPage = max(10, min((int) $request->query('per_page', 20), 100));

        $logs = OrderLog::query()
            ->with([
                'order:id,external_id,store_slug,total,numero,serie,created_at,assigned_delivery_user_id,meta',
                'user:id,name,role',
            ])
            ->where('action', 'status_changed')
            ->tap(fn (Builder $query) => $this->applyAudienceFilter($query, $user))
            ->latest()
            ->paginate($perPage)
            ->through(function (OrderLog $log) use ($user): array {
                $changes = is_array($log->changes) ? $log->changes : [];
                $order = $log->order;
                $actor = $log->user;

                $newStatus = (string) ($log->new_status ?? '');
                $newStatusLabel = OrderStatus::tryFrom($newStatus)?->label() ?? ucfirst(str_replace('_', ' ', $newStatus));
                $oldStatus = (string) ($log->old_status ?? '');
                $oldStatusLabel = OrderStatus::tryFrom($oldStatus)?->label() ?? ucfirst(str_replace('_', ' ', $oldStatus));
                $errorReason = is_string($changes['error_reason'] ?? null) ? $changes['error_reason'] : null;
                $assignedDeliveryName = data_get($changes, 'delivery_assignment.new.name');
                $assignedDeliveryId = data_get($changes, 'delivery_assignment.new.id');
                $orderReference = $this->resolveOrderReference($order?->serie, $order?->numero, $order?->external_id);
                $source = filled($order?->store_slug) ? 'woo' : 'bsale';
                $deliveryDateLabel = $this->resolveDeliveryDateLabel($order);

                return [
                    'id' => $log->id,
                    'type' => 'status_change',
                    'source' => $source,
                    'order_id' => $order?->id,
                    'order_reference' => $orderReference,
                    'order_number' => $order?->external_id,
                    'actor_name' => $actor?->name,
                    'actor_role' => $actor?->role?->value,
                    'actor_role_label' => $actor?->role?->label(),
                    'old_status' => $oldStatus,
                    'old_status_label' => $oldStatusLabel,
                    'new_status' => $newStatus,
                    'new_status_label' => $newStatusLabel,
                    'error_reason' => $errorReason,
                    'assigned_delivery_name' => is_string($assignedDeliveryName) ? $assignedDeliveryName : null,
                    'title' => $this->buildTitle($user->role, $newStatus, is_numeric($assignedDeliveryId) ? (int) $assignedDeliveryId : null),
                    'total' => $order?->total,
                    'message' => $this->buildMessage(
                        audienceRole: $user->role,
                        actorName: (string) ($actor?->name ?? 'Usuario'),
                        actorRoleLabel: (string) ($actor?->role?->label() ?? 'Usuario'),
                        orderReference: $orderReference,
                        newStatus: $newStatus,
                        newStatusLabel: $newStatusLabel,
                        errorReason: $errorReason,
                        assignedDeliveryName: is_string($assignedDeliveryName) ? $assignedDeliveryName : null,
                        assignedDeliveryId: is_numeric($assignedDeliveryId) ? (int) $assignedDeliveryId : null,
                        deliveryDateLabel: $deliveryDateLabel,
                        createdAtLabel: optional($log->created_at)->format('d/m/Y H:i'),
                    ),
                    'created_at' => optional($log->created_at)->toIso8601String(),
                ];
            });

        return response()->json($logs);
    }

    private function applyAudienceFilter(Builder $query, $user): void
    {
        if ($user->isAdmin()) {
            $query->where('user_id', '!=', $user->id);

            return;
        }

        if ($user->role === UserRole::DESPACHADOR) {
            $query->where(function (Builder $roleScopedQuery): void {
                $roleScopedQuery
                    ->whereHas('user', function (Builder $actorQuery): void {
                        $actorQuery->whereIn('role', [UserRole::EMPAQUETADOR->value, UserRole::DELIVERY->value]);
                    })
                    ->orWhere(function (Builder $assignmentQuery): void {
                        $assignmentQuery
                            ->where('new_status', OrderStatus::DESPACHADO->value)
                            ->whereRaw("NULLIF(changes->'delivery_assignment'->'new'->>'id', '') IS NOT NULL");
                    });
            });

            return;
        }

        if ($user->role === UserRole::DELIVERY) {
            $query
                ->where('new_status', OrderStatus::DESPACHADO->value)
                ->whereRaw("(changes->'delivery_assignment'->'new'->>'id')::bigint = ?", [$user->id]);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function resolveOrderReference(?string $serie, ?string $numero, ?string $externalId): string
    {
        $serie = trim((string) $serie);
        $numero = trim((string) $numero);
        $externalId = trim((string) $externalId);

        if ($serie !== '' && $numero !== '') {
            return $serie . '-' . $numero;
        }

        if ($externalId !== '') {
            return $externalId;
        }

        return 'Pedido';
    }

    private function buildTitle(UserRole $audienceRole, string $newStatus, ?int $assignedDeliveryId): string
    {
        if ($audienceRole === UserRole::DELIVERY && $newStatus === OrderStatus::DESPACHADO->value && $assignedDeliveryId !== null) {
            return 'Pedido Asignado para Entrega';
        }

        if ($audienceRole === UserRole::DESPACHADOR && $newStatus === OrderStatus::DESPACHADO->value && $assignedDeliveryId !== null) {
            return 'Pedido Asignado a Delivery';
        }

        if ($audienceRole === UserRole::DESPACHADOR && in_array($newStatus, [OrderStatus::EN_CAMINO->value, OrderStatus::ENTREGADO->value, OrderStatus::ERROR->value], true)) {
            return 'Actualizacion de Delivery';
        }

        return 'Actualizacion Operativa de Pedido';
    }

    private function buildMessage(
        UserRole $audienceRole,
        string $actorName,
        string $actorRoleLabel,
        string $orderReference,
        string $newStatus,
        string $newStatusLabel,
        ?string $errorReason,
        ?string $assignedDeliveryName,
        ?int $assignedDeliveryId,
        ?string $deliveryDateLabel,
        ?string $createdAtLabel,
    ): string {
        if ($audienceRole === UserRole::DELIVERY && $newStatus === OrderStatus::DESPACHADO->value && $assignedDeliveryId !== null) {
            $dateMessage = $deliveryDateLabel !== null ? ' con fecha ' . $deliveryDateLabel : '';
            $timeMessage = $createdAtLabel !== null ? ' a las ' . substr($createdAtLabel, 11, 5) : '';

            return sprintf(
                'Se te ha asignado el pedido %s para su entrega%s%s. Porfavor de completarlo y marcar los estados en orden.',
                $orderReference,
                $dateMessage,
                $timeMessage
            );
        }

        if ($audienceRole === UserRole::DESPACHADOR && $newStatus === OrderStatus::DESPACHADO->value && $assignedDeliveryName) {
            $deliveryCount = $this->countActiveAssignmentsForDelivery($assignedDeliveryId);
            $dateSuffix = $deliveryDateLabel !== null ? ' para el dia ' . $deliveryDateLabel : '';
            $countSuffix = $deliveryCount !== null ? ' Actualmente tiene ' . $deliveryCount . ' pedidos asignados.' : '';

            return sprintf(
                'El pedido %s fue asignado a %s%s.%s',
                $orderReference,
                $assignedDeliveryName,
                $dateSuffix,
                $countSuffix
            );
        }

        if ($audienceRole === UserRole::DESPACHADOR && $actorRoleLabel === UserRole::DELIVERY->label()) {
            return $this->buildDispatcherDeliveryMessage(
                actorName: $actorName,
                orderReference: $orderReference,
                newStatus: $newStatus,
                newStatusLabel: $newStatusLabel,
                errorReason: $errorReason,
                deliveryDateLabel: $deliveryDateLabel,
            );
        }

        if ($newStatus === OrderStatus::ERROR->value) {
            $message = sprintf('%s %s marco el pedido %s con un error en el proceso', $actorName, $actorRoleLabel, $orderReference);
            if ($errorReason) {
                $message .= ' (' . $errorReason . ')';
            }
            return $message;
        }

        if ($newStatus === OrderStatus::DESPACHADO->value && $assignedDeliveryName) {
            return sprintf(
                '%s %s marco el pedido %s como Pedido exitoso despachado derivado a %s',
                $actorName,
                $actorRoleLabel,
                $orderReference,
                $assignedDeliveryName
            );
        }

        return sprintf('%s %s marco el pedido %s como %s', $actorName, $actorRoleLabel, $orderReference, $newStatusLabel);
    }

    private function buildDispatcherDeliveryMessage(
        string $actorName,
        string $orderReference,
        string $newStatus,
        string $newStatusLabel,
        ?string $errorReason,
        ?string $deliveryDateLabel,
    ): string {
        $dateSuffix = $deliveryDateLabel !== null ? ' para el dia ' . $deliveryDateLabel : '';

        if ($newStatus === OrderStatus::EN_CAMINO->value) {
            return sprintf('%s Delivery marco el pedido %s en camino%s.', $actorName, $orderReference, $dateSuffix);
        }

        if ($newStatus === OrderStatus::ENTREGADO->value) {
            return sprintf('%s Delivery entrego el pedido %s%s.', $actorName, $orderReference, $dateSuffix);
        }

        if ($newStatus === OrderStatus::ERROR->value) {
            $message = sprintf('%s Delivery reporto un error con el pedido %s%s', $actorName, $orderReference, $dateSuffix);

            if ($errorReason) {
                $message .= ' (' . $errorReason . ')';
            }

            return $message . '.';
        }

        return sprintf('%s Delivery actualizo el pedido %s como %s%s.', $actorName, $orderReference, $newStatusLabel, $dateSuffix);
    }

    private function countActiveAssignmentsForDelivery(?int $deliveryUserId): ?int
    {
        if ($deliveryUserId === null) {
            return null;
        }

        return Order::query()
            ->where('assigned_delivery_user_id', $deliveryUserId)
            ->whereIn('status', [OrderStatus::DESPACHADO, OrderStatus::EN_CAMINO])
            ->count();
    }

    private function resolveDeliveryDateLabel(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        foreach ((array) ($meta['meta_data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = (string) ($row['key'] ?? '');
            if (! in_array($key, ['_billing_fecha_entrega_1', 'billing_fecha_entrega_1'], true)) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return optional($order->created_at)->format('d/m/Y');
    }
}