<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case VENDEDOR_REDES = 'vendedor_redes';
    case VENTAS_WEB = 'ventas_web';
    case EMPAQUETADOR = 'empaquetador';
    case DESPACHADOR = 'despachador';
    case DELIVERY = 'delivery';

    public static function labels(): array
    {
        return [
            self::ADMIN->value => 'Administrador',
            self::VENDEDOR_REDES->value => 'Vendedor Redes',
            self::VENTAS_WEB->value => 'Ventas Web',
            self::EMPAQUETADOR->value => 'Empaquetador',
            self::DESPACHADOR->value => 'Despachador',
            self::DELIVERY->value => 'Delivery',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public static function allowedStatesForRole(self $role): array
    {
        return match ($role) {
            self::ADMIN => OrderStatus::cases(),
            self::EMPAQUETADOR => [OrderStatus::EMPAQUETADO],
            self::DESPACHADOR => [OrderStatus::DESPACHADO, OrderStatus::EN_CAMINO],
            self::DELIVERY => [OrderStatus::EN_CAMINO, OrderStatus::ENTREGADO, OrderStatus::ERROR],
            default => [],
        };
    }
}
