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
        $balaiColumn   = 'nama_balai'; // ganti ke 'balai_id' kalau itu kolom FK yang benar
        $idPayload     = data_get($dataInformasiUmum, 'id_informasi_umum'); // opsional, kalau ada berarti edit
        $namaPaket     = trim((string) data_get($dataInformasiUmum, 'nama_paket'));
        $tipeInformasi = (string) data_get($dataInformasiUmum, 'tipe_informasi_umum'); // 'sipasti' | 'manual'
        $kodeRup       = (string) data_get($dataInformasiUmum, 'kode_rup', '');
        $jabatanPPK    = data_get($dataInformasiUmum, 'jabatan_ppk');
        $namaPPK       = data_get($dataInformasiUmum, 'nama_ppk');
        $tipologi      = (string) data_get($dataInformasiUmum, 'tipologi', '');

        if ($namaPaket === '') {
            throw ValidationException::withMessages(['nama_paket' => ['Nama paket wajib diisi.']]);
        }
        if ($balaiId === '') {
            throw ValidationException::withMessages(['balai_id' => ['Balai tidak valid.']]);
        }

        return DB::transaction(function () use (
            $balaiColumn,
            $balaiId,
            $idPayload,
            $namaPaket,
            $tipeInformasi,
            $kodeRup,
            $jabatanPPK,
            $namaPPK,
            $tipologi
        ) {
            // 1) Selalu batasi scope pada balai yang dimaksud
            $queryBalai = InformasiUmum::query()->where($balaiColumn, $balaiId);

            if (!empty($idPayload)) {
                $row = (clone $queryBalai)->where('id', $idPayload)->first();
                if (!$row) {
                    throw ValidationException::withMessages([
                        'id' => ['Data tidak ditemukan pada balai ini.']
                    ]);
                }

                // Cek unik nama_paket pada balai yang sama, exclude dirinya
                $dupe = (clone $queryBalai)
                    ->whereRaw('LOWER(nama_paket) = ?', [mb_strtolower($namaPaket)])
                    ->where('id', '!=', $row->id)
                    ->exists();

                if ($dupe) {
                    throw ValidationException::withMessages([
                        'nama_paket' => ['Nama paket sudah digunakan pada balai ini.']
                    ]);
                }
                
                $row->fill([
                    $balaiColumn      => $balaiId, 
                    'nama_paket'      => $namaPaket,
                    'kode_rup'        => $kodeRup,
                    'jabatan_ppk'     => $jabatanPPK,
                    'nama_ppk'        => $namaPPK,
                    'jenis_informasi' => $tipeInformasi,
                    'tipologi'        => $tipologi,
                ])->save();

                $ok = $this->savePerencanaanData($row->id, 'informasi_umum_id');
                if (!$ok) {
                    throw ValidationException::withMessages([
                        'perencanaan_data' => ['Gagal menyimpan data perencanaan.']
                    ]);
                }

                return $row;
            }

            // ====== MODE CREATE ======
            // Cek duplikat nama_paket pada balai yang sama
            $exists = (clone $queryBalai)
                ->whereRaw('LOWER(nama_paket) = ?', [mb_strtolower($namaPaket)])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'nama_paket' => ['Nama paket sudah digunakan pada balai ini.']
                ]);
            }

            // Buat baru
            $row = InformasiUmum::create([
                $balaiColumn      => $balaiId,
                'nama_paket'      => $namaPaket,
                'kode_rup'        => $kodeRup,
                'jabatan_ppk'     => $jabatanPPK,
                'nama_ppk'        => $namaPPK,
                'jenis_informasi' => $tipeInformasi,
                'tipologi'        => $tipologi,
            ]);

            $ok = $this->savePerencanaanData($row->id, 'informasi_umum_id');
            if (!$ok) {
                throw ValidationException::withMessages([
                    'perencanaan_data' => ['Gagal menyimpan data perencanaan.']
                ]);
            }

            return $row;
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
