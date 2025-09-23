<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeteranganPetugasSurvey extends Model
{
    use HasFactory;

    protected $table = 'keterangan_petugas_survey';
    protected $fillable = [
        'identifikasi_kebutuhan_id',
        'petugas_lapangan_id',
        'pengawas_id',
        'tanggal_survei',
        'tanggal_pengawasan',
        'nama_pemberi_informasi',
        'catatan_blok_v',
    ];
}
