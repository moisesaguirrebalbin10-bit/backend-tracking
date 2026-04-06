<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BsaleDocument;
use App\Services\BsaleService;
use App\Services\Sync\SyncStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBsaleDocumentsJob implements ShouldQueue
{
    use Queueable;

    private function appNow(): Carbon
    {
        return Carbon::now((string) config('app.timezone', 'UTC'));
    }

    private function appTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    public function __construct(private readonly string $mode = 'incremental')
    {
    }

    public function handle(BsaleService $bsaleService, SyncStateService $syncStates): void
    {
        $scope = 'default';
        $state = $syncStates->markStarted('bsale_documents', $scope, [
            'mode' => $this->mode,
        ]);

        $now = $this->appNow();
        $batchSize = max(1, (int) config('bsale.batch_size', 50));
        $offset = 0;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $processed = 0;
        $maxCursor = null;

        try {
            $filters = $this->buildFilters($bsaleService, $state->last_cursor_at, $now);

            do {
                $page = $bsaleService->getDocumentsPage($offset, $batchSize, $filters);
                $items = $page['items'];
                $total = $page['total'];

                foreach ($items as $document) {
                    if (! is_array($document)) {
                        continue;
                    }

                    if ($bsaleService->shouldExcludeDocument($document)) {
                        $this->deleteExcludedDocument($document);
                        continue;
                    }

                    $result = $this->syncDocument($document);
                    $processed++;

                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    } else {
                        $unchanged++;
                    }

                    $generationDate = $this->parseUnixTimestamp($document['generationDate'] ?? null);
                    if ($generationDate !== null && ($maxCursor === null || $generationDate->greaterThan($maxCursor))) {
                        $maxCursor = $generationDate;
                    }
                }

                $offset += count($items);
            } while ($offset < $total && $items !== []);

            $syncStates->markSucceeded(
                'bsale_documents',
                $scope,
                $maxCursor ?? $now,
                [
                    'mode' => $this->mode,
                    'processed' => $processed,
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                ],
                $this->mode === 'full',
            );
        } catch (Throwable $e) {
            $syncStates->markFailed('bsale_documents', $scope, $e->getMessage(), [
                'mode' => $this->mode,
                'processed' => $processed,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
            ]);

            Log::error('Bsale document sync failed', [
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildFilters(BsaleService $bsaleService, ?Carbon $lastCursorAt, Carbon $now): array
    {
        if ($this->mode === 'full') {
            return [];
        }

        $from = $lastCursorAt?->copy()->subMinutes((int) config('bsale.sync_overlap_minutes', 5))
            ?? $now->copy()->subMinutes((int) config('bsale.bootstrap_lookback_minutes', 1440));

        return [
            'generationdaterange' => $bsaleService->buildGenerationDateRange($from, $now),
        ];
    }

    private function syncDocument(array $document): string
    {
        $externalId = (int) ($document['id'] ?? 0);
        if ($externalId <= 0) {
            return 'unchanged';
        }

        $fingerprint = hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: (string) $externalId);

        $record = BsaleDocument::query()->firstWhere('external_id', $externalId);

        if ($record !== null && $record->fingerprint === $fingerprint) {
            $record->forceFill([
                'synced_at' => $this->appNow(),
            ])->save();

            return 'unchanged';
        }

        $payload = [
            'document_number' => isset($document['number']) ? (int) $document['number'] : null,
            'serial_number' => isset($document['serialNumber']) ? (string) $document['serialNumber'] : null,
            'generation_date' => $this->parseUnixTimestamp($document['generationDate'] ?? null),
            'emission_date' => $this->parseUnixTimestamp($document['emissionDate'] ?? null),
            'total_amount' => (float) ($document['totalAmount'] ?? 0),
            'state' => isset($document['state']) ? (int) $document['state'] : null,
            'commercial_state' => isset($document['commercialState']) ? (int) $document['commercialState'] : null,
            'cancellation_status' => isset($document['cancellationStatus']) ? (int) $document['cancellationStatus'] : null,
            'client_code' => data_get($document, 'client.code'),
            'client_name' => trim(((string) data_get($document, 'client.firstName', '')) . ' ' . ((string) data_get($document, 'client.lastName', ''))) ?: null,
            'client_email' => data_get($document, 'client.email'),
            'client_phone' => data_get($document, 'client.phone'),
            'office_id' => data_get($document, 'office.id'),
            'office_name' => data_get($document, 'office.name'),
            'user_id_external' => data_get($document, 'user.id'),
            'user_name' => trim(((string) data_get($document, 'user.firstName', '')) . ' ' . ((string) data_get($document, 'user.lastName', ''))) ?: null,
            'document_type_id' => data_get($document, 'document_type.id'),
            'document_type_name' => data_get($document, 'document_type.name'),
            'fingerprint' => $fingerprint,
            'payload' => $document,
            'synced_at' => $this->appNow(),
        ];

        if ($record === null) {
            BsaleDocument::query()->create([
                'external_id' => $externalId,
                ...$payload,
            ]);

            return 'created';
        }

        $record->fill($payload)->save();

        return 'updated';
    }

    private function parseUnixTimestamp(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::createFromTimestampUTC((int) $value)->setTimezone($this->appTimezone());
        } catch (Throwable) {
            return null;
        }
    }

    private function deleteExcludedDocument(array $document): void
    {
        $externalId = (int) ($document['id'] ?? 0);

        if ($externalId <= 0) {
            return;
        }

        BsaleDocument::query()
            ->where('external_id', $externalId)
            ->delete();
    }
}