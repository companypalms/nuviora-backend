<?php

use App\Services\Meta\Upload\UploadRules;

// "Subir a Meta" (`.57`/`.58`, decisiones del dueño del 2026-10-31): nombres, parámetros de URL y Drive.

it('los nombres como los pone el dueño: código + ángulo', function () {
    expect(UploadRules::adsetName('B0123', 'fasp'))->toBe('B0123 FASP');
    expect(UploadRules::adName('C0457', 'FASP'))->toBe('C0457 FASP');
});

it('los anuncios se crean encendidos (decisión del dueño)', function () {
    expect(UploadRules::STATUS)->toBe('ACTIVE');
});

describe('los parámetros de URL', function () {
    it('sin nada, los de la .53', function () {
        expect(UploadRules::urlTags(null))->toBe('utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}');
    });

    it('con otros de la referencia: se conservan, y los utm quedan los de la .53', function () {
        $t = UploadRules::urlTags('?utm_source=fb&ref=abc&utm_content=viejo&campaign_name={{campaign.name}}');
        expect($t)->toBe('utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.id}}&utm_term={{adset.id}}&utm_content={{ad.id}}&ref=abc&campaign_name={{campaign.name}}');
    });
});

describe('Drive', function () {
    it('el enlace de descarga directa', function () {
        expect(UploadRules::driveDownloadUrl('1AbCdEfGhIjKlMnOpQrStUvWxYz'))->toBe('https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOpQrStUvWxYz');
    });

    it('lee lo que contesta Drive', function () {
        expect(UploadRules::driveAnswer(206, 'video/mp4', "\x00\x00\x00 ftypmp42")['ok'])->toBeTrue();
        $private = UploadRules::driveAnswer(200, 'text/html; charset=utf-8', '<html>... https://accounts.google.com/ServiceLogin ...');
        expect($private['ok'])->toBeFalse()->and($private['note'])->toContain('cualquiera con el enlace');
        expect(UploadRules::driveAnswer(200, 'text/html', '<form action="...&confirm=t"> Google Drive can\'t scan this file for viruses')['ok'])->toBeTrue();
        expect(UploadRules::driveAnswer(404, 'text/html', '')['note'])->toContain('no existe');
        expect(UploadRules::driveAnswer(200, 'text/html', '<html>otra página</html>')['ok'])->toBeFalse();
    });
});

it('el presupuesto de la campaña (CBO), de lo que guarda Meta en centavos', function () {
    expect(UploadRules::campaignBudget(['daily_budget' => '3000']))->toBe(30.0);
    expect(UploadRules::campaignBudget(['lifetime_budget' => '150000']))->toBe(1500.0);
    expect(UploadRules::campaignBudget(null))->toBeNull();
    expect(UploadRules::campaignBudget(['bid_strategy' => 'LOWEST_COST']))->toBeNull();
});
