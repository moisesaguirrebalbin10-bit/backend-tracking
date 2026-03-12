<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WoocommerceService
{
    private PendingRequest $client;
    private string $baseEndpoint;
    private int $perPage;

    public function __construct(private readonly array $storeConfig)
    {
        $baseUrl    = rtrim($storeConfig['base_url'], '/');
        $apiVersion = config('woocommerce.api_version', 'wc/v3');
        $this->perPage = (int) config('woocommerce.per_page', 100);
 
        $this->client = Http::withBasicAuth(
            $storeConfig['consumer_key'],
            $storeConfig['consumer_secret']
        )
            ->timeout(config('woocommerce.timeout', 30))
            ->acceptJson()
            ->baseUrl("{$baseUrl}/wp-json/{$apiVersion}");
    }
     public function getLabel(): string
    {
        return $this->storeConfig['label'] ?? 'unknown';
    }

    /**
     * GET a cualquier endpoint de WooCommerce.
     * 
     *
     */
     public function get(string $endpoint, array $params = []): array
    {
        return $this->handleResponse($this->client->get($endpoint, $params), "GET {$endpoint}");
    }

    /**
     * GET paginado: recorre TODAS las páginas y devuelve todos los registros.
     *
     */
     public function getAll(string $endpoint, array $params = []): array
    {
        $allItems = [];
        $page     = 1;
 
        do {
            $response = $this->client->get($endpoint, array_merge($params, [
                'page'     => $page,
                'per_page' => $this->perPage,
            ]));
 
            $this->handleResponse($response, "GET {$endpoint} (página {$page})");
            $items = $response->json();
 
            if (empty($items)) break;
 
            $allItems   = array_merge($allItems, $items);
            $totalPages = (int) $response->header('X-WP-TotalPages');
            $page++;
 
        } while ($page <= $totalPages);
 
        return $allItems;
    }
    /**
     * GET de un recurso por ID.
     *
     */
     public function find(string $endpoint, int|string $id): array
    {
        return $this->handleResponse($this->client->get("{$endpoint}/{$id}"), "GET {$endpoint}/{$id}");
    }

    /**
     * GET paginado devolviendo metadata de paginación junto con los datos.
     *
     */
    public function getPaginated(string $endpoint, int $page = 1, int $perPage = 20, array $params = []): array
    {
        $response = $this->client->get($endpoint, array_merge($params, [
            'page'     => $page,
            'per_page' => $perPage,
        ]));
 
        $this->handleResponse($response, "GET {$endpoint} paginado");
 
        return [
            'data' => $response->json(),
            'meta' => [
                'total'        => (int) $response->header('X-WP-Total'),
                'total_pages'  => (int) $response->header('X-WP-TotalPages'),
                'current_page' => $page,
                'per_page'     => $perPage,
            ],
        ];
    }
    
    #MANEJO DE ERRORES 
      private function handleResponse(Response $response, string $context): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }
 
        $status  = $response->status();
        $body    = $response->json();
        $message = $body['message'] ?? $response->body();
 
        Log::error("[WooCommerceService:{$this->getLabel()}] Error en {$context}", [
            'status'  => $status,
            'message' => $message,
        ]);
 
        throw new RuntimeException(
            "[WooCommerce:{$this->getLabel()}] {$context} falló con status {$status}: {$message}",
            $status
        );
    }
}
