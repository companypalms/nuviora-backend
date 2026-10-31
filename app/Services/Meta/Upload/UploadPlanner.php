<?php

namespace App\Services\Meta\Upload;

use App\Models\CreativeBatch;
use App\Models\MetaAd;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Services\Meta\Queue\EditorRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Lo que "Subir a Meta" va a crear para un batch en banca (`.57`, el simulacro: no escribe nada en Meta). Lo mismo que
 * usará la `.58` para subirlo de verdad:
 * - la campaña: la del producto en la ciudad de aprendizaje, activa (si hay varias, la que más gastó en 7 días); tiene
 *   que repartir el presupuesto (CBO), como todas las del dueño;
 * - el ad set a copiar: el activo más nuevo de esa campaña (su público, ubicaciones y optimización);
 * - un anuncio por pieza, con el archivo de su enlace de Drive (se revisa que sea público y se pueda bajar);
 * - el texto, la landing y los parámetros de URL: los del anuncio más nuevo de la referencia (o, sin referencia, del ad
 *   set que se copia), con los UTM de la `.53`.
 * Lo que impide subirlo va en "problems"; lo que conviene saber, en "warnings".
 */
class UploadPlanner
{
    public function plan(CreativeBatch $batch, bool $checkFiles = true): array
    {
        $batch->loadMissing(['pieces', 'product:id,title,showable_name', 'learningCity:id,name', 'reference:id,label']);
        $problems = [];
        $warnings = [];
        $angle = $batch->angle_code;
        $code = $batch->code();
        if ($batch->status !== 'bank') {
            $problems[] = 'Solo se sube un batch "En banca" (ya aprobado). Este está "' . $batch->status . '".';
        }
        if ($code === null) {
            $problems[] = 'El batch todavía no tiene número (B…): se lo da la aprobación.';
        }
        if (!$angle) {
            $problems[] = 'El batch no tiene ángulo: póntelo para que los nombres queden como "B0123 FASP".';
        }

        // La campaña del producto en la ciudad de aprendizaje
        $campaign = null;
        if (!$batch->learning_city_id) {
            $problems[] = 'El batch no tiene ciudad de aprendizaje: elígela para saber en qué campaña va.';
        } else {
            $spend = DB::table('meta_insights_daily')->where('level', 'campaign')->where('date', '>=', now()->subDays(7)->toDateString())
                ->groupBy('campaign_meta_id')->selectRaw('campaign_meta_id, SUM(spend) AS spend');
            $campaign = MetaCampaign::query()->leftJoinSub($spend, 's', 's.campaign_meta_id', '=', 'meta_campaigns.meta_id')
                ->where('meta_campaigns.product_id', $batch->product_id)->where('meta_campaigns.city_id', $batch->learning_city_id)
                ->where('meta_campaigns.effective_status', 'ACTIVE')->whereNull('meta_campaigns.missing_since')
                ->orderByRaw('COALESCE(s.spend, 0) DESC')->orderByDesc('meta_campaigns.id')->select('meta_campaigns.*')->first();
            if (!$campaign) {
                $problems[] = 'No hay una campaña activa de ' . $this->productName($batch) . ' en ' . ($batch->learningCity?->name ?? 'esa ciudad') . '.';
            }
        }
        $budget = $campaign ? UploadRules::campaignBudget($campaign->meta) : null;
        if ($campaign && $budget === null) {
            $problems[] = "La campaña {$campaign->name} no tiene presupuesto de campaña: el ad set nuevo necesitaría uno propio y tus campañas reparten el presupuesto desde la campaña.";
        }

        // El ad set que se copia
        $template = $campaign ? MetaAdset::where('campaign_meta_id', $campaign->meta_id)->where('effective_status', 'ACTIVE')->whereNull('missing_since')
            ->orderByDesc('created_time')->orderByDesc('id')->first() : null;
        if ($campaign && !$template) {
            $problems[] = "La campaña {$campaign->name} no tiene un ad set activo para copiar su configuración.";
        }
        if ($campaign && $code && MetaAdset::where('campaign_meta_id', $campaign->meta_id)->where('name', 'like', $code . '%')->whereNull('missing_since')->exists()) {
            $problems[] = "Ya hay un ad set {$code} en esa campaña: este batch ya se subió.";
        }

        // Los textos: de la referencia o, sin referencia, del ad set que se copia
        $source = $batch->reference_creative_id ? MetaAd::where('meta_creative_ref_id', $batch->reference_creative_id)->orderByDesc('created_time')->first() : null;
        $from = $source ? 'la referencia ' . ($batch->reference?->label ?? '') : null;
        if (!$source && $template) {
            $source = MetaAd::where('adset_meta_id', $template->meta_id)->whereNull('missing_since')->orderByDesc('created_time')->first();
            $from = $source ? "el anuncio más nuevo del ad set {$template->name}" : null;
        }
        if (!$source || (!$source->ad_body && !$source->landing_url)) {
            $problems[] = 'No hay un anuncio de donde copiar el texto y la landing (ni de la referencia ni del ad set que se copia).';
        }
        if ($source && !$source->landing_url) {
            $problems[] = 'El anuncio de donde se copia no tiene landing.';
        }

        // Un anuncio por pieza, con su archivo de Drive
        $ads = [];
        foreach ($batch->pieces->sortBy('position') as $piece) {
            $fileId = EditorRules::driveFileId($piece->link);
            $file = ['ok' => false, 'note' => null, 'size_mb' => null];
            if (!$piece->link) {
                $file['note'] = 'La pieza no tiene enlace de Drive (falta la entrega).';
            } elseif ($fileId === null) {
                $file['note'] = 'El enlace no es de un archivo de Drive (¿es una carpeta?).';
            } elseif ($checkFiles) {
                $file = $this->checkFile($fileId);
            } else {
                $file = ['ok' => true, 'note' => 'Sin revisar.', 'size_mb' => null];
            }
            if (!$file['ok']) {
                $problems[] = ($piece->code() ?? 'Una pieza') . ': ' . $file['note'];
            }
            $ads[] = [
                'code' => $piece->code(), 'name' => $piece->code() ? UploadRules::adName($piece->code(), $angle) : null,
                'format' => $piece->format ?: $batch->format, 'hook' => $piece->hook, 'link' => $piece->link,
                'file_ok' => $file['ok'], 'file_note' => $file['note'], 'size_mb' => $file['size_mb'],
            ];
        }
        if ($ads === []) {
            $problems[] = 'El batch no tiene piezas.';
        }
        $warnings[] = 'Los anuncios se crean ENCENDIDOS: empiezan a gastar del presupuesto de la campaña en cuanto Meta los aprueba.';

        return [
            'batch' => ['id' => $batch->id, 'code' => $code, 'product' => $this->productName($batch), 'angle' => $angle,
                'city' => $batch->learningCity?->name, 'reference' => $batch->reference?->label],
            'campaign' => $campaign ? ['meta_id' => $campaign->meta_id, 'name' => $campaign->name, 'budget' => $budget] : null,
            'template_adset' => $template ? ['meta_id' => $template->meta_id, 'name' => $template->name, 'optimization_goal' => $template->optimization_goal] : null,
            'adset_name' => $code ? UploadRules::adsetName($code, $angle) : null,
            'status' => UploadRules::STATUS,
            'texts' => $source ? ['from' => $from, 'title' => $source->ad_title, 'body' => $source->ad_body, 'landing_url' => $source->landing_url,
                'url_tags' => UploadRules::urlTags($source->url_tags)] : null,
            'ads' => $ads,
            'problems' => array_values(array_unique($problems)),
            'warnings' => $warnings,
            'ready' => $problems === [],
        ];
    }

    /**
     * Pide a Drive el principio del archivo: si es público y se puede bajar, y cuánto pesa si lo dice.
     *
     * @return array{ok: bool, note: ?string, size_mb: ?float}
     */
    private function checkFile(string $fileId): array
    {
        try {
            $r = Http::withHeaders(['Range' => 'bytes=0-2047'])->timeout(20)->get(UploadRules::driveDownloadUrl($fileId));
        } catch (\Throwable $e) {
            return ['ok' => false, 'note' => 'No se pudo consultar Drive: ' . mb_substr($e->getMessage(), 0, 120), 'size_mb' => null];
        }
        $answer = UploadRules::driveAnswer($r->status(), $r->header('Content-Type'), mb_substr($r->body(), 0, 4000));
        $size = null;
        if (preg_match('#/(\d+)$#', (string) $r->header('Content-Range'), $m)) {
            $size = round((int) $m[1] / 1048576, 1);
        }

        return $answer + ['size_mb' => $size];
    }

    private function productName(CreativeBatch $batch): string
    {
        return $batch->product?->showable_name ?: ($batch->product?->title ?? 'el producto');
    }
}
