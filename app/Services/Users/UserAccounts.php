<?php

namespace App\Services\Users;

use App\Constants\OrderStatus;
use App\Models\City;
use App\Models\DailyAgentRoster;
use App\Models\LogisticsOrder;
use App\Models\Order;
use App\Models\RiderProfile;
use App\Models\RosterChange;
use App\Models\SalesGroupMember;
use App\Models\User;
use App\Models\UserAccountChange;
use App\Models\WhatsappConversation;
use App\Services\Agencies\AgencyRouter;
use App\Services\Assignment\BulkReassignService;
use App\Services\Logistics\Candidates;
use App\Services\Metrics\PersonName;
use App\Services\Metrics\Readers\TeamReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desactivar y reactivar cuentas (Etapa 4, paso 6). "Eliminar" a alguien es desactivarlo: no se borra nada.
 * - Al desactivar: se cierran sus sesiones (y el login la rechaza), sale del roster de hoy, deja de recibir órdenes,
 *   leads de WhatsApp y rondas de Logística propia, y no sale en las listas para asignar a mano. Lo que hizo sigue en su
 *   historial y en las métricas. Las órdenes que tenga no se mueven solas: la ventana avisa y ofrece pasarlas.
 * - Al reactivar vuelve a entrar con su misma contraseña, y vuelve a su roster de siempre al abrir la jornada.
 * - Lo usan la pantalla de Usuarios y, en el paso 7, el asistente.
 */
class UserAccounts
{
    /** Las que no se desactivan nunca: el dueño, el Master y la cuenta que recibe las órdenes de Shopify. */
    public const PROTECTED_ROLES = ['Admin', 'Master', 'Shopify'];

    public const REASON_MIN = 5;

    /** Órdenes en manos de un repartidor. */
    private const RIDER_STATUSES = [OrderStatus::ASIGNADO_A_REPARTIDOR, OrderStatus::EN_RUTA];

    /** Por qué no se puede desactivar, en una frase; null si se puede. */
    public function problem(User $target, User $by): ?string
    {
        $name = $this->name($target);
        $role = $target->role?->description;
        if ($target->isDeactivated()) {
            return "La cuenta de {$name} ya está desactivada.";
        }
        if ((int) $target->id === (int) $by->id) {
            return 'No puedes desactivar tu propia cuenta.';
        }
        if ($role === 'Shopify') {
            return 'Es la cuenta del sistema que recibe las órdenes de Shopify: no se desactiva.';
        }
        if (in_array($role, self::PROTECTED_ROLES, true)) {
            return "Las cuentas de {$role} no se desactivan.";
        }
        if ($group = $target->ledGroup()) {
            return "{$name} es la líder del grupo \"{$group->name}\": primero cambia la líder en Reparto › Grupos y %.";
        }

        return null;
    }

