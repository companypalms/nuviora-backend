<?php

namespace App\Services;

use App\Models\User;
use App\Models\BusinessDay;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CrmAssignmentService
{
    /**
     * Returns true if at least one shop has an open BusinessDay record today.
     */
    public static function isAnyShopOpen(): bool
    {
        return BusinessDay::where('date', now()->toDateString())
            ->whereNotNull('open_at')
            ->whereNull('close_at')
            ->exists();
    }

    /** La vendedora del cliente ya no trabaja (cuenta desactivada, paso 6): el lead se vuelve a asignar. */
    public static function agentIsGone(?int $agentId): bool
    {
        return $agentId !== null && User::whereKey($agentId)->whereNotNull('deactivated_at')->exists();
    }

    public static function assignNextAgent($client)
    {
        // If already assigned, do nothing (salvo que su vendedora esté desactivada: entonces va a otra del turno)
        if ($client->agent_id) {
            $current = User::find($client->agent_id);
            if ($current && !$current->isDeactivated()) {
                return $current;
            }
        }

        // 🛑 NO ASIGNAR SI TODAS LAS TIENDAS ESTÁN CERRADAS
        // Esto previene asignaciones por mensajes de WhatsApp fuera de horario.
        if (!self::isAnyShopOpen()) {
            Log::info("CrmAssignmentService: Todas las tiendas están cerradas. Lead #{$client->id} ({$client->phone}) no se asigna fuera de horario.");
            return null;
        }

        // Obtener vendedores que estén activos HOY en cualquier tienda, basándonos en el Roster Oficial
        $activeRosters = \App\Models\DailyAgentRoster::with('agent.role')
            ->where('date', now()->toDateString())
            ->where('is_active', true)
            ->get();
            
        // Extraer los agentes únicos con su rol permitido
        $activeAgents = $activeRosters->pluck('agent')->filter(function ($agent) {
            if (!$agent || !$agent->role || $agent->isDeactivated()) return false;
            return in_array($agent->role->description, ['Vendedor', 'Gerente', 'Admin', 'Master']);
        })->unique('id')->values();

        if ($activeAgents->isEmpty()) {
            Log::warning("CrmAssignmentService: No active agents found for assignment. Lead #{$client->id} remains unassigned.");
            return null;
        }

        // Round Robin index management
        $lastIndex = Cache::get('crm_assignment_last_index', -1);
        $nextIndex = ($lastIndex + 1) % $activeAgents->count();
        
        $agent = $activeAgents[$nextIndex];
        
        $client->update(['agent_id' => $agent->id]);

        // Sync active conversation if it exists
        \App\Models\WhatsappConversation::where('client_id', $client->id)
            ->where('status', 'open')
            ->update(['agent_id' => $agent->id]);
        
        Cache::forever('crm_assignment_last_index', $nextIndex);
        
        Log::info("CrmAssignmentService: Assigned Lead #{$client->id} ({$client->phone}) to Agent #{$agent->id} ({$agent->names}) [Index: {$nextIndex}]");
        
        return $agent;
    }
}
