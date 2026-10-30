<?php

use App\Services\Meta\AngleProposer;
use App\Services\Meta\CreativeTrackingId;
use App\Services\Meta\MetaCreativeLinker;

// Etapa 5, paso 1b: los nombres reales de producción (2026-10-30) y de los análisis del dueño.

const P_FLEX = 'FlexiStep® Tobillera de compresion profesional';
const P_COMP = 'Comprimax - Chaleco termico';
const P_CERV = 'Cervimax® Alivia desde el primer uso';
const P_ZEN = 'ZenPulse™ Alivio inmediato';
const P_SOLO = 'Solo Brillo® Elimina las manchas mas dificiles';

describe('Nombres de anuncios', function () {
    it('lee creativo, ángulo, batch, referencia e iteración', function (string $name, string $product, ?string $code, ?string $creative, ?string $angle, ?string $batch, ?string $ref, ?string $parent) {
        $p = CreativeTrackingId::parse($name, [$product]);
        expect($p['code'])->toBe($code)
            ->and($p['creative'])->toBe($creative)
            ->and($p['angle'])->toBe($angle)
            ->and($p['batch'])->toBe($batch)
            ->and($p['ref'])->toBe($ref)
            ->and($p['iteration_of'])->toBe($parent);
    })->with([
        ['CRE001-FLEX-FAS-BATCH004', P_FLEX, 'CRE001-FAS-B004', 'CRE001', 'FAS', 'B004', null, null],
        ['CRE003-FLEX-FAS-BATCH005', P_FLEX, 'CRE003-FAS-B005', 'CRE003', 'FAS', 'B005', null, null],
        ['CRE001-COM-GINE-BATCH003-REF001 - Copia', P_COMP, 'CRE001-GINE-B003-REF001', 'CRE001', 'GINE', 'B003', 'REF001', null],
        ['CRE002-COM-GINE-BATCH003-REF002', P_COMP, 'CRE002-GINE-B003-REF002', 'CRE002', 'GINE', 'B003', 'REF002', null],
        ['CRE001-CERV-NERV-REF008- BATCH005', P_CERV, 'CRE001-NERV-B005-REF008', 'CRE001', 'NERV', 'B005', 'REF008', null],
        ['CREATIVO 6 27/10/2026', P_ZEN, 'CREATIVO6-27-10-2026', 'CREATIVO6', null, null, null, null],
        ['C005 FASP', P_FLEX, 'C005-FASP', 'C005', 'FASP', null, null, null],
        ['ITER3-C003 AGDU', P_SOLO, 'ITER3-C003-AGDU', 'ITER3-C003', 'AGDU', null, null, 'C003'],
        ['C004 NERV REF002', P_CERV, 'C004-NERV-REF002', 'C004', 'NERV', null, 'REF002', null],
        ['C0457 FASP', P_FLEX, 'C0457-FASP', 'C0457', 'FASP', null, null, null],
        ['C004-COMP-GINE-UGC-H1 – Copy 2', P_COMP, 'C004-GINE-UGC-H1', 'C004', 'GINE', null, null, null],
        ['Nuevo anuncio de Ventas', P_COMP, null, null, null, null, null, null],
        ['CERVIMAX | CCS | TEST', P_CERV, null, null, null, null, null, null],
    ]);

    it('el mismo anuncio con otro separador, en otra ciudad o copiado es el mismo creativo', function () {
        $a = CreativeTrackingId::parse('CRE001-CERV-NERV-REF008- BATCH005', [P_CERV])['code'];
        expect(CreativeTrackingId::parse('CRE001-CERV-NERV-REF008-BATCH005', [P_CERV])['code'])->toBe($a)
            ->and(CreativeTrackingId::parse('cre001 cerv nerv ref008 batch005 - Copia', [P_CERV])['code'])->toBe($a)
            ->and(CreativeTrackingId::parse('CRE001-CERV-NERV-REF008-BATCH005 CCS', [P_CERV])['code'])->toBe($a);
    });

    it('el mismo número en otro batch es otro creativo', function () {
        expect(CreativeTrackingId::parse('CRE001-FLEX-FAS-BATCH004', [P_FLEX])['code'])
            ->not->toBe(CreativeTrackingId::parse('CRE001-FLEX-FAS-BATCH005', [P_FLEX])['code']);
    });

    it('sin el producto, la parte del producto no se toma como ángulo de otro', function () {
        // Sin clasificar la campaña, COMP parece un ángulo; al clasificarla se vuelve a leer
        expect(CreativeTrackingId::parse('C004-COMP-GINE', [])['angle'])->toBe('COMP')
            ->and(CreativeTrackingId::parse('C004-COMP-GINE', [P_COMP])['angle'])->toBe('GINE');
    });

    it('reconoce el producto también por la primera palabra de la campaña', function () {
        expect(CreativeTrackingId::parse('CRE001-ZEN-ANS-BATCH001', ['Alivio inmediato', 'ZENPULSE']))->toMatchArray(['product_token' => 'ZEN', 'angle' => 'ANS'])
            ->and(CreativeTrackingId::parse('CRE001-ZEN-ANS-BATCH001', ['Alivio inmediato'])['angle'])->toBe('ZEN');
    });

    it('quita lo que agrega Meta al duplicar', function () {
        expect(CreativeTrackingId::label('BATCH 005 - FASP -CONCEPTO OPEN | TEST - - Copia'))->toBe('BATCH 005 - FASP -CONCEPTO OPEN | TEST')
            ->and(CreativeTrackingId::label('C004 NERV – Copy 2'))->toBe('C004 NERV')
            ->and(CreativeTrackingId::label('  C004   NERV  '))->toBe('C004 NERV')
            ->and(CreativeTrackingId::label('ANUNCIO DE ALGODÓN'))->toBe('ANUNCIO DE ALGODÓN');
    });
});