    /**
     * Lo que tiene pendiente y lo que pasa al desactivarla, en frases para la ventana. Cada línea: key, text, tone
     * (warning si conviene resolverlo antes, info si solo avisa), count y action (qué ventana abre para resolverlo).
     *
     * @return array<int, array{key: string, text: string, tone: string, count?: int, action?: string}>
     */
    public function pending(User $target): array
    {
        $lines = [];
        $id = (int) $target->id;
        $role = $target->role?->description;

        if ($role === 'Vendedor') {
            $orders = Order::where('agent_id', $id)
                ->whereHas('status', fn ($q) => $q->whereIn('description', BulkReassignService::REASSIGNABLE_STATUSES))->count();
            if ($orders > 0) {
                $lines[] = ['key' => 'orders', 'count' => $orders, 'tone' => 'warning', 'action' => 'reassign_seller',
                    'text' => self::plural($orders, 'orden', 'órdenes') . ' en sus manos (por llamar, programadas, con novedad…). Si la desactivas, se quedan con ella hasta que las pases.'];
            }
            $chats = WhatsappConversation::where('agent_id', $id)->where('status', 'open')->count();
            if ($chats > 0) {
                $lines[] = ['key' => 'chats', 'count' => $chats, 'tone' => 'info',
                    'text' => self::plural($chats, 'chat de WhatsApp abierto', 'chats de WhatsApp abiertos') . ': cuando el cliente escriba, pasa solo a otra vendedora del turno. También puedes pasarlos desde WhatsApp CRM.'];
            }
            if (DailyAgentRoster::where('agent_id', $id)->where('date', now()->toDateString())->where('is_active', true)->exists()) {
                $lines[] = ['key' => 'roster', 'tone' => 'info', 'text' => 'Está en el roster de hoy: sale al desactivarla.'];
            }
            $group = SalesGroupMember::open()->where('user_id', $id)->with('group:id,name,is_active')->first()?->group;
            if ($group?->is_active) {
                $lines[] = ['key' => 'group', 'tone' => 'info', 'text' => "Está en el grupo \"{$group->name}\": sigue en el grupo para su historial, pero no recibe órdenes."];
            }
        }

        if ($role === 'Agencia') {
            $orders = Order::where('agency_id', $id)
                ->whereHas('status', fn ($q) => $q->whereIn('description', AgencyRouter::ACTIVE_STATUSES))->count();
            if ($orders > 0) {
                $lines[] = ['key' => 'orders', 'count' => $orders, 'tone' => 'warning', 'action' => 'reassign_agency',
                    'text' => self::plural($orders, 'orden', 'órdenes') . ' sin terminar. Las que no salieron a la calle se pueden pasar a otra agencia de su ciudad.'];
            }
            $cash = (float) (app(TeamReader::class)->agencyCash()[$id] ?? 0);
            if ($cash >= 0.01) {
                $lines[] = ['key' => 'cash', 'tone' => 'warning', 'text' => 'Tiene ' . self::usd($cash) . ' de órdenes entregadas sin entregar a la empresa (Finanzas › Caja).'];
            }
            $alone = $this->citiesLeftWithout($target);
            if ($alone !== []) {
                $lines[] = ['key' => 'cities', 'tone' => 'warning',
                    'text' => 'Es la única agencia de ' . self::listing($alone) . ': ' . (count($alone) === 1 ? 'esa ciudad deja' : 'esas ciudades dejan')
                        . ' de recibir órdenes (quedan en espera, como cuando la apagas) hasta que pongas otra agencia o la reactives.'];
            }
            $riders = User::active()->where('agency_id', $id)->count();
            if ($riders > 0) {
                $lines[] = ['key' => 'riders', 'tone' => 'info',
                    'text' => 'Tiene ' . self::plural($riders, 'repartidor propio', 'repartidores propios', false) . ': siguen activos (desactívalos aparte si ya no trabajan).'];
            }
        }

        if ($role === 'Repartidor') {
            $orders = Order::where('deliverer_id', $id)
                ->whereHas('status', fn ($q) => $q->whereIn('description', self::RIDER_STATUSES))->count();
            if ($orders > 0) {
                $lines[] = ['key' => 'orders', 'count' => $orders, 'tone' => 'warning',
                    'text' => self::plural($orders, 'orden asignada', 'órdenes asignadas') . ' o en ruta: asígnalas a otro repartidor desde la orden.'];
            }
            if (LogisticsOrder::where('rider_id', $id)->whereIn('status', Candidates::BUSY)->exists()) {
                $lines[] = ['key' => 'logistics', 'tone' => 'warning', 'text' => 'Lleva una orden de Logística propia ahora mismo.'];
            }
            $cash = (float) (app(TeamReader::class)->riderCash([$id])[$id] ?? 0);
            if ($cash >= 0.01) {
                $lines[] = ['key' => 'cash', 'tone' => 'warning', 'text' => 'Tiene ' . self::usd($cash) . ' en efectivo de entregas sin rendir.'];
            }
            if (RiderProfile::where('user_id', $id)->where('is_active', true)->exists()) {
                $lines[] = ['key' => 'rider_profile', 'tone' => 'info', 'text' => 'Sale de las rondas de Logística propia (al reactivar la cuenta, vuelve).'];
            }
        }

        $lines[] = ['key' => 'session', 'tone' => 'info', 'text' => 'Se cierra su sesión en todos lados y ya no puede entrar. Su historial queda y puedes reactivar la cuenta cuando quieras.'];

        return $lines;
    }

