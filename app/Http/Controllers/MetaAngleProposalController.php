<?php

namespace App\Http\Controllers;

use App\Models\AngleProposal;
use App\Services\Meta\Products\AngleProposals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** `.116`: el ángulo que la IA le propone al dueño, en su calendario, y lo que él decide. Solo el Administrador. */
class MetaAngleProposalController extends Controller
{
    public function __construct(private AngleProposals $proposals)
    {
    }

    public function show(AngleProposal $proposal)
    {
        return response()->json($this->proposals->show($proposal));
    }

    public function decide(Request $request, AngleProposal $proposal)
    {
        $data = $request->validate([
            'choice' => ['required', Rule::in(AngleProposal::CHOICES)],
            'name' => 'required_if:choice,other|nullable|string|max:120',
            'about' => 'nullable|string|max:1000',
            'code' => 'required_if:choice,existing|nullable|string|max:20',
        ], ['choice.required' => 'Elige una opción.', 'name.required_if' => 'Escribe cuál es el ángulo.', 'code.required_if' => 'Elige uno de los ángulos.']);
        $angle = $this->proposals->decide($proposal, $data, Auth::user());

        return response()->json(['message' => "Listo: el siguiente encargo será del ángulo {$angle}."]);
    }
}
