<?php

namespace App\Services\Storefront;

/**
 * `.164` (dueño, 2026-11-19: "mejor olvida lo de medir el formulario de Releasit… vamos a utilizar solo nuestro nuevo
 * formulario"): las reglas de nuestro formulario de pedido en la landing, en PHP puro. Pago al recibir, como el de Releasit
 * del dueño: las mismas ofertas (unidades × precio × (1 − %)), los precios en bolívares con su tasa de Shopify (la cuenta y el
 * redondeo de su Liquid), el Delivery Express, el teléfono venezolano, los nombres de los pedidos (WEB-12), el Content ID de
 * su catálogo y el mensaje de WhatsApp después del pedido (los códigos de Releasit).
 *
 * `.168` (dueño, 2026-11-21): el pago anticipado (pago móvil con la foto del comprobante), las encomiendas a todo el país
 * (MRW y Zoom), "hablar con una asesora", el horario para el mensaje de fuera de horario, y cuándo sale el Purchase en las
 * campañas marcadas "al entregar". Nada de lo que ve o recibe el cliente nombra a una IA: "el cliente debe creer que lo
 * revisa una persona… así evitamos que nos busquen hacer fraudes y estafas".
 */
final class StorefrontRules
{
    public const SETTING = 'storefront_form';

    public const CONTENT_FORMATS = ['shopify' => 'shopify_{REGION}_{producto}_{variante}', 'product' => '{producto}', 'variant' => '{variante}'];

    /** El mensaje de WhatsApp del dueño en Releasit (19/11). */
    public const WHATSAPP_MESSAGE = '*Es importante enviar este mensaje* ¡Hola! Soy {first_name} y he realizado el pedido {order_id}. Deseo pagarlo al momento de recibirlo. Muchas gracias!';

    /** `.168`: las empresas de encomiendas (Tealca no, por ahora). */
    public const COURIERS = ['MRW', 'Zoom'];

    /** `.168`: el mensaje que manda el cliente según cómo pagó (los mismos códigos, más {courier}). */
    public const WHATSAPP_PREPAID = '*Es importante enviar este mensaje* ¡Hola! Soy {first_name} y he realizado el pedido {order_id}. Ya lo pagué por pago móvil y envié el comprobante. Muchas gracias!';

    public const WHATSAPP_ADVISOR = '*Es importante enviar este mensaje* ¡Hola! Soy {first_name} y quiero hablar con una asesora sobre mi pedido {order_id}. Muchas gracias!';

    public const WHATSAPP_COURIER = '*Es importante enviar este mensaje* ¡Hola! Soy {first_name} y he realizado el pedido {order_id} para retirar en {courier}. Ya lo pagué por pago móvil y envié el comprobante. Muchas gracias!';

    /**
     * `.168`: lo que le escribe el sistema al cliente de los casos nuevos, como si lo escribiera una persona. `.181` (dueño,
     * 26/11: "necesito que todos los mensajes para los casos nuevos que estamos agregando sean plantillas aprobadas por
     * meta"; "muchas personas no envían ese mensaje"): sale siempre con su plantilla de Meta (Utilidad, Español), nos haya
     * escrito o no. El texto es el mismo que el dueño crea en Meta: con él se guarda en el chat lo que le llegó.
     * - ack: pagó con la tienda cerrada (dueño, 21/11: "en nuestro horario laboral una asesora validará el pago y procederá a
     *   realizar el envío");
     * - confirm_home: su comprobante quedó bien y lo recibe en su casa (dueño, 26/11: "debe igualmente pedir la ubicación gps
     *   para que el repartidor llegue más fácil");
     *   (`.182`: primero le agradece la dirección del formulario: "qué bueno que nos mandó la ubicación… en el formulario, pero
     *   para que el repartidor llegue más rápido, que por favor nos envíe su ubicación por WhatsApp");
     * - location_reminder (`.182`): si 2 horas después todavía no la mandó, una vez;
     * - confirm_courier: igual, en una encomienda (la guía es una foto desde la `.173`);
     * - advisor: la encomienda que se paga con una asesora (no paga en el formulario): que nos responda para cobrarle.
     */
    public const TEMPLATES = [
        'ack' => ['name' => 'pago_recibido_fuera_de_horario', 'when' => 'Pagó con la tienda cerrada: 1 minuto después de pedir.',
            'text' => "¡Hola {{1}}! 🙌 Recibimos tu pedido {{2}} y tu comprobante de pago.\n\nNuestro horario de atención es {{3}}. Una asesora validará tu pago {{4}} y procederá con tu envío. Te escribiremos por aquí.\n\n¡Gracias por tu confianza! 💕",
            'examples' => ['María', 'WEB-12', 'de lunes a sábado, de 8:00 a. m. a 7:00 p. m', 'mañana a partir de las 8:00 a. m.']],
        'confirm_home' => ['name' => 'pago_confirmado_domicilio', 'when' => 'Su comprobante quedó bien y lo recibe en su casa: entre 5 y 15 minutos después, con la tienda abierta.',
            'text' => "¡Hola {{1}}! ✅ Ya confirmamos tu pago del pedido {{2}}. Estamos preparando tu envío y te avisaremos por aquí cuando salga.\n\n📍 Ya tenemos la dirección que nos dejaste. Para que el repartidor llegue más rápido, respóndenos con tu ubicación: toca el 📎 (o el +), elige «Ubicación» y «Enviar tu ubicación actual».\n\n¡Gracias por tu compra! 💕",
            'examples' => ['María', 'WEB-12']],
        'location_reminder' => ['name' => 'recordatorio_ubicacion', 'when' => 'Pagó por adelantado, lo recibe en su casa y 2 horas después de la confirmación todavía no mandó su ubicación: una vez, con la tienda abierta.',
            'text' => "¡Hola {{1}}! 📍 Para llevarte tu pedido {{2}} sin demoras, ¿nos ayudas con tu ubicación? Respóndenos por aquí: toca el 📎 (o el +), elige «Ubicación» y «Enviar tu ubicación actual».\n\n¡Gracias! 💕",
            'examples' => ['María', 'WEB-12']],
        'confirm_courier' => ['name' => 'pago_confirmado_encomienda', 'when' => 'Su comprobante quedó bien y es una encomienda: entre 5 y 15 minutos después, con la tienda abierta.',
            'text' => "¡Hola {{1}}! ✅ Ya confirmamos tu pago del pedido {{2}}. Estamos preparando tu envío por {{3}} y te mandaremos por aquí la foto de tu guía cuando salga.\n\n¡Gracias por tu compra! 💕",
            'examples' => ['María', 'WEB-12', 'MRW']],
        'advisor' => ['name' => 'pedido_encomienda_asesora', 'when' => 'Encomienda que paga con una asesora: 1 minuto después de pedir, con la tienda abierta (si está cerrada, al abrir).',
            'text' => "¡Hola {{1}}! 📦 Recibimos tu pedido {{2}} para enviarlo por {{3}}.\n\nRespóndenos este mensaje y una asesora te ayuda a hacer el pago móvil y a confirmar tu envío. ¡Gracias por tu confianza! 💕",
            'examples' => ['María', 'WEB-12', 'MRW']],
    ];

