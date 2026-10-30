<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los creativos bien identificados, los ángulos y el modo de cada producto (Etapa 5, paso 1b, 2026-10-30):
 * - meta_ads y meta_adsets: lo que dice su nombre (creativo, ángulo, batch, referencia, iteración; en el ad set, el
 *   concepto y la etapa), leído por CreativeTrackingId;
 * - meta_creatives: su producto (la identidad es producto + código, para que el C004 de un producto no se junte con el
 *   de otro), el nombre que se muestra y lo mismo que sus anuncios;
 * - ad_angles: el diccionario de ángulos de cada producto (el sistema junta los códigos, la IA propone el nombre y la
 *   hipótesis, el dueño confirma). Un código que es lo mismo que otro (FAS y FASP) apunta a él con alias_of;
 * - product_ad_settings: el modo de cada producto (normal, liquidación, pausado o abandonado), que usan las reglas.
 * Solo agrega columnas y tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads', function (Blueprint $table) {
            $table->string('creative_code', 40)->nullable()->after('tracking_id_source');
            $table->string('angle_code', 12)->nullable()->after('creative_code');
            $table->string('batch_code', 16)->nullable()->after('angle_code');
            $table->string('ref_code', 16)->nullable()->after('batch_code');
            $table->string('iteration_of', 40)->nullable()->after('ref_code');
        });
        Schema::table('meta_adsets', function (Blueprint $table) {
            $table->string('batch_code', 16)->nullable()->after('optimization_goal');
            $table->string('angle_code', 12)->nullable()->after('batch_code');
            $table->string('concept', 24)->nullable()->after('angle_code');
            $table->string('stage', 8)->nullable()->after('concept'); // test o proven
        });
        Schema::table('meta_creatives', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('tracking_id')->constrained()->nullOnDelete();
            $table->string('label')->nullable()->after('product_id');
            $table->string('creative_code', 40)->nullable()->after('label');
            $table->string('angle_code', 12)->nullable()->after('creative_code');
            $table->string('batch_code', 16)->nullable()->after('angle_code');
            $table->string('iteration_of', 40)->nullable()->after('batch_code');
            $table->index(['product_id', 'angle_code']);
        });
        Schema::create('ad_angles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12);
            $table->foreignId('alias_of')->nullable()->constrained('ad_angles')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->text('hypothesis')->nullable();
            $table->string('status', 12)->default('detected'); // detected, proposed, confirmed
            $table->string('proposed_by', 8)->nullable();      // ai u owner
            $table->string('suggested_alias', 12)->nullable(); // la IA cree que es lo mismo que este código
            $table->timestamp('proposed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });
        Schema::create('product_ad_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 12)->default('normal'); // normal, liquidation, paused, abandoned
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ad_settings');
        Schema::dropIfExists('ad_angles');
        Schema::table('meta_creatives', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'angle_code']);
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['label', 'creative_code', 'angle_code', 'batch_code', 'iteration_of']);
        });
        Schema::table('meta_adsets', function (Blueprint $table) {
            $table->dropColumn(['batch_code', 'angle_code', 'concept', 'stage']);
        });
        Schema::table('meta_ads', function (Blueprint $table) {
            $table->dropColumn(['creative_code', 'angle_code', 'batch_code', 'ref_code', 'iteration_of']);
        });
    }
};
