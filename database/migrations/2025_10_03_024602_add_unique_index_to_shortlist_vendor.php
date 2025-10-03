<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortlist_vendor', function (Blueprint $table) {
            // tambahkan index unik untuk pasangan shortlist_vendor_id + data_vendor_id
            $table->unique(
                ['shortlist_vendor_id', 'data_vendor_id'],
                'uniq_shortlist_vendor_vendor'
            );
        });
    }

    public function down(): void
    {
        Schema::table('shortlist_vendor', function (Blueprint $table) {
            $table->dropUnique('uniq_shortlist_vendor_vendor');
        });
    }
};