    /** `.182`: el recordatorio de la ubicación sale estas horas después de "ya confirmamos tu pago" (si todavía no la mandó). */
    public const LOCATION_REMINDER_HOURS = 2;

    /** El "ya confirmamos tu pago" sale entre estos minutos después de validar el comprobante (como si lo revisara una persona). */
    public const CONFIRM_MINUTES = [5, 15];

    /** Meta acepta un evento de la web hasta 7 días después: con un día de margen, después de eso sale con la hora de entrega. */
    public const LATE_EVENT_DAYS = 6;

    public const DEFAULTS = ['enabled' => false, 'shop_id' => null, 'product_ids' => [], 'express_usd' => 2.0, 'whatsapp_number' => '+584221792891',
        'whatsapp_message' => self::WHATSAPP_MESSAGE, 'allowed_origins' => [], 'releasit_selector' => '', 'pixel_id' => '', 'capi_token' => null,
        'capi_test_code' => '', 'content_id_format' => 'shopify', 'content_region' => 'VE', 'send_initiate' => true, 'send_pageview' => false,
        'daily_max' => 500, 'prepaid_enabled' => false, 'advisor_enabled' => false, 'courier_enabled' => false, 'courier_companies' => self::COURIERS,
        'courier_origin_city_id' => null, 'courier_dropoff' => ['MRW' => '', 'Zoom' => ''], 'whatsapp_message_prepaid' => self::WHATSAPP_PREPAID,
        'whatsapp_message_advisor' => self::WHATSAPP_ADVISOR, 'whatsapp_message_courier' => self::WHATSAPP_COURIER, 'courier_fee_usd' => null];

    /** Los celulares de Venezuela (Movilnet, Movistar y Digitel). */
    public const MOBILE = ['412', '414', '416', '422', '424', '426'];

    /** El mismo teléfono y el mismo producto en este tiempo es el mismo pedido (un doble toque o un reenvío). */
    public const SAME_ORDER_MINUTES = 30;

    /** Cuántos pedidos puede hacer un mismo teléfono en 24 horas (cada uno manda plantillas de WhatsApp). */
    public const PHONE_DAY_MAX = 3;

    /** Lo mínimo que tarda una persona en llenar el formulario (los robots lo mandan al instante). */
    public const MIN_SECONDS = 3;

    /** Para que nunca choque con un número de Shopify (un id de pedido de Shopify tiene 13 dígitos). */
    public const ORDER_ID_BASE = 9_000_000_000_000_000;

