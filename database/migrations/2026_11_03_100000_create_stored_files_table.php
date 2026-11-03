<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.90` (dueño, 2026-11-03: "es más práctico que se suban todos los archivos en el sistema en vez de utilizar Google
 * Drive"): los archivos guardados en el almacén (Cloudflare R2 en el servidor; la carpeta privada en local): las piezas
 * que entregan los editores, las fotos y el documento de estrategia de cada producto. Se guardan para siempre (decisión
 * del dueño). Solo agrega: una tabla y una columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 10);                                          // r2 o local: dónde quedó
            $table->string('path', 500)->unique();                                 // su llave en el almacén
            $table->string('name', 255);                                           // el nombre al verlo o bajarlo ("C0457 POSP.mp4")
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('status', 10)->default('pending');                      // pending (subiendo) o ready
            $table->string('kind', 20);                                            // piece, photo o strategy
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('creative_batch_piece_id')->nullable()->constrained('creative_batch_pieces')->nullOnDelete();
            $table->unsignedSmallInteger('version')->nullable();                   // la entrega (1 = V01) de una pieza
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'kind', 'status']);
        });

        Schema::table('creative_batch_pieces', function (Blueprint $table) {
            // el archivo de la última entrega (el enlace de Drive sigue para las entregas viejas)
            $table->foreignId('file_id')->nullable()->after('link')->constrained('stored_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creative_batch_pieces', fn (Blueprint $table) => $table->dropConstrainedForeignId('file_id'));
        Schema::dropIfExists('stored_files');
    }
};
