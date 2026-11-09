<?php

use App\Services\Meta\CampaignNameTargets;

// .79 (dueño, 2026-11-02): "en la campaña yo siempre pongo el CPA referencial": para un producto nuevo vale hasta que el
// sistema tenga su número real. Los nombres son los de sus campañas.

it('lee el CPA y el BE que el dueño escribe en el nombre de sus campañas', function (string $name, float $c, ?float $be) {
    expect(CampaignNameTargets::parse($name))->toBe(['target_cpa' => $c, 'break_even_cpa' => $be]);
})->with([
    'Termafit' => ['TERMAFIT | MCAY |  CBO | HV | CPA $4| BE $5.6| 29/10/2026', 4.0, 5.6],
    'ZenPulse' => ['ZENPULSE | CSS | TEST | CBO | HV | CPA OBJ $4 | BE $5.5 | 27/10/2026', 4.0, 5.5],
    'Solobrillo' => ['SOLOBRILLO | CBOBO | CBO | HV | CPA 3.7 | BE 7.8', 3.7, 7.8],
    'FlexiStep' => ['FLEXISTEP | CCS | CBO | HV | CPA $3.1 | BE $4.7 | 21/09/2026', 3.1, 4.7],
    'con coma' => ['NUEVO | CPA $4,5 | BE $6,2', 4.5, 6.2],
    'sin BE' => ['NUEVO | CBOBO | CPA $4', 4.0, null],
    // .122: como lo escribió en producción el 6 y el 7 de noviembre
    'ResistBarPro (CPABE)' => ['Resistbarpro / Caracas /  CPA 4$ / CPABE 5.5$ / 06-11-2026', 4.0, 5.5],
    'Releev (CPABE sin barra)' => ['Releev / Maracay / CPA 4$ CPABE 5.6$ / 07-11-2026', 4.0, 5.6],
    'CPA BE con espacio' => ['NUEVO / CPA 4 / CPA BE 5.5', 4.0, 5.5],
    'CPA_BE' => ['NUEVO / CPA 4 / CPA_BE 5,5', 4.0, 5.5],
    'el BE antes que el CPA' => ['NUEVO / CPABE 5.5$ / CPA 4$', 4.0, 5.5],
]);

it('sin CPA en el nombre no hay referencia', function () {
    expect(CampaignNameTargets::parse('COMPRIMAX | CBOBO | CBO | HV | 21/09/2026'))->toBeNull()
        ->and(CampaignNameTargets::parse('CPA $0 | BE $5'))->toBeNull()
        ->and(CampaignNameTargets::parse(null))->toBeNull()
        // .122: "CPABE" solo no es el CPA objetivo
        ->and(CampaignNameTargets::parse('NUEVO / CPABE 5.5$ / 06-11-2026'))->toBeNull();
});
