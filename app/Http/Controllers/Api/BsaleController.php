<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BsaleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class BsaleController extends Controller
{
    protected $bsaleService;

    public function __construct(BsaleService $bsaleService)
    {
        $this->bsaleService = $bsaleService;
    }

    public function index(Request $request)
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $limit = min(50, max(1, (int) $request->query('limit', 50)));
        $filters = $this->resolveFilters($request);
        $filters['include_variant_details'] = $request->boolean('include_variant_details', false);

        try {
            $result = $this->bsaleService->getOrders($offset, $limit, $filters);

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con Bsale. Revisa BSALE_BASE_URL y la conectividad.',
            ], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Error inesperado al obtener ordenes de Bsale.',
            ], 500);
        }
    }

    /**
     * Convierte filtros del front a un rango de emisión compatible con Bsale.
     *
     * Reglas:
     * - `period=day`    => hoy
     * - `period=week`   => últimos 7 días calendario, incluyendo hoy
     * - `period=month`  => mes actual desde el día 1 hasta hoy
     * - `date_from/date_to` => rango manual
     */
    private function resolveFilters(Request $request): array
    {
        $request->validate([
            'period' => ['nullable', 'string', 'in:day,week,month'],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
        ]);

        $timezone = (string) config('app.timezone', 'UTC');
        $period = $request->query('period');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        if (blank($period) && blank($dateFrom) && blank($dateTo)) {
            return [];
        }

        if (filled($dateFrom) xor filled($dateTo)) {
            throw ValidationException::withMessages([
                'date_from' => ['Debes enviar date_from y date_to juntos para usar un rango manual.'],
            ]);
        }

        if (filled($dateFrom) && filled($dateTo)) {
            $start = $this->parseDate($dateFrom, $timezone)->startOfDay();
            $end = $this->parseDate($dateTo, $timezone)->endOfDay();
            $source = 'range';
        } else {
            $now = CarbonImmutable::now($timezone);

            [$start, $end] = match ($period) {
                'day' => [$now->startOfDay(), $now->endOfDay()],
                'week' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
                'month' => [$now->startOfMonth(), $now->endOfDay()],
                default => throw ValidationException::withMessages([
                    'period' => ['Periodo no válido. Usa day, week o month.'],
                ]),
            };

            $source = $period;
        }

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'date_from' => ['La fecha inicial no puede ser mayor que la fecha final.'],
            ]);
        }

        return [
            'emissiondaterange' => sprintf('[%d,%d]', $start->timestamp, $end->timestamp),
            'meta' => [
                'source' => $source,
                'timezone' => $timezone,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'timestamp_from' => $start->timestamp,
                'timestamp_to' => $end->timestamp,
            ],
        ];
    }

    private function parseDate(string $value, string $timezone): CarbonImmutable
    {
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'];

        foreach ($formats as $format) {
            if (!CarbonImmutable::canBeCreatedFromFormat($value, $format)) {
                continue;
            }

            return CarbonImmutable::createFromFormat($format, $value, $timezone);
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestamp((int) $value, $timezone);
        }

        throw ValidationException::withMessages([
            'date_from' => ['Formato de fecha no válido. Usa YYYY-MM-DD o DD/MM/YYYY.'],
        ]);
    }
}
