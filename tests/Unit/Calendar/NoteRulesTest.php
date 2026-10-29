<?php

use App\Services\Calendar\Rules\NoteRules;

// Calendario del dueño (Etapa 3): lo que se revisa al anotar algo y cómo se lee.

$note = fn (array $over = []) => NoteRules::normalize($over + [
    'title' => 'Pagar el alquiler', 'date' => '2026-11-05', 'kind' => 'payment', 'repeat' => 'monthly',
]);

describe('Notas del calendario', function () use ($note) {
    it('limpia lo que llega: espacios de más, vacío = nada, y sin "hasta" si no se repite', function () {
        $n = NoteRules::normalize(['title' => '  Llamar al banco ', 'details' => '  ', 'date' => '2026-11-05', 'time' => '',
            'repeat' => 'none', 'repeat_until' => '2026-12-01']);
        expect($n)->toBe([
            'title' => 'Llamar al banco', 'details' => null, 'date' => '2026-11-05', 'time' => null,
            'kind' => 'task', 'repeat' => 'none', 'repeat_until' => null,
        ]);
    });

    it('una nota bien escrita se puede guardar', function () use ($note) {
        expect(NoteRules::problems($note()))->toBe([]);
        expect(NoteRules::problems($note(['time' => '09:30', 'repeat_until' => '2027-05-05'])))->toBe([]);
    });

    it('avisa qué casilla está mal, en palabras simples', function () use ($note) {
        expect(NoteRules::problems($note(['title' => ' '])))->toBe(['title' => 'Escribe qué hay que hacer.']);
        expect(NoteRules::problems($note(['title' => str_repeat('a', 151)])))->toHaveKey('title');
        expect(NoteRules::problems($note(['date' => '2026-02-30'])))->toBe(['date' => 'Elige la fecha.']);
        expect(NoteRules::problems($note(['time' => '25:00'])))->toBe(['time' => 'La hora no es válida.']);
        expect(NoteRules::problems($note(['kind' => 'otro'])))->toBe(['kind' => 'Elige si es una tarea o un pago.']);
        expect(NoteRules::problems($note(['repeat' => 'daily'])))->toBe(['repeat' => 'Elige si se repite.']);
        expect(NoteRules::problems($note(['repeat_until' => '2026-11-01'])))
            ->toBe(['repeat_until' => '"Hasta" no puede ser antes de la fecha de la nota.']);
    });

    it('se lee en una frase', function () use ($note) {
        expect(NoteRules::text($note()))->toBe('Pago: Pagar el alquiler.');
        expect(NoteRules::text($note(['kind' => 'task', 'title' => 'Llamar al banco', 'time' => '10:00'])))->toBe('Tarea: Llamar al banco, a las 10:00.');
    });
});
