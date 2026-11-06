<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `.107` "Tu día": la prioridad, los minutos y el lugar que el dueño le pone a cada cosa de su calendario, para todos los
 * días. Una fila por cosa ("note:12", "rule:rate", "expense:5", o "expense:*" para todos los gastos fijos); sin fila, la
 * cosa usa lo de siempre (DayPlan). rank: el orden que puso arrastrando dentro de su prioridad (null = el automático).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_item_plans', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 60)->unique();
            $table->string('priority', 8);                    // alta | normal | baja
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('rank')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_item_plans');
    }
};
