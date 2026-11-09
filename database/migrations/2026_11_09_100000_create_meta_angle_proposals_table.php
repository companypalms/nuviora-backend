<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.116`: el ángulo que propone la IA cuando a un producto se le acaban los de su ficha, y lo que decidió el dueño: ese
 * ángulo, otro que él recomiende o uno de los que ya están. Solo lo ve el dueño, en su calendario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_angle_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('name', 120)->nullable();
            $table->text('about')->nullable();
            $table->text('why')->nullable();
            $table->string('status', 12)->default('pending');
            $table->string('decided_angle', 20)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_angle_proposals');
    }
};
