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
            $table->longText('team_teknis_balai_id')->change();
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
            $table->bigInteger('team_teknis_balai_id')->change();
        });
    }
};
