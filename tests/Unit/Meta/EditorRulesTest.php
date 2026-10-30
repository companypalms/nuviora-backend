<?php

use App\Services\Meta\Queue\EditorRules;
use App\Services\Meta\Queue\QueueRules;

// Etapa 5, paso 5a: quién puede hacer qué con cada encargo, los enlaces de Drive y lo pendiente de cada editor.

const DUENO = ['id' => 1, 'role' => 'Admin'];
const LIDER = ['id' => 10, 'role' => 'Editor líder'];
const EDITOR = ['id' => 20, 'role' => 'Editor de video'];
const OTRO_EDITOR = ['id' => 21, 'role' => 'Editor de video'];
const VENDEDORA = ['id' => 30, 'role' => 'Vendedor'];

function encargo(string $status, ?int $editor = null, ?int $pendingBy = null): array
{
    return ['status' => $status, 'editor_id' => $editor, 'pending_by' => $pendingBy];
}

describe('Quién ve cada encargo', function () {
    it('el editor ve lo suyo; el líder y el dueño, todo lo aprobado; nadie de fuera ve nada', function () {
        expect(EditorRules::canSee(EDITOR, encargo('production', 20)))->toBeTrue()
            ->and(EditorRules::canSee(EDITOR, encargo('production', 21)))->toBeFalse()
            ->and(EditorRules::canSee(EDITOR, encargo('approved')))->toBeFalse()
            ->and(EditorRules::canSee(LIDER, encargo('approved')))->toBeTrue()
            ->and(EditorRules::canSee(LIDER, encargo('review', 21)))->toBeTrue()
            ->and(EditorRules::canSee(DUENO, encargo('proposed')))->toBeTrue()
            ->and(EditorRules::canSee(VENDEDORA, encargo('approved', 30)))->toBeFalse();
    });

    it('lo que el dueño todavía no aprobó no lo ve ningún editor', function () {
        expect(EditorRules::canSee(LIDER, encargo('proposed')))->toBeFalse()
            ->and(EditorRules::canSee(EDITOR, encargo('proposed', 20)))->toBeFalse();
    });
});

describe('Qué puede hacer cada uno', function () {
    it('asignan el líder y el dueño, mientras no se haya entregado', function () {
        foreach (['approved', 'production'] as $s) {
            expect(EditorRules::can('assign', LIDER, encargo($s, 20)))->toBeTrue()
                ->and(EditorRules::can('assign', DUENO, encargo($s)))->toBeTrue();
        }
        expect(EditorRules::can('assign', LIDER, encargo('review', 20)))->toBeFalse()
            ->and(EditorRules::can('assign', LIDER, encargo('bank', 20)))->toBeFalse()
            ->and(EditorRules::can('assign', EDITOR, encargo('approved')))->toBeFalse()
            ->and(EditorRules::can('assign', VENDEDORA, encargo('approved')))->toBeFalse();
    });

    it('empieza y entrega solo el editor al que se lo asignaron (el líder también, con lo suyo)', function () {
        expect(EditorRules::can('start', EDITOR, encargo('approved', 20)))->toBeTrue()
            ->and(EditorRules::can('start', EDITOR, encargo('production', 20)))->toBeFalse()
            ->and(EditorRules::can('start', OTRO_EDITOR, encargo('approved', 20)))->toBeFalse()
            ->and(EditorRules::can('start', LIDER, encargo('approved', 20)))->toBeFalse()
            ->and(EditorRules::can('start', LIDER, encargo('approved', 10)))->toBeTrue()
            ->and(EditorRules::can('deliver', EDITOR, encargo('production', 20)))->toBeTrue()
            ->and(EditorRules::can('deliver', EDITOR, encargo('approved', 20)))->toBeTrue()
            ->and(EditorRules::can('deliver', EDITOR, encargo('review', 20)))->toBeFalse()
            ->and(EditorRules::can('deliver', DUENO, encargo('production', 1)))->toBeFalse();
    });

    it('revisa el líder lo de los demás; lo del líder, solo el dueño', function () {
        expect(EditorRules::can('review', LIDER, encargo('review', 20, 20)))->toBeTrue()
            ->and(EditorRules::can('review', LIDER, encargo('review', 10, 10)))->toBeFalse()
            ->and(EditorRules::can('review', DUENO, encargo('review', 10, 10)))->toBeTrue()
            ->and(EditorRules::can('review', EDITOR, encargo('review', 21, 21)))->toBeFalse()
            ->and(EditorRules::can('review', EDITOR, encargo('review', 20, 20)))->toBeFalse();
    });

    it('sin una entrega pendiente no hay nada que revisar', function () {
        expect(EditorRules::can('review', DUENO, encargo('review', 20)))->toBeFalse()
            ->and(EditorRules::can('review', LIDER, encargo('production', 20, 20)))->toBeFalse()
            ->and(EditorRules::can('volar', DUENO, encargo('approved')))->toBeFalse();
    });
});

