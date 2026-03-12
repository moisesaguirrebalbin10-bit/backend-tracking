<?php

namespace App\Services;

use InvalidArgumentException;

class WooCommerceManager
{
    
    private array $instances = [];
    
    #INSTANCIA UNA TIENDA POR SLUG 
    public function store(string $slug): WooCommerceService
    {
        $slug = strtolower(trim($slug));
 
        if (isset($this->instances[$slug])) {
            return $this->instances[$slug];
        }
 
        $config = config("woocommerce.stores.{$slug}");
 
        if (empty($config) || empty($config['base_url'])) {
            throw new InvalidArgumentException(
                "La tienda '{$slug}' no está registrada o le faltan credenciales en el .env."
            );
        }
 
        $this->instances[$slug] = new WooCommerceService($config);
 
        return $this->instances[$slug];
    }
    #TIENDAS POR SLUG 
    public function stores(array $slugs): array
    {
        $result = [];
 
        foreach ($slugs as $slug) {
            $result[$slug] = $this->store($slug);
        }
 
        return $result;
    }
 
    #INSTANCIA TODAS LAS TIENDAS CONFIGURADAS
    public function all(): array
    {
        return $this->stores($this->availableSlugs());
    }
 
    # LISTA LOS SLUGS DISPONIBLES EN LA CONFIGURACION
    public function availableSlugs(): array
    {
        return array_keys(config('woocommerce.stores', []));
    }
 
    # VALIDA QUE LOS SLUGS EXISTAN EN LA CONFIGURACION
    public function invalidSlugs(array $slugs): array
    {
        $available = $this->availableSlugs();
 
        return array_values(array_diff($slugs, $available));
    }
}
