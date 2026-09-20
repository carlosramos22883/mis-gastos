<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->foreignId('compromiso_id')->nullable()->after('categoria_personal_id')->constrained('compromisos')->nullOnDelete();
            $table->unsignedInteger('compromiso_cuota')->nullable()->after('compromiso_id');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_efectivo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compromiso_id');
            $table->dropColumn('compromiso_cuota');
        });
    }
};
