<?php

namespace App\Jobs;

use App\Models\MetaUpload;
use App\Services\Meta\Upload\MetaUploader;
use App\Services\Meta\Upload\MetaUploads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * `.155` "Aprobar y subir": una subida a Meta, por la cola "meta" (la del worker de corta vida de routes/console.php, como
 * las sincronizaciones). Cada vuelta avanza lo que le cabe en ~55 s; si Meta está procesando los videos, vuelve en 30 s.
 */
class RunMetaUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 85;   // menos que retry_after (90) de la cola

    public function __construct(public int $uploadId)
    {
        $this->onQueue(SyncMetaConnection::QUEUE);
    }

    public function handle(): void
    {
        $u = MetaUpload::find($this->uploadId);
        if (!$u || !$u->isOpen()) {
            return;
        }
        $lock = Cache::lock('meta-upload:' . $u->id, 100);
        if (!$lock->get()) {
            self::dispatch($u->id)->delay(now()->addSeconds(30));
            return;
        }
        try {
            $result = (new MetaUploader())->advance($u, 55);
        } finally {
            $lock->release();
        }
        if ($result === 'wait') {
            self::dispatch($u->id)->delay(now()->addSeconds(30));
        } elseif ($result === 'again') {
            self::dispatch($u->id);
        }
    }

    /** Algo que no es de Meta (la base, el servidor): la subida queda fallida, con "Reintentar". */
    public function failed(\Throwable $e): void
    {
        $u = MetaUpload::find($this->uploadId);
        if ($u && $u->isOpen()) {
            $u->forceFill(['status' => 'failed', 'error' => 'el sistema se cortó a mitad de camino', 'finished_at' => now()])->save();
            MetaUploads::finished($u);
        }
    }
}