describe('El enlace de Drive de cada pieza', function () {
    $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz_0123';

    it('acepta los enlaces de archivo que da Drive en la computadora y en el teléfono', function () use ($id) {
        foreach ([
            "https://drive.google.com/file/d/{$id}/view?usp=sharing",
            "https://drive.google.com/file/d/{$id}/view?usp=drive_link",
            "https://drive.google.com/file/d/{$id}/view?usp=drivesdk",
            "https://drive.google.com/file/d/{$id}",
            "https://drive.google.com/open?id={$id}",
            "https://drive.google.com/uc?id={$id}&export=download",
            "https://docs.google.com/file/d/{$id}/edit",
            "  https://drive.google.com/file/d/{$id}/view  ",
        ] as $url) {
            expect(EditorRules::driveFileId($url))->toBe($id);
        }
    });

    it('no acepta carpetas, otras páginas ni enlaces sin https', function () use ($id) {
        foreach ([
            "https://drive.google.com/drive/folders/{$id}",
            "http://drive.google.com/file/d/{$id}/view",
            "https://drive.google.com.evil.com/file/d/{$id}/view",
            "https://www.dropbox.com/s/{$id}/video.mp4",
            'https://drive.google.com/file/d/corto/view',
            'https://drive.google.com/open?id[]=x',
            'C0457.mp4',
            '',
            null,
        ] as $url) {
            expect(EditorRules::driveFileId($url))->toBeNull();
        }
    });

    it('dice qué hacer cuando pegan una carpeta', function () use ($id) {
        expect(EditorRules::linkProblem("https://drive.google.com/drive/folders/{$id}"))->toContain('carpeta')
            ->and(EditorRules::linkProblem('hola'))->toContain('Copiar enlace');
    });
});

describe('Versiones, plazo y pendientes', function () {
    it('las versiones son V01, V02…', function () {
        expect(EditorRules::version(1))->toBe('V01')->and(EditorRules::version(12))->toBe('V12');
    });

    it('a tiempo es hasta el final del día del plazo', function () {
        expect(EditorRules::onTime('2026-10-31 23:59:00', '2026-10-31'))->toBeTrue()
            ->and(EditorRules::onTime('2026-10-30 09:00:00', '2026-10-31'))->toBeTrue()
            ->and(EditorRules::onTime('2026-11-01 00:10:00', '2026-10-31'))->toBeFalse()
            ->and(EditorRules::onTime(null, '2026-10-31'))->toBeNull()
            ->and(EditorRules::onTime('2026-10-30 09:00:00', null))->toBeNull();
    });

    it('el número del menú: lo suyo por hacer y, el líder, lo que falta asignar y revisar', function () {
        $cola = [
            encargo('approved'),               // sin asignar
            encargo('approved', 20),           // del editor, por empezar
            encargo('production', 20),         // del editor, en producción (o devuelto)
            encargo('review', 20, 20),         // entregó el editor: lo revisa el líder
            encargo('production', 10),         // del líder
            encargo('review', 10, 10),         // entregó el líder: lo revisa el dueño
            encargo('bank', 20),               // terminado
        ];
        expect(EditorRules::pending(EDITOR, $cola))->toBe(['todo' => 2, 'to_assign' => 0, 'to_review' => 0, 'total' => 2])
            ->and(EditorRules::pending(LIDER, $cola))->toBe(['todo' => 1, 'to_assign' => 1, 'to_review' => 1, 'total' => 3]);
    });
});

describe('El encargo en texto', function () {
    it('lleva los enlaces de la entrega y el editor, para subirlo a Meta', function () {
        $b = ['code' => 'B0100', 'product' => 'FlexiStep', 'urgency' => 1, 'kind' => 'iteration', 'reference' => 'C004 GINE', 'angle_code' => 'GINE',
            'angle_name' => null, 'format' => 'video', 'due_date' => '2026-10-31', 'deliverables' => null, 'editor' => 'Ana Pérez',
            'pieces' => [['code' => 'C0100', 'change' => 'Hook 1', 'format' => null, 'link' => 'https://drive.google.com/file/d/abc/view', 'hook' => '¿Te duele?'],
                ['code' => 'C0101', 'change' => 'Hook 2', 'format' => null, 'link' => null, 'hook' => null]],
            'meta_names' => QueueRules::metaNames(100, 'GINE', [100, 101])];
        $text = QueueRules::brief($b);
        expect($text)->toContain("Archivos (Drive):\n- C0100: https://drive.google.com/file/d/abc/view — hook: ¿Te duele?")
            ->not->toContain('- C0101:')
            ->toContain('Editor: Ana Pérez');
        expect(QueueRules::brief(array_replace($b, ['editor' => null, 'pieces' => [['code' => 'C0100', 'change' => 'Hook 1', 'format' => null]]])))
            ->not->toContain('Archivos')->not->toContain('Editor:');
    });
});
