<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case EN_PROCESO = 'en_proceso';
    case EMPAQUETADO = 'empaquetado';
    case DESPACHADO = 'despachado';
    case EN_CAMINO = 'en_camino';
    case ENTREGADO = 'entregado';
    case ERROR = 'error_en_pedido';
    case CANCELADO = 'cancelado';

    public static function labels(): array
    {
        return [
            self::EN_PROCESO->value => 'En Proceso',
            self::EMPAQUETADO->value => 'Empaquetado',
            self::DESPACHADO->value => 'Despachado',
            self::EN_CAMINO->value => 'En Camino',
            self::ENTREGADO->value => 'Entregado',
            self::ERROR->value => 'Error en Pedido',
            self::CANCELADO->value => 'Cancelado',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public static function validTransitions(): array
    {
        return [
            self::EN_PROCESO->value => [self::EMPAQUETADO, self::ERROR],
            self::EMPAQUETADO->value => [self::DESPACHADO, self::ERROR],
            self::DESPACHADO->value => [self::EN_CAMINO, self::ERROR],
            self::EN_CAMINO->value => [self::ENTREGADO, self::ERROR],
            self::ENTREGADO->value => [],
            self::ERROR->value => [self::EN_PROCESO, self::CANCELADO],
            self::CANCELADO->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        $allowed = self::validTransitions()[$this->value] ?? [];
        return in_array($target, $allowed);
    }
}
