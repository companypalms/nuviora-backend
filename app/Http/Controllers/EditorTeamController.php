<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\EditorTeam;
use App\Services\Meta\Queue\EditorWork;
use App\Services\Meta\Queue\QueueProposer;
use Illuminate\Http\Request;

/**
 * `.175`: el horario y los días de permiso de los editores (el del líder lo pone el dueño; el de sus editores, el líder) y
 * "Adelantar" del líder. Las mismas acciones desde /meta (el dueño) y desde /editor (el líder); quién puede qué lo dice
 * EditorPaceRules::canEdit.
 */
class EditorTeamController extends Controller
{
    /** Los editores con su horario y cómo va cada uno hoy (la tarjeta del dueño en Cola y banca). */
    public function index(Request $request)
    {
        $today = collect(EditorTeam::today())->keyBy('id');

        return response()->json([
            'editors' => array_map(fn ($e) => $e + ($today[$e['id']] ?? []), EditorTeam::listFor($request->user())),
            'goal' => EditorTeam::goal(),
        ]);
    }

    public function schedule(Request $request, User $user)
    {
        $data = $request->validate([
            'days' => 'required|array|min:1|max:7',
            'days.*' => 'integer|between:0,6',
            'start' => 'required|string|size:5',
            'end' => 'required|string|size:5',
        ], ['days.required' => 'Elige al menos un día.', 'days.min' => 'Elige al menos un día.']);
        EditorTeam::setSchedule($request->user(), $user, $data['days'], $data['start'], $data['end']);

        return $this->index($request);
    }

    public function dayOff(Request $request, User $user)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'off' => 'required|boolean', 'note' => 'nullable|string|max:160']);
        EditorTeam::setDayOff($request->user(), $user, $data['date'], (bool) $data['off'], $data['note'] ?? null);

        return $this->index($request);
    }

    /** "Adelantar": solo el editor líder, desde su pantalla. */
    public function advance(Request $request, QueueProposer $proposer)
    {
        abort_unless(EditorWork::who($request->user())['role'] === EditorRules::LEADER, 403, 'Solo el editor líder adelanta encargos.');
        $data = $request->validate(['product_id' => 'required|integer', 'editor_id' => 'nullable|integer']);
        $made = $proposer->advance((int) $data['product_id'], $request->user(), isset($data['editor_id']) ? (int) $data['editor_id'] : null);

        return response()->json(['made' => array_map(fn ($b) => ['id' => $b->id, 'code' => $b->code(), 'editor_id' => $b->editor_id], $made)]);
    }
}
