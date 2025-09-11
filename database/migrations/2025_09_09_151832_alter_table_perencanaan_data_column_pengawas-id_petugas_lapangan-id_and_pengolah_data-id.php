<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('perencanaan_data', function (Blueprint $table) {
            $table->longText('pengawas_id')->change();
            $table->longText('petugas_lapangan_id')->change();
            $table->longText('pengolah_data_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('perencanaan_data', function (Blueprint $table) {
            $table->json('pengawas_id')->change();
            $table->json('petugas_lapangan_id')->change();
            $table->json('pengolah_data_id')->change();
        });
    }
};