describe('Nombres de ad sets', function () {
    it('lee batch, ángulo, concepto y etapa', function (string $name, ?string $batch, ?string $angle, ?string $concept, ?string $stage) {
        expect(CreativeTrackingId::parseAdset($name, [P_FLEX]))->toBe(['batch' => $batch, 'angle' => $angle, 'concept' => $concept, 'stage' => $stage]);
    })->with([
        ['BATCH 003- GINE -CONCEPTO OPEN | TEST - Copia', 'B003', 'GINE', 'OPEN', 'test'],
        ['BATCH 005 | NERV | BROAD | PURCHASE - Copia', 'B005', 'NERV', 'BROAD', null],
        ['BATCH 005 - FASP -CONCEPTO OPEN | TEST - - Copia', 'B005', 'FASP', 'OPEN', 'test'],
        ['BATCH 002| SIN ANGULO | BROAD | PURCHASE TESTEO', 'B002', null, 'BROAD', 'test'],
        ['BATCH 002 nuevos- GINE -CONCEPTO OPEN | TEST', 'B002', 'GINE', 'OPEN', 'test'],
        ['BATCH 005 - AGDU -OPEN | TEST', 'B005', 'AGDU', 'OPEN', 'test'],
        ['BATCH 001.1 - GORD -CONCEPTO OPEN | TEST - Copia', 'B001.1', 'GORD', 'OPEN', 'test'],
        ['BATCH 001 - DISI -CONCEPTO OPEN | TEST', 'B001', 'DISI', 'OPEN', 'test'],
        ['B0123 FASP', 'B0123', 'FASP', null, null],
        ['Batch 002 NERV', 'B002', 'NERV', null, null],
        ['NERV Broad', null, 'NERV', 'BROAD', null],
        ['Open Test', null, null, 'OPEN', 'test'],
        ['Andrómeda 2708', null, null, 'ANDROMEDA', null],
        ['FLEXISTEP | CCS | PROVEN', null, null, null, 'proven'],
    ]);
});

describe('Ángulos que son lo mismo', function () {
    it('el código del anuncio que es el comienzo del de su ad set es un alias', function () {
        expect(MetaCreativeLinker::aliases([['FAS', 'FASP'], ['FAS', 'FASP']], []))->toBe(['FAS' => 'FASP']);
    });

    it('también al revés, y las cadenas terminan en el más largo', function () {
        expect(MetaCreativeLinker::aliases([['FASP', 'FAS'], ['FA', 'FAS']], []))->toBe(['FAS' => 'FASP', 'FA' => 'FASP']);
    });

    it('no une códigos que no empiezan igual ni uno que ya estaba en el diccionario', function () {
        expect(MetaCreativeLinker::aliases([['NERV', 'GINE']], []))->toBe([])
            ->and(MetaCreativeLinker::aliases([['FAS', 'FASP']], ['FAS']))->toBe([]);
    });

    it('cada código se lee como el que vale solo', function () {
        $rows = [(object) ['id' => 1, 'code' => 'FASP', 'alias_of' => null], (object) ['id' => 2, 'code' => 'FAS', 'alias_of' => 1],
            (object) ['id' => 3, 'code' => 'NERV', 'alias_of' => null]];
        expect(MetaCreativeLinker::resolveMap($rows))->toBe(['FASP' => 'FASP', 'FAS' => 'FASP', 'NERV' => 'NERV']);
    });

    it('la identidad del creativo es producto + código', function () {
        expect(MetaCreativeLinker::creativeKey(7, 'C004-NERV'))->toBe('7:C004-NERV')
            ->and(MetaCreativeLinker::creativeKey(null, 'C004-NERV'))->toBe('0:C004-NERV');
    });
});

describe('Lo que propone la IA', function () {
    it('se queda solo con los códigos pedidos y con alias que existen', function () {
        $clean = AngleProposer::clean(['angulos' => [
            ['codigo' => 'fas', 'nombre' => 'Dolor al caminar', 'hipotesis' => 'H', 'igual_a' => 'FASP'],
            ['codigo' => 'NERV', 'nombre' => 'Nervio', 'hipotesis' => 'H2', 'igual_a' => 'XYZ'],
            ['codigo' => 'OTRO', 'nombre' => 'No pedido', 'hipotesis' => '', 'igual_a' => ''],
            ['codigo' => 'GINE', 'nombre' => 'Gine', 'hipotesis' => 'H3', 'igual_a' => 'GINE'],
        ]], ['FAS', 'NERV', 'GINE'], ['FAS', 'FASP', 'NERV', 'GINE']);
        expect(array_keys($clean))->toBe(['FAS', 'NERV', 'GINE'])
            ->and($clean['FAS']['alias'])->toBe('FASP')
            ->and($clean['NERV']['alias'])->toBeNull()
            ->and($clean['GINE']['alias'])->toBeNull();
    });

    it('le manda los textos de cada código', function () {
        $input = AngleProposer::input('FlexiStep', ['FASP' => ['aliases' => ['FAS'], 'names' => ['C005 FASP'], 'texts' => ['¿Te duele el tobillo?']]], ['FASP', 'NERV']);
        expect($input)->toContain('Producto: FlexiStep')->toContain('FASP, NERV')->toContain('también escrito FAS')->toContain('¿Te duele el tobillo?');
    });
});
