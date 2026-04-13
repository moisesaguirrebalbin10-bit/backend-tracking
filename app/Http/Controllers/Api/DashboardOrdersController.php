<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardOrderFiltersRequest;
use App\Models\BsaleDocument;
use App\Models\Order;
use App\Models\User;
use App\Services\BsaleService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DashboardOrdersController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $statusService,
        private readonly BsaleService $bsaleService,
    ) {
    }

    public function index(DashboardOrderFiltersRequest $request): JsonResponse
    {
        $paginator = $this->dashboardQuery($request)
            ->paginate($request->perPage())
            ->appends($request->query());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (object $row): array => $this->transformListRow($row))
        );

        return response()->json($paginator);
    }

    public function metrics(DashboardOrderFiltersRequest $request): JsonResponse
    {
        $normalizedStatusSql = $this->normalizedStatusSql();

        $metrics = $this->filteredDashboardBaseQuery($request, applyStatusFilter: false)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalizedStatusSql} IN (?, ?, ?, ?, ?) THEN total ELSE 0 END), 0) as total_amount", [
                OrderStatus::EN_PROCESO->value,
                OrderStatus::EMPAQUETADO->value,
                OrderStatus::DESPACHADO->value,
                OrderStatus::EN_CAMINO->value,
                OrderStatus::ENTREGADO->value,
            ])
            ->selectRaw("SUM(CASE WHEN {$normalizedStatusSql} = ? THEN 1 ELSE 0 END) as delivered_orders", [OrderStatus::ENTREGADO->value])
            ->selectRaw("SUM(CASE WHEN {$normalizedStatusSql} IN (?, ?, ?, ?) THEN 1 ELSE 0 END) as in_process_orders", [
                OrderStatus::EN_PROCESO->value,
                OrderStatus::EMPAQUETADO->value,
                OrderStatus::DESPACHADO->value,
                OrderStatus::EN_CAMINO->value,
            ])
            ->selectRaw("SUM(CASE WHEN {$normalizedStatusSql} = ? THEN 1 ELSE 0 END) as error_orders", [OrderStatus::ERROR->value])
            ->selectRaw("SUM(CASE WHEN {$normalizedStatusSql} = ? THEN 1 ELSE 0 END) as cancelled_orders", [OrderStatus::CANCELADO->value])
            ->first();

        return response()->json([
            'filters' => [
                'source' => $request->source(),
                'scope' => $request->scopeFilter(),
                'period' => $request->period(),
                'status' => $request->statusFilter(),
                'search' => $request->searchTerm(),
            ],
            'metrics' => [
                'total_orders' => (int) ($metrics->total_orders ?? 0),
                'delivered_orders' => (int) ($metrics->delivered_orders ?? 0),
                'in_process_orders' => (int) ($metrics->in_process_orders ?? 0),
                'error_orders' => (int) ($metrics->error_orders ?? 0),
                'cancelled_orders' => (int) ($metrics->cancelled_orders ?? 0),
                'total_amount' => round((float) ($metrics->total_amount ?? 0), 2),
            ],
        ]);
    }

    public function show(Request $request, string $source, int $id): JsonResponse
    {
        return match ($source) {
            'woo' => response()->json(['order' => $this->transformWooDetail(
                $this->resolveVisibleWooOrder($request->user(), $id),
                $request->user(),
            )]),
            'bsale' => response()->json(['order' => $this->transformBsaleDetail(
                $this->resolveVisibleBsaleDocument($request->user(), $id)
            )]),
            default => throw new NotFoundHttpException('Fuente de pedido no soportada.'),
        };
    }

    private function dashboardQuery(DashboardOrderFiltersRequest $request, bool $applyStatusFilter = true): Builder
    {
        $normalizedStatusSql = $this->normalizedStatusSql();

        return $this->filteredDashboardBaseQuery($request, $applyStatusFilter)
            ->select('dashboard_orders.*')
            ->selectRaw("{$normalizedStatusSql} as normalized_status")
            ->orderByDesc('ingested_at')
            ->orderByDesc('ordered_at')
            ->orderByDesc('source_record_id');
    }

    private function filteredDashboardBaseQuery(DashboardOrderFiltersRequest $request, bool $applyStatusFilter = true): Builder
    {
        [$from, $to] = $request->dateRange();
        $normalizedStatusSql = $this->normalizedStatusSql();
        $baseQuery = DB::query()->fromSub($this->baseDataset($request), 'dashboard_orders')
            ->whereBetween('ordered_at', [$from, $to]);

        $search = $request->searchTerm();
        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';

            $baseQuery->where(function (Builder $query) use ($like): void {
                $query->whereRaw("LOWER(COALESCE(order_number, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(bsale_receipt, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(customer_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(vendor_name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(location, '')) LIKE ?", [$like])
                    ->orWhereRaw('CAST(ordered_at AS TEXT) LIKE ?', ['%' . $search . '%']);
            });
        }

        if ($applyStatusFilter && $request->statusFilter() !== null) {
            $baseQuery->whereRaw("{$normalizedStatusSql} = ?", [$request->statusFilter()]);
        }

        $this->applyOperationalScope($baseQuery, $request, $normalizedStatusSql);

        return $baseQuery;
    }

    private function baseDataset(DashboardOrderFiltersRequest $request): Builder
    {
        $source = $request->source();
        $woo = $this->wooDataset();
        $bsale = $this->bsaleDataset();

        return match ($source) {
            'woo' => $woo,
            'bsale' => $bsale,
            default => $woo->unionAll($bsale),
        };
    }

    private function wooDataset(): Builder
    {
        $deliveryDateSql = $this->wooMetaDataValueSql(['_billing_fecha_entrega_1', 'billing_fecha_entrega_1']);
        $locationSql = $this->wooMetaDataValueSql(['_billing_direccion_mapa', 'billing_direccion_mapa', '_billing_distrito', 'billing_distrito']);
        $receiptSql = <<<SQL
CASE
    WHEN NULLIF(serie, '') IS NULL THEN NULLIF(numero, '')
    WHEN NULLIF(numero, '') IS NULL THEN serie
    WHEN serie LIKE '%' || numero THEN serie
    ELSE CONCAT_WS('-', serie, numero)
END
SQL;

        return DB::table('orders')
            ->leftJoin('users as assigned_delivery_users', 'assigned_delivery_users.id', '=', 'orders.assigned_delivery_user_id')
            ->whereNull('orders.deleted_at')
            ->selectRaw("'woo' as source")
            ->selectRaw('orders.id as source_record_id')
            ->selectRaw('orders.created_at as ordered_at')
            ->selectRaw('orders.external_id as order_number')
            ->selectRaw("{$receiptSql} as bsale_receipt")
            ->selectRaw('orders.customer_name')
            ->selectRaw("{$deliveryDateSql} as delivery_date")
            ->selectRaw("COALESCE(orders.meta->>'date_completed_gmt', orders.meta->>'date_completed') as delivered_at")
            ->selectRaw("{$locationSql} as location")
            ->selectRaw('orders.total::numeric as total')
            ->selectRaw('orders.created_at as ingested_at')
            ->selectRaw('orders.store_slug as vendor_name')
            ->selectRaw('orders.store_slug')
                ->selectRaw('orders.status as raw_status')
                ->selectRaw('orders.assigned_delivery_user_id')
                ->selectRaw('assigned_delivery_users.name as assigned_delivery_name');
    }

    private function bsaleDataset(): Builder
    {
        $deliveryDateSql = $this->bsaleAttributeValueSql('FECHA DE DESPACHO');
        $locationSql = $this->bsaleAttributeValueSql('MARCA/RED SOCIAL');
        $statusSql = $this->bsaleAttributeValueSql('ESTADO DE PEDIDO');
        $sellerNameSql = $this->bsaleSellerNameSql();

        return DB::table('bsale_documents')
            ->selectRaw("'bsale' as source")
            ->selectRaw('id as source_record_id')
            ->selectRaw('generation_date as ordered_at')
            ->selectRaw('serial_number as order_number')
            ->selectRaw('serial_number as bsale_receipt')
            ->selectRaw('client_name as customer_name')
            ->selectRaw("{$deliveryDateSql} as delivery_date")
            ->selectRaw('NULL::text as delivered_at')
            ->selectRaw("{$locationSql} as location")
            ->selectRaw('total_amount::numeric as total')
            ->selectRaw('created_at as ingested_at')
            ->selectRaw("COALESCE({$sellerNameSql}, user_name) as vendor_name")
            ->selectRaw('office_name as store_slug')
            ->selectRaw("COALESCE({$statusSql}, 'PEDIDO') as raw_status")
            ->selectRaw('NULL::bigint as assigned_delivery_user_id')
            ->selectRaw('NULL::text as assigned_delivery_name');
    }

    private function normalizedStatusSql(): string
    {
        return <<<SQL
CASE
    WHEN source = 'woo' AND raw_status IN ('en_proceso', 'empaquetado', 'despachado', 'en_camino', 'entregado', 'error_en_pedido', 'cancelado') THEN raw_status
    WHEN source = 'woo' AND LOWER(COALESCE(raw_status, '')) IN ('processing', 'pending', 'on-hold') THEN 'en_proceso'
    WHEN source = 'woo' AND LOWER(COALESCE(raw_status, '')) = 'completed' THEN 'entregado'
    WHEN source = 'woo' AND LOWER(COALESCE(raw_status, '')) IN ('cancelled', 'cancel') THEN 'cancelado'
    WHEN source = 'woo' AND LOWER(COALESCE(raw_status, '')) IN ('failed', 'refunded', 'error') THEN 'error_en_pedido'
    WHEN source = 'bsale' AND LOWER(COALESCE(raw_status, '')) LIKE '%entreg%' THEN 'entregado'
    WHEN source = 'bsale' AND LOWER(COALESCE(raw_status, '')) LIKE '%camino%' THEN 'en_camino'
    WHEN source = 'bsale' AND LOWER(COALESCE(raw_status, '')) LIKE '%despach%' THEN 'despachado'
    WHEN source = 'bsale' AND LOWER(COALESCE(raw_status, '')) LIKE '%empaquet%' THEN 'empaquetado'
    WHEN source = 'bsale' AND LOWER(COALESCE(raw_status, '')) LIKE '%cancel%' THEN 'cancelado'
    WHEN source = 'bsale' AND (LOWER(COALESCE(raw_status, '')) LIKE '%error%' OR LOWER(COALESCE(raw_status, '')) LIKE '%fall%') THEN 'error_en_pedido'
    ELSE 'en_proceso'
END
SQL;
    }

    private function transformListRow(object $row): array
    {
        $normalizedStatus = (string) ($row->normalized_status ?? OrderStatus::EN_PROCESO->value);

        return [
            'source' => (string) $row->source,
            'source_record_id' => (int) $row->source_record_id,
            'readonly' => $row->source === 'bsale',
            'order_number' => (string) ($row->order_number ?? ''),
            'bsale_receipt' => $row->bsale_receipt,
            'customer_name' => $row->customer_name,
            'ordered_at' => $row->ordered_at,
            'delivery_date' => $row->delivery_date,
            'delivered_at' => $row->delivered_at,
            'location' => $row->location,
            'total' => round((float) $row->total, 2),
            'status' => [
                'value' => $normalizedStatus,
                'label' => OrderStatus::labels()[$normalizedStatus] ?? ucfirst(str_replace('_', ' ', $normalizedStatus)),
                'raw' => $row->raw_status,
            ],
            'assigned_delivery_user_id' => $row->assigned_delivery_user_id !== null ? (int) $row->assigned_delivery_user_id : null,
            'assigned_delivery_name' => $row->assigned_delivery_name,
            'vendor_name' => $row->vendor_name,
            'store_slug' => $row->store_slug,
            'detail_endpoint' => sprintf('/api/v1/dashboard/orders/%s/%d', $row->source, $row->source_record_id),
        ];
    }

    private function transformBsaleDetail(BsaleDocument $document): array
    {
        $payload = is_array($document->payload) ? $document->payload : [];
        $products = collect(data_get($payload, 'details.items', []))
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $total = (float) ($item['totalAmount'] ?? 0);
                $discount = (float) ($item['totalDiscount'] ?? 0);
                $quantity = (float) ($item['quantity'] ?? 0);
                $unitPrice = $quantity > 0 ? ($total + $discount) / $quantity : ($total + $discount);

                return [
                    'name' => $this->bsaleService->resolveVariantDisplayName($item),
                    'sku' => data_get($item, 'variant.code'),
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'discount' => round($discount, 2),
                    'total' => round($total, 2),
                ];
            })
            ->values();

        $payments = collect(data_get($payload, 'payments', []))
            ->filter(fn ($payment): bool => is_array($payment))
            ->map(fn (array $payment): array => [
                'method' => (string) ($payment['name'] ?? 'Pago'),
                'amount' => round((float) ($payment['amount'] ?? 0), 2),
            ])
            ->values();

        $sellerName = $this->firstBsaleSellerName($payload) ?? $document->user_name;
        $rawStatus = $this->firstBsaleAttributeValue($payload, ['ESTADO DE PEDIDO']) ?? 'PEDIDO';

        return [
            'source' => 'bsale',
            'readonly' => true,
            'id' => $document->id,
            'external_id' => $document->external_id,
            'order_number' => $document->serial_number,
            'bsale_receipt' => $document->serial_number,
            'status' => [
                'value' => $this->normalizeBsaleStatus($rawStatus),
                'label' => $rawStatus,
                'raw' => $rawStatus,
            ],
            'assigned_delivery_user_id' => null,
            'assigned_delivery_name' => null,
            'assigned_delivery' => null,
            'dispatch_date' => $this->firstBsaleAttributeValue($payload, ['FECHA DE DESPACHO']),
            'location' => $this->firstBsaleAttributeValue($payload, ['MARCA/RED SOCIAL']),
            'customer' => [
                'name' => $document->client_name,
                'document' => $document->client_code,
                'email' => $document->client_email,
                'phone' => $document->client_phone,
            ],
            'seller' => [
                'name' => $sellerName,
                'issue_date' => optional($document->emission_date)->toDateString(),
                'receipt' => $document->serial_number,
            ],
            'payment' => [
                'methods' => $payments,
                'total' => round((float) $document->total_amount, 2),
            ],
            'products' => $products,
            'allowed_transitions' => [],
            'meta' => [
                'document_type_name' => $document->document_type_name,
                'office_name' => $document->office_name,
                'generation_date' => optional($document->generation_date)->toIso8601String(),
                'emission_date' => optional($document->emission_date)->toIso8601String(),
            ],
        ];
    }

    private function transformWooDetail(Order $order, ?User $actor = null): array
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $order->loadMissing('assignedDelivery');
        $products = collect($meta['line_items'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $qty = (float) ($item['quantity'] ?? 0);
                $total = (float) ($item['total'] ?? 0);
                $price = isset($item['price']) ? (float) $item['price'] : ($qty > 0 ? $total / $qty : 0);

                return [
                    'name' => (string) ($item['name'] ?? ''),
                    'sku' => (string) ($item['sku'] ?? ''),
                    'quantity' => $qty,
                    'unit_price' => round($price, 2),
                    'discount' => round((float) (($item['subtotal'] ?? $total) - $total), 2),
                    'total' => round($total, 2),
                ];
            })
            ->values();

        return [
            'source' => 'woo',
            'readonly' => false,
            'id' => $order->id,
            'external_id' => $order->external_id,
            'order_number' => $order->external_id,
            'bsale_receipt' => $this->wooReceipt($order),
            'status' => [
                'value' => $order->status->value,
                'label' => $order->status_label,
                'raw' => $order->woo_status,
                'woo_label' => $order->woo_status_label,
            ],
            'assigned_delivery_user_id' => $order->assigned_delivery_user_id,
            'assigned_delivery_name' => $order->assignedDelivery?->name,
            'assigned_delivery' => $order->assignedDelivery ? [
                'id' => $order->assignedDelivery->id,
                'name' => $order->assignedDelivery->name,
                'email' => $order->assignedDelivery->email,
            ] : null,
            'dispatch_date' => $this->wooMetaDataValue($meta, ['_billing_fecha_entrega_1', 'billing_fecha_entrega_1']),
            'location' => $this->wooMetaDataValue($meta, ['_billing_direccion_mapa', 'billing_direccion_mapa', '_billing_distrito', 'billing_distrito']),
            'customer' => [
                'name' => $order->customer_name,
                'document' => $this->wooMetaDataValue($meta, ['dni_ce', '_billing_documento', 'billing_documento', 'dni']),
                'email' => data_get($meta, 'billing.email'),
                'phone' => data_get($meta, 'billing.phone'),
            ],
            'seller' => [
                'name' => $order->store_slug,
                'issue_date' => optional($order->created_at)->toDateString(),
                'receipt' => $this->wooReceipt($order),
            ],
            'payment' => [
                'methods' => [[
                    'method' => (string) (data_get($meta, 'payment_method_title') ?? data_get($meta, 'payment_method') ?? 'Pago'),
                    'amount' => round((float) $order->total, 2),
                ]],
                'total' => round((float) $order->total, 2),
            ],
            'products' => $products,
            'allowed_transitions' => $actor !== null
                ? collect($this->statusService->allowedTransitions($order, $actor))
                    ->map(fn (OrderStatus $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                        'requires_delivery_user_id' => $status === OrderStatus::DESPACHADO,
                    ])
                    ->values()
                    ->all()
                : [],
            'meta' => [
                'store_slug' => $order->store_slug,
                'woo_status' => $order->woo_status,
                'ordered_at' => optional($order->created_at)->toIso8601String(),
                'delivered_at' => data_get($meta, 'date_completed_gmt') ?? data_get($meta, 'date_completed'),
            ],
        ];
    }

    private function bsaleAttributeValueSql(string $name): string
    {
        $name = strtoupper($name);

        return "(SELECT attr->>'value' FROM json_array_elements(COALESCE(payload->'attributes'->'items', '[]'::json)) AS attr WHERE UPPER(TRIM(COALESCE(attr->>'name', ''))) = '{$name}' LIMIT 1)";
    }

    private function bsaleSellerNameSql(): string
    {
        return "(SELECT TRIM(CONCAT_WS(' ', seller->>'firstName', seller->>'lastName')) FROM json_array_elements(COALESCE(payload->'sellers'->'items', '[]'::json)) AS seller LIMIT 1)";
    }

    /**
     * @param list<string> $keys
     */
    private function wooMetaDataValueSql(array $keys): string
    {
        $quotedKeys = implode(', ', array_map(fn (string $key): string => "'{$key}'", $keys));

        return "(SELECT md->>'value' FROM json_array_elements(COALESCE(meta->'meta_data', '[]'::json)) AS md WHERE md->>'key' IN ({$quotedKeys}) LIMIT 1)";
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $names
     */
    private function firstBsaleAttributeValue(array $payload, array $names): ?string
    {
        foreach ((array) data_get($payload, 'attributes.items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = strtoupper(trim((string) ($item['name'] ?? '')));
            if (! in_array($name, array_map('strtoupper', $names), true)) {
                continue;
            }

            $value = trim((string) ($item['value'] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function firstBsaleSellerName(array $payload): ?string
    {
        foreach ((array) data_get($payload, 'sellers.items', []) as $seller) {
            if (! is_array($seller)) {
                continue;
            }

            $value = trim(((string) ($seller['firstName'] ?? '')) . ' ' . ((string) ($seller['lastName'] ?? '')));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeBsaleStatus(string $rawStatus): string
    {
        $value = mb_strtolower($rawStatus);

        return match (true) {
            str_contains($value, 'entreg') => OrderStatus::ENTREGADO->value,
            str_contains($value, 'camino') => OrderStatus::EN_CAMINO->value,
            str_contains($value, 'despach') => OrderStatus::DESPACHADO->value,
            str_contains($value, 'empaquet') => OrderStatus::EMPAQUETADO->value,
            str_contains($value, 'cancel') => OrderStatus::CANCELADO->value,
            str_contains($value, 'error'), str_contains($value, 'fall') => OrderStatus::ERROR->value,
            default => OrderStatus::EN_PROCESO->value,
        };
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $keys
     */
    private function wooMetaDataValue(array $meta, array $keys): ?string
    {
        foreach ((array) ($meta['meta_data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! in_array((string) ($row['key'] ?? ''), $keys, true)) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function wooReceipt(Order $order): ?string
    {
        $serie = filled($order->serie) ? trim((string) $order->serie) : null;
        $numero = filled($order->numero) ? trim((string) $order->numero) : null;

        if ($serie === null) {
            return $numero;
        }

        if ($numero === null) {
            return $serie;
        }

        if (str_ends_with($serie, $numero)) {
            return $serie;
        }

        return $serie . '-' . $numero;
    }

    private function applyOperationalScope(Builder $query, DashboardOrderFiltersRequest $request, string $normalizedStatusSql): void
    {
        $actor = $request->user();

        if ($actor === null || $actor->isAdmin() || $request->scopeFilter() !== 'my_queue') {
            return;
        }

        $query->where('source', 'woo');

        match ($actor->role) {
            UserRole::EMPAQUETADOR => $query->whereRaw("{$normalizedStatusSql} = ?", [OrderStatus::EN_PROCESO->value]),
            UserRole::DESPACHADOR => $query->whereRaw("{$normalizedStatusSql} = ?", [OrderStatus::EMPAQUETADO->value]),
            UserRole::DELIVERY => $query
                ->where('assigned_delivery_user_id', $actor->id)
                ->whereRaw("{$normalizedStatusSql} IN (?, ?)", [OrderStatus::DESPACHADO->value, OrderStatus::EN_CAMINO->value]),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function resolveVisibleWooOrder(?User $actor, int $id): Order
    {
        $order = Order::query()->with('assignedDelivery')->findOrFail($id);

        if ($actor !== null && ! $this->statusService->canViewOrder($order, $actor)) {
            throw new AccessDeniedHttpException('No autorizado para ver este pedido.');
        }

        return $order;
    }

    private function resolveVisibleBsaleDocument(?User $actor, int $id): BsaleDocument
    {
        if ($actor !== null && ! $actor->isAdmin() && ! in_array($actor->role, [
            UserRole::EMPAQUETADOR,
            UserRole::DESPACHADOR,
        ], true)) {
            throw new AccessDeniedHttpException('No autorizado para ver pedidos Bsale en esta vista.');
        }

        return BsaleDocument::query()->findOrFail($id);
    }
}