<?php

use App\Jobs\CancelExpiredOrderErrorsJob;
use App\Jobs\SyncBsaleDocumentsJob;
use App\Jobs\SyncWooOrdersJob;
use App\Models\BsaleDocument;
use App\Models\OrderSyncRun;
use App\Services\BsaleService;
use App\Services\Sync\SyncStateService;
use App\Services\WooCommerceManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Artisan::command('sync:woo:full {stores?}', function (): void {
    /** @var WooCommerceManager $manager */
    $manager = app(WooCommerceManager::class);
    /** @var SyncStateService $syncStates */
    $syncStates = app(SyncStateService::class);
    $rawStores = trim((string) $this->argument('stores'));
    $stores = $rawStores !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $rawStores))))
        : $manager->availableSlugs();

    $invalid = $manager->invalidSlugs($stores);
    if ($invalid !== []) {
        $this->error('Tiendas no encontradas: ' . implode(', ', $invalid));

        return;
    }

    foreach ($stores as $slug) {
        $syncStates->recoverStaleLock('woo_orders', $slug, (int) config('woocommerce.stale_after_minutes', 30));
        $state = $syncStates->state('woo_orders', $slug);
        if (in_array($state->status, ['queued', 'running'], true)) {
            $this->warn("Se omite full sync Woo para {$slug}: ya existe una corrida {$state->status}");
            continue;
        }

        $run = OrderSyncRun::query()->create([
            'status' => 'queued',
            'mode' => 'full',
            'requested_by' => null,
            'stores' => [$slug],
            'total_orders' => 0,
            'synced_orders' => 0,
            'failed_stores' => [],
        ]);

        $syncStates->markQueued('woo_orders', $slug, [
            'mode' => 'full',
            'run_id' => $run->id,
        ]);

        SyncWooOrdersJob::dispatch($run->id);
        $this->info("Full sync Woo encolado para {$slug} (run {$run->id})");
    }
})->purpose('Encola una sincronización completa de WooCommerce por tienda');

Artisan::command('sync:woo:incremental {stores?}', function (): void {
    /** @var WooCommerceManager $manager */
    $manager = app(WooCommerceManager::class);
    /** @var SyncStateService $syncStates */
    $syncStates = app(SyncStateService::class);
    $rawStores = trim((string) $this->argument('stores'));
    $stores = $rawStores !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $rawStores))))
        : $manager->availableSlugs();

    $invalid = $manager->invalidSlugs($stores);
    if ($invalid !== []) {
        $this->error('Tiendas no encontradas: ' . implode(', ', $invalid));

        return;
    }

    $now = now((string) config('app.timezone', 'UTC'));

    foreach ($stores as $slug) {
        $syncStates->recoverStaleLock('woo_orders', $slug, (int) config('woocommerce.stale_after_minutes', 30));
        $state = $syncStates->state('woo_orders', $slug);
        if (in_array($state->status, ['queued', 'running'], true)) {
            $this->warn("Se omite sync incremental Woo para {$slug}: ya existe una corrida {$state->status}");
            continue;
        }

        $fromDate = $state->last_cursor_at?->copy()->subMinutes((int) config('woocommerce.sync_overlap_minutes', 2))
            ?? $now->copy()->subMinutes((int) config('woocommerce.bootstrap_lookback_minutes', 1440));

        $run = OrderSyncRun::query()->create([
            'status' => 'queued',
            'mode' => 'incremental',
            'requested_by' => null,
            'stores' => [$slug],
            'from_date' => $fromDate,
            'to_date' => $now,
            'total_orders' => 0,
            'synced_orders' => 0,
            'failed_stores' => [],
        ]);

        $syncStates->markQueued('woo_orders', $slug, [
            'mode' => 'incremental',
            'run_id' => $run->id,
        ]);

        SyncWooOrdersJob::dispatch($run->id);
        $this->info("Sync incremental Woo encolado para {$slug} (run {$run->id})");
    }
})->purpose('Encola una sincronización incremental de WooCommerce por tienda');

Artisan::command('sync:bsale:full', function (): void {
    /** @var SyncStateService $syncStates */
    $syncStates = app(SyncStateService::class);
    $syncStates->recoverStaleLock('bsale_documents', 'default', (int) config('bsale.stale_after_minutes', 30));
    $state = $syncStates->state('bsale_documents');

    if (in_array($state->status, ['queued', 'running'], true)) {
        $this->warn('Se omite full sync Bsale: ya existe una corrida en curso');

        return;
    }

    $syncStates->markQueued('bsale_documents', 'default', ['mode' => 'full']);
    SyncBsaleDocumentsJob::dispatch('full');
    $this->info('Full sync Bsale encolado');
})->purpose('Encola una sincronización completa de documentos Bsale');

Artisan::command('sync:bsale:incremental', function (): void {
    /** @var SyncStateService $syncStates */
    $syncStates = app(SyncStateService::class);
    $syncStates->recoverStaleLock('bsale_documents', 'default', (int) config('bsale.stale_after_minutes', 30));
    $state = $syncStates->state('bsale_documents');

    if (in_array($state->status, ['queued', 'running'], true)) {
        $this->warn('Se omite sync incremental Bsale: ya existe una corrida en curso');

        return;
    }

    $syncStates->markQueued('bsale_documents', 'default', ['mode' => 'incremental']);
    SyncBsaleDocumentsJob::dispatch('incremental');
    $this->info('Sync incremental Bsale encolado');
})->purpose('Encola una sincronización incremental de documentos Bsale');

Artisan::command('sync:bsale:cleanup-web-duplicates', function (): void {
    /** @var BsaleService $bsaleService */
    $bsaleService = app(BsaleService::class);

    $deleted = 0;

    BsaleDocument::query()
        ->orderBy('id')
        ->chunkById(200, function ($documents) use ($bsaleService, &$deleted): void {
            foreach ($documents as $document) {
                $payload = is_array($document->payload) ? $document->payload : [];

                if (! $bsaleService->shouldExcludeDocument($payload)) {
                    continue;
                }

                $document->delete();
                $deleted++;
            }
        });

    $this->info("Documentos Bsale eliminados por duplicidad Woo: {$deleted}");
})->purpose('Elimina de bsale_documents los documentos web replicados desde WooCommerce');

Schedule::command('sync:woo:incremental')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->name('sync-woo-orders-incremental')
    ->description('Incrementally sync WooCommerce orders every minute using modified_after');

Schedule::command('sync:bsale:incremental')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->name('sync-bsale-documents-incremental')
    ->description('Incrementally sync Bsale documents every minute using generationDate');

Schedule::command('sync:woo:full')
    ->dailyAt('02:30')
    ->withoutOverlapping(120)
    ->name('sync-woo-orders-full-nightly')
    ->description('Run a nightly full WooCommerce reconciliation');

Schedule::command('sync:bsale:full')
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->name('sync-bsale-documents-full-nightly')
    ->description('Run a nightly full Bsale reconciliation');

Schedule::job(CancelExpiredOrderErrorsJob::class)
    ->hourly()
    ->name('cancel-expired-order-errors')
    ->description('Cancel orders with errors that have exceeded 1 day');
