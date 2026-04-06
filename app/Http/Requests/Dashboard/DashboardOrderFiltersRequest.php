<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class DashboardOrderFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'in:all,woo,bsale'],
            'scope' => ['nullable', 'string', 'in:all,my_queue'],
            'period' => ['nullable', 'string', 'in:day,week,month,range'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->source() === 'all' && $this->statusFilter() === null) {
                return;
            }

            if ($this->query('period', 'month') !== 'range') {
                return;
            }

            if (! $this->filled('date_from') || ! $this->filled('date_to')) {
                $validator->errors()->add('date_from', 'Debes enviar date_from y date_to cuando uses period=range.');
            }
        });
    }

    public function source(): string
    {
        return (string) $this->query('source', 'all');
    }

    public function period(): string
    {
        return (string) $this->query('period', 'month');
    }

    public function scopeFilter(): string
    {
        $value = (string) $this->query('scope', '');

        if (in_array($value, ['all', 'my_queue'], true)) {
            return $value;
        }

        $user = $this->user();
        if ($user !== null && ! $user->isAdmin() && in_array($user->role, [
            UserRole::EMPAQUETADOR,
            UserRole::DESPACHADOR,
            UserRole::DELIVERY,
        ], true)) {
            return 'my_queue';
        }

        return 'all';
    }

    public function searchTerm(): string
    {
        return trim((string) $this->query('search', ''));
    }

    public function perPage(): int
    {
        return max(1, min((int) $this->query('per_page', 50), 200));
    }

    public function statusFilter(): ?string
    {
        $value = trim((string) $this->query('status', ''));

        if ($value === '' || $value === 'all' || $value === 'todos') {
            return null;
        }

        return match ($value) {
            'error' => OrderStatus::ERROR->value,
            default => $value,
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);

        return match ($this->period()) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'range' => [
                Carbon::parse((string) $this->query('date_from'), $timezone)->startOfDay(),
                Carbon::parse((string) $this->query('date_to'), $timezone)->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }
}