<?php

namespace App\Services;

use App\Services\Integrations\BsaleClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class BsaleService
{
    protected $client;
    // La caché estática es vital para que no se cuelgue por exceso de llamadas
    protected static $variantCache = [];

    public function __construct(BsaleClient $client)
    {
        $this->client = $client;
    }

    public function getOrders(int $offset = 0, int $limit = 50, array $filters = []): array
    {
        $offset = max(0, $offset);
        $limit = min(50, max(1, $limit));
        $includeVariantDetails = (bool) ($filters['include_variant_details'] ?? false);

        $baseParams = $this->buildDocumentFilters($filters);

        $firstResponse = $this->client->get('documents', array_merge($baseParams, ['limit' => 1]));
        $total = $firstResponse->json()['count'] ?? 0;

        if ($offset >= $total) {
            return [
                'total_registros' => $total,
                'items' => [],
                'meta' => [
                    'offset' => $offset,
                    'limit' => $limit,
                    'applied_offset' => null,
                    'applied_limit' => 0,
                    'filters' => $filters['meta'] ?? null,
                ],
            ];
        }

        $remaining = $total - $offset;
        $pageSize = min($limit, $remaining);
        $realOffset = max(0, $total - $offset - $pageSize);

        $response = $this->client->get('documents', array_merge($baseParams, [
            'limit'   => $pageSize,
            'offset'  => $realOffset,
            'expand'  => '[client,sellers,attributes,payments,details]'
        ]));

        $data = $response->json();
        
        $items = collect($data['items'] ?? [])
            ->reverse() 
            ->reject(fn ($order): bool => is_array($order) && $this->shouldExcludeDocument($order))
            ->map(function ($order) use ($includeVariantDetails) {
                return $this->formatOrder($order, $includeVariantDetails);
            })
            ->values();

        return [
            'total_registros' => $total,
            'items' => $items,
            'meta' => [
                'offset' => $offset,
                'limit' => $limit,
                'applied_offset' => $realOffset,
                'applied_limit' => $pageSize,
                'filters' => $filters['meta'] ?? null,
            ],
        ];
    }

    public function getDocumentsPage(int $offset = 0, int $limit = 50, array $filters = []): array
    {
        $offset = max(0, $offset);
        $limit = min($this->configInt('bsale.batch_size', 50), max(1, $limit));

        $response = $this->client->get('documents', array_merge(
            $this->buildDocumentFilters($filters),
            [
                'limit' => $limit,
                'offset' => $offset,
                'expand' => '[client,sellers,attributes,payments,details,office,user,coin,document_type]',
            ]
        ));

        $data = $response->json();

        return [
            'total' => (int) ($data['count'] ?? 0),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    public function buildGenerationDateRange(Carbon $from, Carbon $to): string
    {
        return sprintf('[%d,%d]', $from->copy()->utc()->timestamp, $to->copy()->utc()->timestamp);
    }

    public function resolveVariantDisplayName(array $item, bool $includeVariantDetails = true): string
    {
        $variantId = $item['variant']['id'] ?? null;
        $infoProducto = $this->resolveVariantDetail($item, $variantId, $includeVariantDetails);

        return (string) ($infoProducto['nombre'] ?? 'No encontrado');
    }

    public function shouldExcludeDocument(array $document): bool
    {
        if ($this->documentTypeLooksLikeWooReplica($document)) {
            return true;
        }

        return $this->sellerLooksLikeWooReplica($document);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildDocumentFilters(array $filters): array
    {
        $baseParams = [
            'state' => $this->configInt('bsale.state', 0),
        ];

        foreach (['emissiondaterange', 'generationdaterange'] as $rangeKey) {
            if (! empty($filters[$rangeKey])) {
                $baseParams[$rangeKey] = $filters[$rangeKey];
            }
        }

        return $baseParams;
    }

    private function configInt(string $key, int $default): int
    {
        try {
            return (int) config($key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    private function documentTypeLooksLikeWooReplica(array $document): bool
    {
        $documentTypeName = $this->normalizeText((string) data_get($document, 'document_type.name', ''));

        return $documentTypeName !== '' && str_contains($documentTypeName, 'WEB');
    }

    private function sellerLooksLikeWooReplica(array $document): bool
    {
        $sellers = data_get($document, 'sellers.items', []);

        if (! is_array($sellers)) {
            return false;
        }

        $storeTokens = $this->wooStoreTokens();

        foreach ($sellers as $seller) {
            if (! is_array($seller)) {
                continue;
            }

            $fullName = $this->normalizeText(trim(
                ((string) ($seller['firstName'] ?? '')) . ' ' . ((string) ($seller['lastName'] ?? ''))
            ));

            if ($fullName === '' || ! str_contains($fullName, 'WEB')) {
                continue;
            }

            if ($storeTokens->contains(fn (string $token): bool => str_contains($fullName, $token))) {
                return true;
            }

            return true;
        }

        return false;
    }

    /**
     * @return Collection<int, string>
     */
    private function wooStoreTokens(): Collection
    {
        $defaults = [
            '3X100',
            'EZZETA',
            'MAXETA',
            'CREPANTE',
            'UOMO',
            'UOMOCATTIVO',
            'UOMO CATTIVO',
        ];

        $configuredStores = [];

        try {
            $stores = config('woocommerce.stores', []);

            foreach ($stores as $slug => $store) {
                $configuredStores[] = (string) $slug;
                $configuredStores[] = (string) Arr::get($store, 'label', '');
            }
        } catch (Throwable) {
            // Tests unitarios pueden ejecutarse sin contenedor/config cargada.
        }

        return collect(array_merge($defaults, $configuredStores))
            ->map(fn (string $value): string => $this->normalizeText($value))
            ->filter(fn (string $value): bool => $value !== '' && $value !== 'WEB')
            ->unique()
            ->values();
    }

    private function normalizeText(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtoupper($value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function formatOrder($order, bool $includeVariantDetails = false) 
    {
        $timestamp = $order['generationDate'] ?? ($order['emissionDate'] ?? time());

        $getAttr = function($name) use ($order) {
            $attributes = $order['attributes']['items'] ?? [];
            $attr = collect($attributes)->first(fn($a) => trim(strtoupper($a['name'] ?? '')) === strtoupper($name));
            if (!$attr) return "No encontrado";
            $value = $attr['value'] ?? "";
            return (is_numeric($value) && isset($attr['details'][0]['name'])) ? $attr['details'][0]['name'] : ($value ?: "No encontrado");
        };

        $pagos = collect($order['payments'] ?? []);
        $totalCaja = $pagos->sum('amount');
        
        $metodosDetallados = $pagos->map(function($p) {
            $nombreMetodo = $p['name'] ?? 'Pago';
            $monto = number_format($p['amount'], 2);
            return "{$nombreMetodo} (S/ {$monto})";
        })->implode(' + ');

        return [
            'boleta' => $order['serialNumber'] ?? "TK-{$order['number']}",
            'fechaEmision' => date('d/m/Y, h:i A', $timestamp),
            'cliente' => [
                'nombre' => trim(($order['client']['firstName'] ?? '') . ' ' . ($order['client']['lastName'] ?? '')) ?: "No encontrado",
                'dni_ruc' => $order['client']['code'] ?? 'No encontrado',
                'email' => $order['client']['email'] ?? 'No encontrado',
                'telefono' => $order['client']['phone'] ?? 'No encontrado'
            ],
            'vendedor' => trim(($order['sellers']['items'][0]['firstName'] ?? '') . ' ' . ($order['sellers']['items'][0]['lastName'] ?? '')) ?: "No encontrado",
            'atributos' => [
                'fechaDespacho' => $getAttr("FECHA DE DESPACHO"),
                'marcaRedSocial' => $getAttr("MARCA/RED SOCIAL"),
                'estadoPedido' => $getAttr("ESTADO DE PEDIDO"),
            ],
            'pago' => [
                'metodos' => $metodosDetallados ?: "No encontrado", 
                'montoTotal' => "S/ " . number_format($totalCaja, 2)
            ],
            'prendas' => collect($order['details']['items'] ?? [])->map(function($item) use ($includeVariantDetails) {
                $variantId = $item['variant']['id'] ?? null;
                $infoProducto = $this->resolveVariantDetail($item, $variantId, $includeVariantDetails);
                
                $montoPagadoReal = $item['totalAmount'];
                $descuento = $item['totalDiscount'] ?? 0;

                return [
                    'nombre' => $infoProducto['nombre'],
                    'sku' => $item['variant']['code'] ?? 'No encontrado',
                    'cantidad' => $item['quantity'],
                    'precioUnitario' => $montoPagadoReal + $descuento,
                    'descuentoAplicado' => $descuento,
                    'totalAPagar' => $montoPagadoReal
                ];
            })
        ];
    }

    private function resolveVariantDetail(array $item, $variantId, bool $includeVariantDetails = false): array
    {
        $localName = $this->extractVariantNameFromDocument($item);

        if ($localName !== null && ! ($includeVariantDetails && $this->looksLikeVariantSizeOnly($localName))) {
            return ['nombre' => $localName];
        }

        if (!$variantId) {
            return ['nombre' => 'No encontrado'];
        }

        if ($includeVariantDetails) {
            return $this->getVariantDetail($variantId);
        }

        // En el listado evitamos golpear el endpoint de variantes por cada prenda;
        // si el documento no trae un nombre útil, devolvemos un fallback estable.

        $sku = trim((string) ($item['variant']['code'] ?? ''));

        return ['nombre' => $sku !== '' ? $sku : 'No encontrado'];
    }

    private function looksLikeVariantSizeOnly(string $value): bool
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return false;
        }

        return preg_match('/^(?:\d+(?:\.\d+)?|[XSML]{1,4}|XXL|XXXL)$/i', $normalized) === 1;
    }

    private function extractVariantNameFromDocument(array $item): ?string
    {
        $candidates = [
            $item['name'] ?? null,
            $item['description'] ?? null,
            $item['variant']['description'] ?? null,
            $item['variant']['name'] ?? null,
            $item['variant']['product']['name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function getVariantDetail($variantId)
    {
        if (!$variantId) return ['nombre' => 'No encontrado'];

        // Si ya está en la memoria de esta carga, lo devolvemos inmediatamente
        if (isset(self::$variantCache[$variantId])) {
            return self::$variantCache[$variantId];
        }

        try {
            // Hacemos la llamada al endpoint de variantes
            $response = $this->client->get("variants/{$variantId}", ['expand' => '[product]']);
            
            if ($response->successful()) {
                $data = $response->json();
                $productName = $data['product']['name'] ?? 'Producto';
                $variantDesc = $data['description'] ?? '';
                $fullName = trim("$productName - $variantDesc");
                
                self::$variantCache[$variantId] = ['nombre' => $fullName ?: "No encontrado"];
                return self::$variantCache[$variantId];
            }
        } catch (\Exception $e) {
            // Error de conexión o timeout
        }

        return ['nombre' => "No encontrado"];
    }
}
