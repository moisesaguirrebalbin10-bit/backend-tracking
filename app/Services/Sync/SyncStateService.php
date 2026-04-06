<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\IntegrationSyncState;
use Illuminate\Support\Carbon;

final class SyncStateService
{
    private function appNow(): Carbon
    {
        return Carbon::now((string) config('app.timezone', 'UTC'));
    }

    private function normalizeForDatabase(Carbon $value): Carbon
    {
        return $value->copy()->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public function recoverStaleLock(
        string $integration,
        string $scope = 'default',
        int $staleAfterMinutes = 30,
    ): IntegrationSyncState {
        $state = $this->state($integration, $scope);

        if (! in_array($state->status, ['queued', 'running'], true)) {
            return $state;
        }

        $reference = $state->last_started_at ?? $state->updated_at;
        if ($reference === null) {
            return $state;
        }

        if ($reference->copy()->addMinutes($staleAfterMinutes)->isFuture()) {
            return $state;
        }

        return $this->markFailed(
            $integration,
            $scope,
            sprintf('Stale sync lock recovered automatically after %d minutes.', $staleAfterMinutes),
            array_merge($state->meta ?? [], [
                'recovered_stale_lock' => true,
                'recovered_at' => $this->appNow()->toIso8601String(),
            ]),
        );
    }

    public function state(string $integration, string $scope = 'default'): IntegrationSyncState
    {
        return IntegrationSyncState::query()->firstOrCreate(
            [
                'integration' => $integration,
                'scope' => $scope,
            ],
            [
                'status' => 'idle',
            ],
        );
    }

    public function markStarted(string $integration, string $scope = 'default', array $meta = []): IntegrationSyncState
    {
        $state = $this->state($integration, $scope);

        $state->forceFill([
            'status' => 'running',
            'last_started_at' => $this->appNow(),
            'error_message' => null,
            'meta' => $meta !== [] ? $meta : $state->meta,
        ])->save();

        return $state;
    }

    public function markQueued(string $integration, string $scope = 'default', array $meta = []): IntegrationSyncState
    {
        $state = $this->state($integration, $scope);

        $state->forceFill([
            'status' => 'queued',
            'error_message' => null,
            'meta' => $meta !== [] ? $meta : $state->meta,
        ])->save();

        return $state;
    }

    public function markSucceeded(
        string $integration,
        string $scope = 'default',
        ?Carbon $cursorAt = null,
        array $meta = [],
        bool $fullSync = false,
    ): IntegrationSyncState {
        $state = $this->state($integration, $scope);

        $payload = [
            'status' => 'idle',
            'last_finished_at' => $this->appNow(),
            'error_message' => null,
        ];

        if ($cursorAt !== null) {
            $payload['last_cursor_at'] = $this->normalizeForDatabase($cursorAt);
        }

        if ($fullSync) {
            $payload['last_full_sync_at'] = $this->appNow();
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        $state->forceFill($payload)->save();

        return $state;
    }

    public function markFailed(string $integration, string $scope = 'default', string $message, array $meta = []): IntegrationSyncState
    {
        $state = $this->state($integration, $scope);

        $payload = [
            'status' => 'failed',
            'last_finished_at' => $this->appNow(),
            'error_message' => $message,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        $state->forceFill($payload)->save();

        return $state;
    }
}