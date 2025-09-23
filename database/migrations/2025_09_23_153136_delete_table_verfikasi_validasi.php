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
        Schema::dropIfExists("verfikasi_validasi");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::create('verifikasi_validasi', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('data_vendor_id');
            $table->bigInteger('shortlist_vendor_id');
            $table->string('item_number');
            $table->string('status_pemeriksaan');
            $table->string('verified_by');
            $table->timestamps();     // created_at & updated_at
            $table->softDeletes();    // deleted_at
        });
    }
};
