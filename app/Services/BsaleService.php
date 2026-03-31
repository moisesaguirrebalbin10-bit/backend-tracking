<?php

namespace App\Services;

use App\Services\Integrations\BsaleClient;

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

        $baseParams = ['state' => 0];

        if (!empty($filters['emissiondaterange'])) {
            $baseParams['emissiondaterange'] = $filters['emissiondaterange'];
        }

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
            ->filter(function ($order) {
                $vendedor = $order['sellers']['items'][0] ?? null;
                if (!$vendedor) return true;
                $fullName = strtoupper(($vendedor['firstName'] ?? '') . ' ' . ($vendedor['lastName'] ?? ''));
                return !str_contains($fullName, 'WEB');
            })
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

        if ($localName !== null) {
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
