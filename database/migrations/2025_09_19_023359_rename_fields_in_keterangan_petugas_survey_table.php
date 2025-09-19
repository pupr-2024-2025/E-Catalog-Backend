<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('keterangan_petugas_survey', function (Blueprint $table) {
            $table->renameColumn('tanggal_survey', 'tanggal_survei');
            $table->renameColumn('catatan', 'catatan_blok_v');
        });
    }

    public function down(): void
    {
        Schema::table('keterangan_petugas_survey', function (Blueprint $table) {
            $table->renameColumn('tanggal_survei', 'tanggal_survey');
            $table->renameColumn('catatan_blok_v', 'catatan');
        });
    }
};
