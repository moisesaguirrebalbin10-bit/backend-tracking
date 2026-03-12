<?php

namespace App\Http\Controllers;

use App\Services\WooCommerceManager;
use App\Services\WooCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class WooCommerceController extends Controller
{
    public function __construct(
        private readonly WooCommerceManager $manager
    ) {
    }

    /**
     * GET /api/woo/orders
     * 
     */
    public function getOrders(Request $request): JsonResponse
    {
        try {

            return $this->multiStoreResponse(
                request: $request,
                callback: function (WooCommerceService $service) use ($request) {
                    return  $service->getPaginated(
                        endpoint: 'orders',
                        page: (int) $request->get('page', 1),
                        perPage: (int) $request->get('per_page', 20),
                        params: $request->only(['status', 'customer', 'search', 'orderby', 'order'])
                    );
                }
            );
            
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    /**
     * GET /api/woo/orders/{id}
     * DEBE APUNTAR A UNA SOLA TIENDA 
     */
    public function showOrder(Request $request, int $id): JsonResponse
    {
        return $this->singleStoreResponse($request, fn ($s) => $s->find('orders', $id));
    }

    #LISTA DE TIENDAS REGISTRADAS EN EL SISTEMA
    public function listStores(): JsonResponse
    {
        $stores = collect(config('woocommerce.stores'))
            ->map(fn($config, $slug) => [
                'slug' => $slug,
                'label' => $config['label'] ?? $slug,
            ])
            ->values();

        return response()->json(['data' => $stores]);
    }
    # RESPUESTA PARA MULTIPLES TIENDAS 
    private function multiStoreResponse(Request $request, callable $callback, int $successStatus = 200): JsonResponse
    {
        $slugs = $this->resolveSlugs($request);
        $invalid = $this->manager->invalidSlugs($slugs);

        if (!empty($invalid)) {
            return response()->json([
                'error' => true,
                'message' => "Tiendas no encontradas: " . implode(', ', $invalid),
                'available' => $this->manager->availableSlugs(),
            ], 422);
        }

        try {
            $result = [];

            foreach ($slugs as $slug) {
                $service = $this->manager->store($slug);
                $data = $callback($service);

                $result[$slug] = array_merge(['store' => $service->getLabel()], (array) $data);
            }

            return response()->json($result, $successStatus);

        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    #RESPUESTA A UNA SOLA TIENDA 
    private function singleStoreResponse(Request $request, callable $callback, int $successStatus = 200): JsonResponse
    {
        $slugs = $this->resolveSlugs($request);

        if (count($slugs) !== 1) {
            return response()->json([
                'error' => true,
                'message' => 'Esta operación requiere exactamente una tienda. Usa ?stores=slug',
            ], 422);
        }

        $slug = $slugs[0];
        $invalid = $this->manager->invalidSlugs([$slug]);

        if (!empty($invalid)) {
            return response()->json([
                'error' => true,
                'message' => "Tienda '{$slug}' no encontrada.",
                'available' => $this->manager->availableSlugs(),
            ], 422);
        }

        try {
            $service = $this->manager->store($slug);
            $data = $callback($service);

            return response()->json([
                $slug => array_merge(['store' => $service->getLabel()], (array) $data),
            ], $successStatus);

        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    #FILTRO PARA SLUGS EN PARAMS SI NO TIENE DEVUELVE DE TODAS LAS TIENDAS
    private function resolveSlugs(Request $request): array
    {
        $raw = $request->get('stores', '');

        if (empty(trim($raw))) {
            return $this->manager->availableSlugs();
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $raw))
            )
        );
    }
    #MANEJO DE ERRORES
    private function errorResponse(Throwable $e): JsonResponse
    {
        $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;

        return response()->json([
            'error' => true,
            'message' => $e->getMessage(),
        ], $code);
    }
}
