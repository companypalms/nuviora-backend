<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPS falso (informe del 27/10, sección 06 punto 2; Fran: "que se aplique todo"): Android avisa cuando la ubicación viene
 * de una app que la simula. El punto queda marcado y el rider, marcado hasta que administración lo revise: mientras tanto
 * no recibe rondas (se haría pasar por cercano para ganarse las alarmas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_locations', function (Blueprint $table) {
            $table->boolean('mocked')->default(false)->after('speed');
        });
        Schema::table('rider_profiles', function (Blueprint $table) {
            $table->timestamp('mock_location_at')->nullable()->after('last_location_at');
        });
    }

    public function down(): void
    {
        Schema::table('rider_locations', fn (Blueprint $table) => $table->dropColumn('mocked'));
        Schema::table('rider_profiles', fn (Blueprint $table) => $table->dropColumn('mock_location_at'));
    }
};
