<?php

use App\Services\Meta\Analysis\UrgentList;
use App\Services\Meta\Upload\UploadRules;

// .155 (dueño, 2026-11-17: "que me aparezcan en mis misiones diarias, yo le doy un botón de aprobar y él los suba
// automáticamente en meta ads… en las campañas que ya están rodando ese producto en la ciudad correspondiente").

describe('Lo que sube una misión', function () {
    it('cada batch de la banca en la campaña de la misión, con el gasto mínimo de la .124', function () {
        expect(UrgentList::uploads(['B0123', 'B0124'], '120200000000001', [4.5, 'el CPA del nombre de la campaña']))->toBe([
            ['code' => 'B0123', 'campaign' => '120200000000001', 'min' => 4.5],
            ['code' => 'B0124', 'campaign' => '120200000000001', 'min' => 4.5],
        ])
            ->and(UrgentList::uploads(['B0123'], '120200000000001', null))->toBe([['code' => 'B0123', 'campaign' => '120200000000001', 'min' => null]])
            ->and(UrgentList::uploads(['B0123'], null, [4.5, 'x']))->toBe([])
            ->and(UrgentList::uploads([], '1', [4.5, 'x']))->toBe([]);
    });
});

describe('Las reglas de la subida', function () {
    it('el token puede subir solo con ads_management; si Meta no dijo sus permisos, no se sabe', function () {
        expect(UploadRules::canUpload(['ads_read', 'ads_management']))->toBeTrue()
            ->and(UploadRules::canUpload(['ads_read', 'read_insights']))->toBeFalse()
            ->and(UploadRules::canUpload(null))->toBeNull();
    });

    it('un ad set con el mismo código de batch ya es este batch (aunque Meta le agregue « - Copia»)', function () {
        expect(UploadRules::sameAdset('B0123 FASP', 'B0123 FASP'))->toBeTrue()
            ->and(UploadRules::sameAdset('b0123 FASP - Copia', 'B0123 FASP'))->toBeTrue()
            ->and(UploadRules::sameAdset('B0124 FASP', 'B0123 FASP'))->toBeFalse()
            ->and(UploadRules::sameAdset('B01234 FASP', 'B0123 FASP'))->toBeFalse();
    });

    it('el gasto mínimo va en centavos', function () {
        expect(UploadRules::cents(4.5))->toBe(450)->and(UploadRules::cents(5))->toBe(500)->and(UploadRules::cents(4.999))->toBe(500);
    });

    it('lo que dice Meta del video: listo, procesando o con error (y por qué)', function () {
        expect(UploadRules::videoState(['video_status' => 'ready']))->toBe(['state' => 'ready', 'note' => null])
            ->and(UploadRules::videoState(['video_status' => 'processing', 'processing_phase' => ['status' => 'in_progress']]))->toBe(['state' => 'processing', 'note' => null])
            ->and(UploadRules::videoState([]))->toBe(['state' => 'processing', 'note' => null])
            ->and(UploadRules::videoState(['video_status' => 'error', 'processing_phase' => ['status' => 'error', 'errors' => [['code' => 1, 'message' => 'Formato no soportado']]]]))
            ->toBe(['state' => 'error', 'note' => 'Formato no soportado']);
    });

    it('la miniatura que eligió Meta, o la primera', function () {
        expect(UploadRules::thumbnail([['uri' => 'a.jpg'], ['uri' => 'b.jpg', 'is_preferred' => true]]))->toBe('b.jpg')
            ->and(UploadRules::thumbnail([['uri' => 'a.jpg']]))->toBe('a.jpg')
            ->and(UploadRules::thumbnail([]))->toBeNull();
    });

    it('el botón y la página e Instagram se copian del anuncio de donde sale el texto', function () {
        $video = ['object_story_spec' => ['page_id' => '111', 'instagram_user_id' => '222', 'video_data' => ['call_to_action' => ['type' => 'ORDER_NOW']]]];
        $old = ['object_story_spec' => ['page_id' => '111', 'instagram_actor_id' => '333'], 'asset_feed_spec' => ['call_to_action_types' => ['LEARN_MORE']]];

        expect(UploadRules::ctaType($video))->toBe('ORDER_NOW')
            ->and(UploadRules::ctaType($old))->toBe('LEARN_MORE')
            ->and(UploadRules::ctaType([]))->toBe('SHOP_NOW')
            ->and(UploadRules::identity($video))->toBe(['page_id' => '111', 'instagram' => ['instagram_user_id', '222']])
            ->and(UploadRules::identity($old))->toBe(['page_id' => '111', 'instagram' => ['instagram_actor_id', '333']])
            ->and(UploadRules::identity([]))->toBe(['page_id' => null, 'instagram' => null]);
    });

    it('el creativo de un video: su página, su Instagram, el texto, la landing con su botón y los parámetros', function () {
        $source = ['object_story_spec' => ['page_id' => '111', 'instagram_user_id' => '222', 'video_data' => ['call_to_action' => ['type' => 'SHOP_NOW']]]];
        $texts = ['title' => 'Faja térmica', 'body' => 'Reduce tu cintura', 'landing_url' => 'https://meloon.com/faja', 'url_tags' => 'utm_source=facebook'];

        expect(UploadRules::creativeSpec($source, ['type' => 'video', 'video_id' => '555', 'thumbnail' => 'https://x/t.jpg'], $texts, 'C0457 FASP'))->toBe([
            'name' => 'C0457 FASP',
            'object_story_spec' => ['page_id' => '111', 'instagram_user_id' => '222', 'video_data' => [
                'video_id' => '555', 'image_url' => 'https://x/t.jpg', 'message' => 'Reduce tu cintura', 'title' => 'Faja térmica',
                'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://meloon.com/faja']]]],
            'url_tags' => 'utm_source=facebook',
        ]);
    });

    it('el creativo de una imagen va con su hash y sin título vacío', function () {
        $spec = UploadRules::creativeSpec(['object_story_spec' => ['page_id' => '111']], ['type' => 'image', 'hash' => 'abc'],
            ['title' => null, 'body' => 'Texto', 'landing_url' => 'https://meloon.com/faja', 'url_tags' => null], 'C0458 FASP');

        expect($spec)->toBe(['name' => 'C0458 FASP', 'object_story_spec' => ['page_id' => '111', 'link_data' => [
            'image_hash' => 'abc', 'link' => 'https://meloon.com/faja', 'message' => 'Texto',
            'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://meloon.com/faja']]]]]);
    });

    it('lo que se ve en la misión, en palabras', function () {
        $n = ['files' => 3, 'files_done' => 2, 'ads' => 3, 'ads_done' => 1];

        expect(UploadRules::progress('queued', null, $n, true, 'B0123 FASP', null))->toBe('En cola: empieza en menos de un minuto.')
            ->and(UploadRules::progress('running', 'files', $n, true, 'B0123 FASP', null))->toBe('Subiendo los archivos a Meta: 2 de 3.')
            ->and(UploadRules::progress('waiting', 'processing', $n, true, 'B0123 FASP', null))->toBe('Meta está procesando los videos (puede tardar unos minutos).')
            ->and(UploadRules::progress('running', 'ads', $n, true, 'B0123 FASP', null))->toBe('Creando los anuncios: 1 de 3.')
            ->and(UploadRules::progress('done', null, $n, true, 'B0123 FASP', null))
            ->toBe('Listo, EN PAUSA: revisa el ad set «B0123 FASP» en el Administrador de anuncios y préndelo. Después marca la misión como hecha.')
            ->and(UploadRules::progress('done', null, $n, false, 'B0123 FASP', null))->toBe('Listo: «B0123 FASP» quedó prendido con sus 3 anuncios.')
            ->and(UploadRules::progress('failed', 'ads', $n, false, 'B0123 FASP', 'Meta respondió: no.'))
            ->toBe('No se pudo terminar: Meta respondió: no. Lo que alcanzó a crearse quedó apagado; «Reintentar» sigue donde se quedó, sin repetir nada.');
    });

    it('los errores de Meta, en palabras', function () {
        expect(UploadRules::metaError('token', 'x'))->toContain('pega uno nuevo en Integraciones › Meta Ads › Conexiones')
            ->and(UploadRules::metaError('permission', '(#10) Application does not have permission'))->toContain('no tiene permiso')
            ->and(UploadRules::metaError('meta', 'Invalid parameter'))->toBe('Meta respondió: Invalid parameter');
    });
});