    /**
     * Los textos del formulario de Releasit del dueño (captura del 19/11); cada producto puede cambiar los suyos.
     * `.169` (dueño, 2026-11-21): en pasos numerados (la oferta solo si hay varias); primero sus datos y su estado o ciudad,
     * y según eso la entrega y el pago: en las ciudades con delivery, pagar al recibir o ahora; en el resto del país, por
     * MRW o Zoom, pagando ahora o con una asesora.
     */
    public const TEXTS = [
        'title' => 'PIDE EL TUYO Y PAGA AL RECIBIRLO 👇',
        'step_offer' => 'Elige tu oferta',
        'step_data' => 'Tus datos',
        'step_delivery' => 'Entrega y pago',
        'step_confirm' => 'Confirma tu pedido',
        'size_label' => 'Talla',
        'size_sold_out' => 'Agotada',
        'name_label' => 'Nombre y apellido',
        'name_placeholder' => 'Nombre completo',
        'phone_label' => 'Numero de contacto',
        'phone_placeholder' => 'Ingrese su WhatsApp',
        'where_label' => 'Estado o ciudad donde lo recibes',
        'where_placeholder' => 'Elige tu estado o ciudad',
        'where_group_cod' => 'Delivery a domicilio',
        'where_group_courier' => 'Resto del país (envío por MRW o Zoom)',
        'delivery_wait' => 'Elige tu estado o ciudad para ver cómo te llega tu pedido.',
        'cod_notice' => '🚚 Te lo llevamos gratis a tu dirección en {ciudad}.',
        'address_label' => 'Dirección de entrega (sea detallado)',
        'address_placeholder' => 'Urbanizacion, residencia, calle, etc',
        'reference_placeholder' => 'Puntos de referencia',
        'pay_label' => '¿Cómo quieres pagar?',
        'pay_cod' => 'Pagar al recibir',
        'pay_cod_note' => 'Pagas cuando te llegue el pedido.',
        'pay_now' => 'Pagar ahora con pago móvil',
        'pay_now_note' => 'Más fácil de coordinar: si no vas a estar, lo dejamos en vigilancia o con quien nos digas.',
        'courier_notice' => 'Nuestro delivery es en {ciudades}. A {estado} te lo enviamos por MRW o Zoom, con envío gratis, y se paga por adelantado.',
        'courier_company_label' => 'Empresa de envío',
        'courier_free' => 'Envío gratis',
        'courier_office_label' => 'Agencia {empresa} donde retiras',
        'courier_office_placeholder' => 'Elige la agencia',
        'courier_office_hint' => '¿No ves tu ciudad? Elige la agencia más cercana a ti.',
        'courier_office_none' => 'No hay agencias de {empresa} en {estado}: elige la más cercana a ti.',
        'courier_office_all' => 'Ver agencias de otros estados',
        'courier_office_missing' => 'No encuentro mi agencia',
        'courier_office_text_placeholder' => 'Escribe la agencia y la ciudad',
        'courier_id_label' => 'Cédula de quien retira',
        'courier_id_placeholder' => 'V-12345678',
        'courier_other' => '¿Retira otra persona?',
        'courier_name_label' => 'Nombre de quien retira',
        'courier_phone_label' => 'Teléfono de quien retira',
        'pay_courier_now' => 'Pagar ahora con pago móvil',
        'pay_courier_now_note' => 'Pagas aquí mismo y nos envías la foto del comprobante.',
        'pay_advisor' => 'Hacer el pago con una asesora',
        'pay_advisor_note' => 'Una asesora te escribe por WhatsApp, le haces el pago móvil y te confirma el envío.',
        'pm_title' => 'Datos para tu pago móvil',
        'pm_amount' => 'Monto exacto a pagar',
        'pm_copy' => 'Copiar',
        'pm_copied' => 'Copiado',
        'pm_loading' => 'Cargando los datos del pago…',
        'pm_error' => 'No pudimos cargar los datos del pago. Inténtalo de nuevo en un momento.',
        'receipt_label' => 'Foto del comprobante de pago',
        'receipt_button' => 'Subir foto del comprobante',
        'receipt_change' => 'Cambiar foto',
        'receipt_note' => 'Nuestro equipo valida cada pago antes de enviar tu pedido.',
        'subtotal' => 'Subtotal',
        'delivery' => 'Delivery',
        'courier_shipping' => 'Envío por encomienda',
        'shipping_price' => 'Gratis',
        'total' => 'Total',
        'express_title' => '(OPCIONAL) Añade Delivery Express por solo {precio}',
        'express_note' => 'TU PEDIDO SE ENVIARA PRIMERO QUE TODOS LOS DEMAS',
        'button' => 'CONFIRMAR PEDIDO',
        'button_note' => 'PAGAR AL RECIBIR',
        'button_note_prepaid' => 'YA PAGUÉ · ENVIAR COMPROBANTE',
        'button_note_advisor' => 'HABLAR CON UNA ASESORA',
        'footnote' => '* Nuestro equipo te atenderá por WhatsApp para finalizar el envío.',
        'saving' => 'Ahorras {ahorro}',
        'thanks_title' => '¡Listo! Recibimos tu pedido {pedido}',
        'thanks_text' => 'Ahora te llevamos a WhatsApp: envía el mensaje para confirmar tu pedido.',
        'thanks_prepaid_title' => '¡Listo! Recibimos tu pedido {pedido} y tu comprobante',
        'thanks_prepaid_open' => 'Estamos validando tu pago. En unos minutos te llegará un WhatsApp de confirmación.',
        'thanks_prepaid_closed' => 'Gracias por tu confianza. Nuestro horario de atención es {horario}. {cuando}, una asesora validará tu pago y procederá con tu envío. Te escribiremos por WhatsApp.',
        'thanks_advisor' => 'Una asesora te escribirá por WhatsApp para hacer tu pago móvil y confirmar tu envío.',
        'thanks_go_whatsapp' => 'Ahora te llevamos a WhatsApp: envía el mensaje para que podamos escribirte.',
        'thanks_button' => 'Enviar mensaje por WhatsApp',
        'repeat_text' => 'Ya tenemos tu pedido {pedido}: no hace falta enviarlo otra vez.',
        'error_required' => 'Completa este dato',
        'error_phone' => 'Escribe un número de WhatsApp de Venezuela (0414, 0424, 0412, 0416, 0426 o 0422)',
        'error_where' => 'Elige tu estado o ciudad',
        'error_receipt' => 'Sube la foto del comprobante de tu pago',
        'error_id' => 'Escribe la cédula (solo números, por ejemplo V-12345678)',
        'error_office' => 'Elige la agencia donde retiras',
        'error_stock' => 'Se nos acaba de agotar en {ciudad}. Elige otra opción.',
    ];

