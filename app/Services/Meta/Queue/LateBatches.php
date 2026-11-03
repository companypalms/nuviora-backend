<?php

namespace App\Services\Meta\Queue;

use App\Models\CreativeBatch;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MetaWorkNotification;
use App\Services\Meta\Analysis\UrgentList;

/**
 * `.89` (dueño, 2026-11-03: "de alguna manera debería saber si se le pidió al editor en líder pero está atrasado, debería
 * decirme algo tipo, métele presión al editor en líder para que entregue este batch que se necesita con urgencia"): los
 * encargos que pasaron su fecha sin entregarse. Urgente = su producto necesita un batch hoy (DemandRules). Van como misión
 * en el análisis del dueño y, una vez por día, el sistema también se lo recuerda al líder en su campanita.
 */
class LateBatches
{
    /** Lo que ya se le recordó al líder hoy (para no repetirlo con cada "Volver a analizar"). */
    public const REMINDED = 'meta.queue.late_reminded';

    /**
     * @param array<int, array> $decisions MetaDecisions::compute
     * @return list<array{id: int, product_id: int, product: string, code: string, due: string, days: int, editor: ?string, urgent: bool, cities: list<string>}>
     */
    public static function find(array $decisions, string $today): array
    {
        $out = [];
        $rows = CreativeBatch::with(['product:id,title,showable_name', 'editor:id,names,surnames'])->whereIn('status', ['approved', 'production'])
            ->whereNotNull('number')->whereNotNull('due_date')->where('due_date', '<', $today)->orderBy('due_date')->orderBy('id')->get();
        foreach ($rows as $b) {
            $pid = (int) $b->product_id;
            $need = isset($decisions[$pid]) ? DemandRules::today($decisions[$pid]) : ['today' => 0, 'cities' => []];
            $out[] = [
                'id' => (int) $b->id, 'product_id' => $pid, 'product' => (string) ($b->product?->showable_name ?: $b->product?->title ?: "Producto {$pid}"),
                'code' => (string) $b->code(), 'due' => $b->due_date->toDateString(),
                'days' => (int) round((strtotime($today) - strtotime($b->due_date->toDateString())) / 86400),
                'editor' => $b->editor ? trim($b->editor->names . ' ' . ($b->editor->surnames ?? '')) : null,
                'urgent' => $need['today'] > 0, 'cities' => array_keys($need['cities']),
            ];
        }

        return $out;
    }

    /** El nombre del editor líder (para "Escríbele a …"). */
    public static function leader(): ?string
    {
        $l = collect(EditorWork::editors())->firstWhere('leader', true);

        return $l['name'] ?? null;
    }

    /** Una vez por día y por encargo: el recordatorio al editor líder en su campanita. */
    public static function remind(array $late, string $today): int
    {
        $done = Setting::get(self::REMINDED);
        $ids = ($done['date'] ?? null) === $today ? array_map('intval', $done['ids'] ?? []) : [];
        $todo = array_values(array_filter($late, fn ($b) => !in_array($b['id'], $ids, true)));
        if ($todo === []) {
            return 0;
        }
        $leaders = User::whereNull('deactivated_at')->whereHas('role', fn ($q) => $q->where('description', EditorRules::LEADER))->get();
        foreach ($todo as $b) {
            $short = UrgentList::short($b['product']);
            $due = ForecastRules::days([$b['due']]);
            $message = $b['urgent'] ? "Urgente: {$b['code']} de {$short} era para {$due} y se necesita hoy. Entrégalo hoy."
                : "{$b['code']} de {$short} está atrasado: era para {$due}.";
            foreach ($leaders as $u) {
                $u->notify(new MetaWorkNotification('editor_late', $message, '/editor/equipo'));
            }
            $ids[] = $b['id'];
        }
        Setting::set(self::REMINDED, ['date' => $today, 'ids' => $ids]);

        return count($todo);
    }
}
