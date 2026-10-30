<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los editores en la cola de producción (Etapa 5, paso 5a, 2026-10-30): los roles "Editor líder" y "Editor de video", a
 * quién se le asignó cada encargo y sus entregas (V01, V02…), cada una con un enlace de Drive por pieza, la ficha del
 * editor y lo que dijo la revisión. Solo agrega: dos roles, columnas nuevas y una tabla.
 */
return new class extends Migration
{
    private const ROLES = ['Editor líder', 'Editor de video'];

    public function up(): void
    {
        foreach (self::ROLES as $role) {
            DB::table('roles')->insertOrIgnore(['description' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('creative_batches', function (Blueprint $table) {
            $table->foreignId('editor_id')->nullable()->after('learning')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('editor_id');
            $table->foreignId('assigned_by')->nullable()->after('assigned_at')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('assigned_by');      // "Empezar"
            $table->timestamp('delivered_at')->nullable()->after('started_at');     // la primera entrega (entregas a tiempo)
        });

        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            $table->string('link', 1000)->nullable()->after('format');             // el enlace de Drive de la última entrega
            $table->string('hook', 300)->nullable()->after('link');
        });

        Schema::create('creative_batch_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_batch_id')->constrained('creative_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');                                // 1 = V01
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('concept', 255)->nullable();                             // la ficha del editor
            $table->string('angle', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('pieces');                                                 // [{piece_id, code, link, hook}]
            $table->string('review', 10)->default('pending');                       // pending, approved o returned
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comment')->nullable();                                    // por qué se devolvió
            $table->timestamps();
            $table->unique(['creative_batch_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_batch_deliveries');
        Schema::table('creative_batch_pieces', fn (Blueprint $table) => $table->dropColumn(['link', 'hook']));
        Schema::table('creative_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editor_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['assigned_at', 'started_at', 'delivered_at']);
        });
        // Los roles se quitan solo si nadie los tiene
        DB::table('roles')->whereIn('description', self::ROLES)->whereNotExists(fn ($q) => $q->from('users')->whereColumn('users.role_id', 'roles.id'))->delete();
    }
};
