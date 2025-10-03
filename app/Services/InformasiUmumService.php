<?php

namespace App\Services;

use App\Models\InformasiUmum;
use App\Models\PerencanaanData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InformasiUmumService
{
    public function getDataInformasiUmumById($informasiUmumId)
    {
        return InformasiUmum::find($informasiUmumId);
    }

    public function checkNamaPaket($namaPaket)
    {
        return InformasiUmum::where('nama_paket', $namaPaket)->exists();
    }

    public function saveInformasiUmum($dataInformasiUmum, $balaiId)
    {
        // --- Ambil field dari payload (tanpa Request) ---
        $namaPaket     = trim((string) data_get($dataInformasiUmum, 'nama_paket'));
        $tipeInformasi = (string) data_get($dataInformasiUmum, 'tipe_informasi_umum'); // 'sipasti' | 'manual'
        $kodeRup       = data_get($dataInformasiUmum, 'kode_rup', '');
        $jabatanPPK    = data_get($dataInformasiUmum, 'jabatan_ppk');
        $namaPPK       = data_get($dataInformasiUmum, 'nama_ppk');
        $tipologi      = data_get($dataInformasiUmum, 'tipologi', '');

        // --- Validasi ringan ---
        if ($namaPaket === '') {
            throw ValidationException::withMessages(['nama_paket' => ['Nama paket wajib diisi.']]);
        }
        if ($balaiId === '') {
            throw ValidationException::withMessages(['balai_id' => ['Balai tidak valid.']]);
        }

        // --- Cek duplikat per (balai_id, nama_paket) ---
        $exists = InformasiUmum::query()
            ->where('nama_balai', $balaiId)
            ->whereRaw('LOWER(nama_paket) = ?', [mb_strtolower($namaPaket)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'nama_paket' => ['Nama paket sudah digunakan pada balai ini.']
            ]);
        }

        return DB::transaction(function () use ($balaiId, $namaPaket, $kodeRup, $jabatanPPK, $namaPPK, $tipeInformasi, $tipologi) {
            $informasiUmum = InformasiUmum::create([
                'nama_balai'      => $balaiId,
                'nama_paket'      => $namaPaket,
                'kode_rup'        => $kodeRup,
                'jabatan_ppk'     => $jabatanPPK,
                'nama_ppk'        => $namaPPK,
                'jenis_informasi' => $tipeInformasi,    
                'tipologi'        => $tipologi,
                'nama_balai'      => $balaiId
            ]);

            $ok = $this->savePerencanaanData($informasiUmum->id, 'informasi_umum_id');
            if (!$ok) {
                throw ValidationException::withMessages([
                    'perencanaan_data' => ['Gagal menyimpan data perencanaan.']
                ]);
            }

            return $informasiUmum;
        });
    }

    private function savePerencanaanData($id, $namaField)
    {
        $data = PerencanaanData::updateOrCreate(
            [
                $namaField => $id,
            ]
        );
        return $data;
    }

    public function getInformasiUmumByPerencanaanId($id)
    {
        return InformasiUmum::with('perencanaanData')
            ->select(
                'kode_rup',
                'nama_paket',
                'nama_ppk',
                'jabatan_ppk',
                'nama_balai',
                'tipologi',
                'jenis_informasi'
            )
            ->where('id', $id)
            ->get()->first();
    }
}