    public function deactivate(User $target, User $by, string $reason): User
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw ValidationException::withMessages(['reason' => 'Escribe el motivo (al menos ' . self::REASON_MIN . ' letras): queda en su historial.']);
        }

        return DB::transaction(function () use ($target, $by, $reason) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail()->load('role');
            if ($problem = $this->problem($user, $by)) {
                throw ValidationException::withMessages(['user' => $problem]);
            }
            $pending = $this->pending($user);
            $today = now()->toDateString();

            $user->forceFill(['deactivated_at' => now(), 'deactivated_by' => $by->id, 'deactivation_reason' => mb_strimwidth($reason, 0, 300, '…')])->save();
            $user->tokens()->delete();

            // Fuera del roster de hoy (y de los días siguientes, si ya los hubiera), con su línea en el historial del roster
            foreach (DailyAgentRoster::where('agent_id', $user->id)->where('date', '>=', $today)->where('is_active', true)->get() as $row) {
                $row->update(['is_active' => false]);
                RosterChange::create(['date' => $row->date, 'shop_id' => $row->shop_id, 'agent_id' => $user->id, 'is_active' => false,
                    'reason' => 'Cuenta desactivada: ' . mb_strimwidth($reason, 0, 150, '…'), 'changed_by' => $by->id, 'changed_by_role' => $by->role?->description ?? 'Admin']);
            }
            // Fuera de las rondas de Logística propia; al reactivarlo vuelve si estaba
            $riderWasActive = RiderProfile::where('user_id', $user->id)->where('is_active', true)->update(['is_active' => false]) > 0;

            UserAccountChange::create([
                'user_id' => $user->id,
                'action' => UserAccountChange::DEACTIVATED,
                'reason' => mb_strimwidth($reason, 0, 300, '…'),
                'details' => ['role' => $user->role?->description, 'pending' => array_column($pending, 'text'), 'rider_profile_was_active' => $riderWasActive],
                'changed_by' => $by->id,
            ]);

            return $user->fresh(['role', 'deactivator']);
        });
    }

    public function reactivate(User $target, User $by): User
    {
        return DB::transaction(function () use ($target, $by) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail()->load('role');
            if (!$user->isDeactivated()) {
                throw ValidationException::withMessages(['user' => 'La cuenta de ' . $this->name($user) . ' ya está activa.']);
            }
            $last = UserAccountChange::where('user_id', $user->id)->where('action', UserAccountChange::DEACTIVATED)->orderByDesc('id')->first();

            $user->forceFill(['deactivated_at' => null, 'deactivated_by' => null, 'deactivation_reason' => null])->save();
            if ($last?->details['rider_profile_was_active'] ?? false) {
                RiderProfile::where('user_id', $user->id)->update(['is_active' => true]);
            }
            UserAccountChange::create(['user_id' => $user->id, 'action' => UserAccountChange::REACTIVATED, 'changed_by' => $by->id]);

            return $user->fresh(['role']);
        });
    }

    /** Sus desactivaciones y reactivaciones, de la más nueva a la más vieja. */
    public function history(User $target, int $limit = 20): array
    {
        return UserAccountChange::where('user_id', $target->id)->with('changer:id,names,surnames')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (UserAccountChange $c) => [
                'action' => $c->action,
                'label' => $c->action === UserAccountChange::DEACTIVATED ? 'Desactivada' : 'Reactivada',
                'reason' => $c->reason,
                'by' => $c->changer ? PersonName::of($c->changer->names, $c->changer->surnames) : null,
                'at' => $c->created_at?->toIso8601String(),
            ])->all();
    }

    /** Las ciudades donde es la única agencia que recibe órdenes (sin ella, se quedan sin agencia). @return string[] */
    private function citiesLeftWithout(User $agency): array
    {
        $cityIds = DB::table('city_agency')->where('agency_id', $agency->id)->where('is_active', true)->pluck('city_id');
        if ($cityIds->isEmpty()) {
            return [];
        }
        $covered = DB::table('city_agency as ca')->join('users as u', 'u.id', '=', 'ca.agency_id')
            ->whereIn('ca.city_id', $cityIds)->where('ca.is_active', true)->where('ca.agency_id', '!=', $agency->id)->whereNull('u.deactivated_at')
            ->distinct()->pluck('ca.city_id');

        return City::whereIn('id', $cityIds->diff($covered))->orderBy('name')->pluck('name')->all();
    }

    private function name(User $user): string
    {
        return PersonName::of($user->names, $user->surnames);
    }

    private static function plural(int $n, string $one, string $many, bool $prefix = true): string
    {
        $text = $n === 1 ? "1 {$one}" : "{$n} {$many}";

        return $prefix ? "Tiene {$text}" : $text;
    }

    private static function usd(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    /** "A", "A y B", "A, B y C". */
    private static function listing(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' y ' . $last;
    }
}
