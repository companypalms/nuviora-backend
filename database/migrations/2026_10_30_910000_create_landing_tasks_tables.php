<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los encargos de landing (Etapa 5, paso 6b, 2026-10-30): el rol "Editor de landings", cada encargo (L####) con lo que se
 * vio, la hipótesis, qué cambiar y qué conservar, sus entregas (el enlace de vista previa de Shopify y qué cambió) y el
 * test antes/después con los mismos anuncios cuando se publica. Por ahora las landings las hace el dueño (puede todo).
 * Solo agrega: un rol y dos tablas.
 */
return new class extends Migration
{
    private const ROLE = 'Editor de landings';

    public function up(): void
    {
        DB::table('roles')->insertOrIgnore(['description' => self::ROLE, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('landing_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable()->unique();     // L0123 = 123; se da al aprobar
            $table->string('status', 16)->default('proposed');           // propuesto, aprobado, en producción, en revisión, para publicar, en test, testeado, descartado
            $table->string('kind', 16)->default('angle');                // velocidad, ángulo, landing nueva, oferta o contenido
            $table->foreignId('landing_id')->nullable()->constrained('landings')->nullOnDelete(); // la que se cambia (vacío = una nueva)
            $table->string('angle_code', 12)->nullable();
            $table->text('observed')->nullable();                        // qué se vio (los números)
            $table->text('hypothesis')->nullable();
            $table->text('change')->nullable();                          // qué cambiar
            $table->text('keep')->nullable();                            // qué conservar
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->string('source', 12)->default('owner');              // owner (a mano) o rules (propuesta, paso 6c)
            $table->date('proposed_on')->nullable();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();               // la primera entrega (entregas a tiempo)
            $table->timestamp('status_at')->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('discard_reason', 255)->nullable();
            $table->timestamp('published_at')->nullable();               // "Publicada": empieza el test
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_url', 1000)->nullable();
            $table->foreignId('test_landing_id')->nullable()->constrained('landings')->nullOnDelete(); // la landing que se mide
            $table->timestamp('tested_at')->nullable();
            $table->json('result')->nullable();                          // antes y después con los mismos anuncios, y el veredicto
            $table->text('learning')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });

        Schema::create('landing_task_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_task_id')->constrained('landing_tasks')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');                     // 1 = V01
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('preview_url', 1000);                         // la vista previa de Shopify
            $table->text('notes');                                       // qué cambió
            $table->string('review', 12)->default('pending');            // pending, approved, returned
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['landing_task_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_task_deliveries');
        Schema::dropIfExists('landing_tasks');
        // El rol se quita solo si nadie lo tiene
        DB::table('roles')->where('description', self::ROLE)->whereNotExists(fn ($q) => $q->from('users')->whereColumn('users.role_id', 'roles.id'))->delete();
    }
};