    /** Los colores del formulario de Releasit del dueño; cada producto puede cambiar los suyos. */
    public const COLORS = ['title' => '#1A73E8', 'button' => '#0A6CFF', 'button_text' => '#FFFFFF', 'background' => '#FFFFFF', 'text' => '#333333',
        'label' => '#5F6368', 'border' => '#D9D9D9', 'field_icon_bg' => '#E5E5E5', 'offer_selected' => '#2EA44F', 'offer_selected_bg' => '#E3F5E8',
        'badge' => '#2EA44F', 'badge_text' => '#FFFFFF', 'summary_bg' => '#F3F3F3', 'express_bg' => '#A8E6C1', 'express_border' => '#2EA44F',
        'error' => '#D93025'];

    /** Lo que se guarda: lo que no llega queda como estaba; la clave de Meta nunca se borra sin querer. */
    public static function settings(?array $raw): array
    {
        $raw = (array) $raw;
        $d = self::DEFAULTS;
        $origins = array_values(array_unique(array_filter(array_map(fn ($o) => self::origin((string) $o), (array) ($raw['allowed_origins'] ?? [])))));

        return [
            'enabled' => (bool) ($raw['enabled'] ?? $d['enabled']),
            'shop_id' => isset($raw['shop_id']) && (int) $raw['shop_id'] > 0 ? (int) $raw['shop_id'] : null,
            'product_ids' => array_values(array_unique(array_filter(array_map('intval', (array) ($raw['product_ids'] ?? []))))),
            'express_usd' => round(min(50, max(0, (float) ($raw['express_usd'] ?? $d['express_usd']))), 2),
            'whatsapp_number' => self::waNumber((string) ($raw['whatsapp_number'] ?? $d['whatsapp_number'])),
            'whatsapp_message' => mb_substr(trim((string) ($raw['whatsapp_message'] ?? '')) ?: $d['whatsapp_message'], 0, 1000),
            'allowed_origins' => array_slice($origins, 0, 10),
            'releasit_selector' => mb_substr(trim((string) ($raw['releasit_selector'] ?? '')), 0, 500),
            'pixel_id' => preg_match('/^\d{6,20}$/', trim((string) ($raw['pixel_id'] ?? ''))) ? trim((string) $raw['pixel_id']) : '',
            'capi_token' => is_string($raw['capi_token'] ?? null) && $raw['capi_token'] !== '' ? $raw['capi_token'] : null,
            'capi_test_code' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['capi_test_code'] ?? '')), 0, 40),
            'content_id_format' => array_key_exists($raw['content_id_format'] ?? '', self::CONTENT_FORMATS) ? $raw['content_id_format'] : $d['content_id_format'],
            'content_region' => preg_match('/^[A-Za-z]{2}$/', (string) ($raw['content_region'] ?? '')) ? strtoupper($raw['content_region']) : $d['content_region'],
            'send_initiate' => (bool) ($raw['send_initiate'] ?? $d['send_initiate']),
            'send_pageview' => (bool) ($raw['send_pageview'] ?? $d['send_pageview']),
            'daily_max' => min(5000, max(10, (int) ($raw['daily_max'] ?? $d['daily_max']))),
            'prepaid_enabled' => (bool) ($raw['prepaid_enabled'] ?? $d['prepaid_enabled']),
            'advisor_enabled' => (bool) ($raw['advisor_enabled'] ?? $d['advisor_enabled']),
            'courier_enabled' => (bool) ($raw['courier_enabled'] ?? $d['courier_enabled']),
            'courier_companies' => array_values(array_intersect(self::COURIERS, (array) ($raw['courier_companies'] ?? $d['courier_companies']))) ?: self::COURIERS,
            'courier_origin_city_id' => isset($raw['courier_origin_city_id']) && (int) $raw['courier_origin_city_id'] > 0 ? (int) $raw['courier_origin_city_id'] : null,
            'courier_dropoff' => array_combine(self::COURIERS, array_map(fn ($c) => mb_substr(trim((string) (((array) ($raw['courier_dropoff'] ?? []))[$c] ?? '')), 0, 500), self::COURIERS)),
            // .174: lo que cobra MRW o Zoom por envío, en promedio (null = el dueño todavía no lo puso)
            'courier_fee_usd' => isset($raw['courier_fee_usd']) && is_numeric($raw['courier_fee_usd']) && (float) $raw['courier_fee_usd'] >= 0
                ? round(min(100, (float) $raw['courier_fee_usd']), 2) : null,
            ...array_combine(['whatsapp_message_prepaid', 'whatsapp_message_advisor', 'whatsapp_message_courier'],
                array_map(fn ($k) => mb_substr(trim((string) ($raw[$k] ?? '')) ?: $d[$k], 0, 1000),
                    ['whatsapp_message_prepaid', 'whatsapp_message_advisor', 'whatsapp_message_courier'])),
        ];
    }

    /** La cédula de quien retira una encomienda: "v 12.345.678" → "V-12345678"; sin letra, V. Algo raro → null. */
    public static function idNumber(?string $raw): ?string
    {
        $v = strtoupper(preg_replace('/[\s.\-]+/', '', (string) $raw));
        if (!preg_match('/^([VE])?(\d{6,9})$/', $v, $m)) {
            return null;
        }

        return ($m[1] ?: 'V') . '-' . $m[2];
    }

    /**
     * Si un pedido nuevo manda el Purchase según cómo paga ('delivery': al entregar si paga al recibir o con la asesora) o al
     * pedir ('order', como siempre): según cómo paga si su producto lo tiene prendido ahora (`.171`) o si su campaña está
     * marcada ahora (`.168`). Al apagarlo, su último día queda en `to`.
     *
     * @param list<array{campaign: ?string, product?: ?int, from: string, to: ?string}> $ranges PurchaseModes::ranges()
     */
    public static function purchaseMode(?string $campaignId, array $ranges, string $date, ?int $productId = null): string
    {
        foreach ($ranges as $r) {
            $hit = ($campaignId !== null && $r['campaign'] !== null && (string) $r['campaign'] === $campaignId)
                || ($productId !== null && $r['campaign'] === null && ($r['product'] ?? null) === $productId);
            if ($hit && $r['from'] <= $date && $r['to'] === null) {
                return 'delivery';
            }
        }

        return 'order';
    }

    /**
     * Si ese día la campaña estuvo marcada (con su último día incluido): ese día el sistema la mide con el "pedido hecho".
     *
     * @param list<array{campaign: ?string, from: string, to: ?string}> $ranges
     */
    public static function markedOn(string $campaignId, string $date, array $ranges): bool
    {
        foreach ($ranges as $r) {
            if ($r['campaign'] !== null && (string) $r['campaign'] === $campaignId && $r['from'] <= $date && ($r['to'] === null || $r['to'] >= $date)) {
                return true;
            }
        }

        return false;
    }

    /** Si el Purchase sale al pedir: la campaña lo quiere al pedir, o el cliente ya pagó (pago móvil o encomienda). */
    public static function purchaseNow(string $mode, string $payment): bool
    {
        return $mode !== 'delivery' || $payment === 'pago_movil';
    }

    /** El "pedido hecho" (AddPaymentInfo) de cada pedido, para que el sistema siga contando pedidos. */
    public static function placedEventId(int $n): string
    {
        return "nuv-pedido-{$n}";
    }

    /** La hora del Purchase al entregar: la del pedido si fue hace 6 días o menos (más exacta); si no, la de ahora. */
    public static function deliveredTime(int $created, int $now): int
    {
        return $now - $created <= self::LATE_EVENT_DAYS * 86400 ? $created : $now;
    }

    /** Cuándo sale el "ya confirmamos tu pago": entre 5 y 15 minutos después ($rand de 0 a 1). */
    public static function confirmDue(int $now, float $rand): int
    {
        [$min, $max] = self::CONFIRM_MINUTES;

        return $now + (int) round(($min + max(0, min(1, $rand)) * ($max - $min)) * 60);
    }

    /** 8 → "8:00 a. m.", "19:30" → "7:30 p. m.". */
    public static function niceHour(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return ($h % 12 === 0 ? 12 : $h % 12) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ($h < 12 ? ' a. m.' : ' p. m.');
    }

    private const DAYS = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    /**
     * El horario de atención para el cliente: "de lunes a sábado, de 8:00 a. m. a 7:00 p. m.". Sin horario → null.
     *
     * @param list<int>|null $days 1 = lunes … 7 = domingo; null = todos
     */
    public static function hoursText(?string $open, ?string $close, ?array $days): ?string
    {
        if (!$open || !$close) {
            return null;
        }
        $list = $days === null ? range(1, 7) : array_values(array_unique(array_map('intval', $days)));
        sort($list);
        if ($list === []) {
            return null;
        }
        $groups = [];
        foreach ($list as $d) {
            $last = count($groups) - 1;
            if ($last >= 0 && end($groups[$last]) === $d - 1) {
                $groups[$last][] = $d;
            } else {
                $groups[] = [$d];
            }
        }
        $parts = array_map(fn ($g) => count($g) === 1 ? 'los ' . self::DAYS[$g[0]] . (in_array($g[0], [6, 7], true) ? 's' : '')
            : (count($g) === 2 ? self::DAYS[$g[0]] . ' y ' . self::DAYS[$g[1]] : 'de ' . self::DAYS[$g[0]] . ' a ' . self::DAYS[end($g)]), $groups);
        $daysText = count($parts) === 1 ? $parts[0] : implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts);

        return $daysText . ', de ' . self::niceHour(substr($open, 0, 5)) . ' a ' . self::niceHour(substr($close, 0, 5));
    }

    /**
     * Cuándo se vuelve a atender: la próxima apertura después de ahora (hasta 2 semanas), o null.
     *
     * @param list<int>|null $days
     */
    public static function nextOpening(\DateTimeImmutable $now, ?string $open, ?array $days): ?\DateTimeImmutable
    {
        if (!$open) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $open) + [0, 0]);
        for ($i = 0; $i <= 14; $i++) {
            $at = $now->modify("+{$i} days")->setTime($h, $m);
            $dow = (int) $at->format('N');
            if (($days === null || in_array($dow, array_map('intval', $days), true)) && $at > $now) {
                return $at;
            }
        }

        return null;
    }

    /** "Mañana a partir de las 8:00 a. m.", "Hoy a partir de…", "El lunes a partir de…"; sin apertura, "Apenas abramos". */
    public static function whenText(\DateTimeImmutable $now, ?\DateTimeImmutable $at): string
    {
        if ($at === null) {
            return 'Apenas abramos';
        }
        $days = (int) $now->setTime(12, 0)->diff($at->setTime(12, 0))->days;
        $day = $days === 0 ? 'Hoy' : ($days === 1 ? 'Mañana' : 'El ' . self::DAYS[(int) $at->format('N')]);

        return "{$day} a partir de las " . self::niceHour($at->format('H:i'));
    }

    /** "https://mitienda.com/" → "https://mitienda.com"; lo que no es un origen https (o localhost) no vale. */
    public static function origin(string $url): ?string
    {
        $p = parse_url(trim($url));
        $host = strtolower((string) ($p['host'] ?? ''));
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($host === '' || !in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true))) {
            return null;
        }

        return "{$scheme}://{$host}" . (isset($p['port']) ? ':' . (int) $p['port'] : '');
    }

    /**
     * El WhatsApp del cliente, como lo guardan los pedidos de Shopify: "+58" y los 10 dígitos de un celular. "0414-123.45.67",
     * "+58 414 1234567", "584141234567", "4141234567" y "00584141234567" → "+584141234567". Un fijo o algo raro → null.
     */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        $d = preg_replace('/^(00)?58/', '', $d);
        $d = ltrim($d, '0');

        return strlen($d) === 10 && in_array(substr($d, 0, 3), self::MOBILE, true) ? '+58' . $d : null;
    }

    /** Los últimos 10 dígitos (así se reconoce al mismo cliente en todo el sistema). */
    public static function last10(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone), -10);
    }

    /** "María José Pérez" → ["María", "José Pérez"]. @return array{0: string, 1: string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /** El total en dólares de una oferta: unidades × precio × (1 − %), con centavos (como Releasit: 3 × $33 − 33 % = $66,33). */
    public static function offerUsd(float $price, int $units, float $pct): float
    {
        return round($units * $price * (1 - min(90, max(0, $pct)) / 100), 2);
    }

    /** Los bolívares como los calcula la landing del dueño: dólares × su tasa, redondeado (Math.round). */
    public static function bs(float $usd, float $rate): int
    {
        return (int) round($usd * $rate);
    }

    /** "Bs 12.345", como lo muestra su landing. */
    public static function bsText(int $bs): string
    {
        return 'Bs ' . number_format($bs, 0, ',', '.');
    }

    /** La tasa con que se calculan los Bs: la de su página (su metafield binance_rate) o, si no llega bien, la del sistema. */
    public static function rate(mixed $page, float $system): ?float
    {
        $r = is_numeric($page) ? (float) $page : 0.0;
        if ($r > 0 && $r < 10_000_000) {
            return $r;
        }

        return $system > 0 ? $system : null;
    }

    /**
     * Las ofertas de un producto, listas para el formulario: las activas en su orden, con su total en dólares y en Bs, lo que
     * ahorra y una sola marcada (la que el dueño marcó o, si no, la de 1 unidad). Sin ofertas, una: 1 unidad al precio.
     *
     * @param list<array{id: int, units: int, discount_pct: float, title: ?string, badge: ?string, is_default: bool, is_active: bool}> $offers
     * @return list<array{id: ?int, units: int, title: string, badge: ?string, is_default: bool, price_usd: float, price_bs: ?int, saving_bs: ?int}>
     */
    public static function offers(float $price, array $offers, ?float $rate, string $name): array
    {
        $list = array_values(array_filter($offers, fn ($o) => ($o['is_active'] ?? true) && (int) $o['units'] >= 1));
        if ($list === []) {
            $list = [['id' => null, 'units' => 1, 'discount_pct' => 0.0, 'title' => null, 'badge' => null, 'is_default' => true]];
        }
        $out = array_map(function ($o) use ($price, $rate, $name) {
            $usd = self::offerUsd($price, (int) $o['units'], (float) $o['discount_pct']);
            $full = round($price * (int) $o['units'], 2);

            return ['id' => $o['id'] !== null ? (int) $o['id'] : null, 'units' => (int) $o['units'],
                'title' => trim((string) ($o['title'] ?? '')) ?: ((int) $o['units'] === 1 ? "LLEVA 1 {$name}" : 'LLEVA ' . (int) $o['units'] . " {$name}"),
                'badge' => trim((string) ($o['badge'] ?? '')) ?: null, 'is_default' => (bool) ($o['is_default'] ?? false), 'price_usd' => $usd,
                'price_bs' => $rate !== null ? self::bs($usd, $rate) : null,
                'saving_bs' => $rate !== null && $full > $usd ? self::bs($full, $rate) - self::bs($usd, $rate) : null];
        }, $list);
        $default = array_search(true, array_column($out, 'is_default'), true);
        if ($default === false) {
            $default = array_search(1, array_column($out, 'units'), true);
        }
        foreach ($out as $i => $o) {
            $out[$i]['is_default'] = $i === ($default === false ? 0 : $default);
        }

        return $out;
    }

    /**
     * Dónde se paga al recibir este producto: las ciudades con una agencia activa (o un centro logístico activo), salvo donde
     * el dueño lo marcó como no disponible.
     *
     * @param list<array{id: int, name: string}> $cities
     * @param list<int> $withAgency ciudades con una agencia activa en city_agency
     * @param list<int> $withCenter ciudades con un centro logístico activo
     * @param list<int> $unavailable ciudades donde este producto no se vende
     * @return list<array{id: int, name: string}>
     */
    public static function codCities(array $cities, array $withAgency, array $withCenter, array $unavailable): array
    {
        $ok = array_flip(array_map('intval', [...$withAgency, ...$withCenter]));
        $no = array_flip(array_map('intval', $unavailable));
        $out = array_values(array_filter($cities, fn ($c) => isset($ok[(int) $c['id']]) && !isset($no[(int) $c['id']])));
        usort($out, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name']], $out);
    }

    /**
     * `.172` (dueño, 2026-11-21: "no debería ofrecer si no hay stock"): cuánto puede entregar cada ciudad (PHP puro). Una
     * orden la entrega un solo almacén, así que vale el que más tiene: del producto ('all') y de cada talla (el menor entre el
     * producto y la talla en ese almacén, la misma regla de StockCheck).
     *
     * @param array<int, list<int>> $warehousesByCity city_id => sus almacenes
     * @param array<int, int> $productStock almacén => stock útil del producto
     * @param array<int, array<int, int>> $variantStock almacén => [talla => stock útil]
     * @param list<int> $variantIds las tallas activas del producto
     * @return array<int, array{all: int, sizes: array<int, int>}>
     */
    public static function stockByCity(array $warehousesByCity, array $productStock, array $variantStock, array $variantIds): array
    {
        $out = [];
        foreach ($warehousesByCity as $cityId => $warehouses) {
            $all = 0;
            $sizes = array_fill_keys($variantIds, 0);
            foreach ($warehouses as $w) {
                $p = (int) ($productStock[$w] ?? 0);
                $all = max($all, $p);
                foreach ($variantIds as $v) {
                    $sizes[$v] = max($sizes[$v], min($p, (int) ($variantStock[$w][$v] ?? 0)));
                }
            }
            $out[(int) $cityId] = ['all' => $all, 'sizes' => $sizes];
        }

        return $out;
    }

    /**
     * `.172`: si esa ciudad puede entregar esas piezas: de esa talla; sin talla elegida, de alguna; sin tallas, del producto.
     *
     * @param ?array{all: int, sizes: array<int, int>} $stock
     */
    public static function canDeliver(?array $stock, int $units, ?int $variantId = null): bool
    {
        if ($stock === null) {
            return false;
        }
        if ($variantId !== null) {
            return ($stock['sizes'][$variantId] ?? 0) >= $units;
        }

        return ($stock['sizes'] === [] ? $stock['all'] : max(0, ...array_values($stock['sizes']))) >= $units;
    }

    /** `.172`: lo que ve la página: hasta la oferta más grande (alcanza o no; no dice cuánto stock hay). */
    public static function stockShown(array $stock, int $cap): array
    {
        return ['all' => min($stock['all'], $cap), 'sizes' => (object) array_map(fn ($n) => min($n, $cap), $stock['sizes'])];
    }

    /** Si un pedido anterior del mismo teléfono y producto es el mismo pedido (un doble toque o un reenvío). */
    public static function samePedido(string $before, string $now): bool
    {
        return strtotime($now) - strtotime($before) <= self::SAME_ORDER_MINUTES * 60;
    }

    public static function orderName(int $n): string
    {
        return "WEB-{$n}";
    }

    public static function orderId(int $n): int
    {
        return self::ORDER_ID_BASE + $n;
    }

    public static function eventId(int $n): string
    {
        return "nuv-web-{$n}";
    }

    /** El Content ID del catálogo de Meta, con el formato que tenía en Releasit ("shopify_VE_7558816…_4521…"). */
    public static function contentId(string $format, string $region, string $productId, ?string $variantId): string
    {
        return match ($format) {
            'product' => $productId,
            'variant' => (string) ($variantId ?: $productId),
            default => 'shopify_' . strtoupper($region) . "_{$productId}" . ($variantId ? "_{$variantId}" : ''),
        };
    }

    /**
     * El mensaje que el cliente manda por WhatsApp después de pedir, con los códigos de Releasit: {first_name}, {last_name},
     * {order_id}, {order_number}, {order_total}, {product_title}, {product_quantity}, {products_summary_with_quantity},
     * {phone}, {address}, {address2}, {province}. Lo que no se conoce queda vacío.
     *
     * @param array<string, string> $vars
     */
    public static function whatsappMessage(string $template, array $vars): string
    {
        $text = preg_replace_callback('/\{([a-z0-9_]+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template);

        // .168: "…7:00 p. m.. El lunes": el punto de la abreviatura cierra la oración
        return trim(preg_replace('/(?<!\.)\.\.(?!\.)/u', '.', $text));
    }

    /**
     * `.181`: las variables de una plantilla, en el orden de {{1}}, {{2}}… Meta no manda una plantilla con una variable vacía
     * ni con saltos de línea en ella. El horario va sin su punto final ("…7:00 p. m" + "."), y cuándo abrimos, en minúscula
     * ("validará tu pago mañana a partir de las 8:00 a. m.").
     *
     * @param array<string, string> $vars los de StorefrontOrders::vars
     * @param array{text?: string, when?: string} $hours los de StorefrontCatalog::hours
     * @return list<string>
     */
    public static function templateParams(string $key, array $vars, array $hours = []): array
    {
        $clean = fn ($v, string $empty) => trim((string) preg_replace('/\s+/u', ' ', (string) $v)) ?: $empty;
        $first = [$clean($vars['first_name'] ?? '', 'cliente'), $clean($vars['order_id'] ?? '', 'tu pedido')];
        $when = $clean($hours['when'] ?? '', 'Apenas abramos');

        return match ($key) {
            'ack' => [...$first, rtrim($clean($hours['text'] ?? '', 'en horario de oficina'), '.'), mb_strtolower(mb_substr($when, 0, 1)) . mb_substr($when, 1)],
            'confirm_courier', 'advisor' => [...$first, $clean($vars['courier'] ?? '', 'encomienda')],
            default => $first,
        };
    }

    /** `.181`: el texto que le llega con su plantilla (para el chat de la vendedora). */
    public static function templateText(string $key, array $params): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => (string) ($params[(int) $m[1] - 1] ?? ''), self::TEMPLATES[$key]['text']);
    }

    /** "https://wa.me/584221792891?text=…". */
    public static function waLink(string $number, string $message): ?string
    {
        $d = self::waDigits($number);

        return $d === '' ? null : "https://wa.me/{$d}?text=" . rawurlencode($message);
    }

    /** El origen del pedido con la forma de uno de Shopify, para guardarlo igual (OrderSources: anuncio, landing, utm). */
    public static function sourcePayload(?string $landing, ?string $referrer, ?string $page): array
    {
        return ['landing_site' => $landing ?: $page, 'referring_site' => $referrer, 'source_name' => 'nuviora_form'];
    }

    /** Los textos del formulario de un producto: los suyos sobre los de siempre (lo vacío vuelve al de siempre). */
    public static function texts(?array $own): array
    {
        $out = self::TEXTS;
        foreach ((array) $own as $k => $v) {
            if (array_key_exists($k, self::TEXTS) && is_string($v) && trim($v) !== '') {
                $out[$k] = mb_substr(trim($v), 0, 300);
            }
        }

        return $out;
    }

    /** Los colores de un producto: los suyos (solo #RGB o #RRGGBB) sobre los de siempre. */
    public static function colors(?array $own): array
    {
        $out = self::COLORS;
        foreach ((array) $own as $k => $v) {
            if (array_key_exists($k, self::COLORS) && is_string($v) && preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', trim($v))) {
                $out[$k] = strtoupper(trim($v));
            }
        }

        return $out;
    }

    /** Una letra de Google o la de la página (null). Solo letras, números y espacios: va dentro de un CSS. */
    public static function font(?string $font): ?string
    {
        $f = trim((string) $font);

        return $f !== '' && preg_match('/^[A-Za-z0-9 ]{2,60}$/', $f) ? $f : null;
    }

    /** El CSS del dueño, sin lo que podría salirse de su <style>. */
    public static function css(?string $css): string
    {
        return mb_substr(str_ireplace(['</style', '<script', '</script'], '', (string) $css), 0, 20000);
    }

    /** La firma del momento en que se dibujó el formulario (para saber que no lo mandó un robot al instante). */
    public static function sign(int $ts, string $key): string
    {
        return substr(hash_hmac('sha256', "nvf|{$ts}", $key), 0, 32);
    }

    /** El WhatsApp de la tienda con su código de país: "0422-1792891" → "+584221792891". */
    private static function waNumber(string $n): string
    {
        $d = self::waDigits($n);
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = '58' . substr($d, 1);
        }

        return $d !== '' ? "+{$d}" : '';
    }

    private static function waDigits(string $n): string
    {
        return preg_replace('/\D+/', '', $n);
    }
}
