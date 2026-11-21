<?php

namespace App\Console\Commands;

use App\Models\CourierOffice;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontRules;
use Illuminate\Console\Command;

/**
 * `.168`: carga la lista de oficinas de MRW y Zoom (database/data/courier_offices.json, armada de sus páginas públicas)
 * para el formulario de la tienda. Se puede correr las veces que haga falta: agrega las nuevas y pone al día el nombre y
 * la dirección de las que ya están, sin volver a prender las que el dueño apagó ni tocar las que agregó él.
 */
class LoadCourierOffices extends Command
{
    protected $signature = 'couriers:oficinas {--archivo= : Otro archivo JSON con la misma forma}';

    protected $description = 'Carga las oficinas de MRW y Zoom donde el cliente retira su encomienda (formulario de la tienda)';

    public function handle(): int
    {
        $path = $this->option('archivo') ?: database_path('data/courier_offices.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($data) || !is_array($data['offices'] ?? null)) {
            $this->error("No pude leer la lista de oficinas ({$path}).");

            return self::FAILURE;
        }
        $added = $updated = $skipped = 0;
        foreach ($data['offices'] as $o) {
            $company = (string) ($o['company'] ?? '');
            $name = trim((string) ($o['name'] ?? ''));
            $state = trim((string) ($o['state'] ?? ''));
            $code = trim((string) ($o['code'] ?? ''));
            if (!in_array($company, StorefrontRules::COURIERS, true) || $name === '' || $state === '' || $code === '') {
                $skipped++;
                continue;
            }
            $values = ['state' => mb_substr($state, 0, 60), 'city' => mb_substr(trim((string) ($o['city'] ?? '')), 0, 80) ?: null,
                'name' => mb_substr($name, 0, 120), 'address' => mb_substr(trim((string) ($o['address'] ?? '')), 0, 300) ?: null];
            $office = CourierOffice::where('company', $company)->where('code', mb_substr($code, 0, 80))->first();
            if ($office) {
                $office->fill($values);
                if ($office->isDirty()) {
                    $office->save();
                    $updated++;
                }
                continue;
            }
            CourierOffice::create($values + ['company' => $company, 'code' => mb_substr($code, 0, 80), 'is_active' => true, 'source' => 'lista']);
            $added++;
        }
        StorefrontCatalog::refresh();
        foreach (StorefrontRules::COURIERS as $c) {
            $this->line("{$c}: " . CourierOffice::where('company', $c)->where('is_active', true)->count() . ' oficinas activas.');
        }
        $this->line("Nuevas: {$added} | actualizadas: {$updated} | saltadas (sin datos): {$skipped}");
        $this->line('FIN');

        return self::SUCCESS;
    }
}
