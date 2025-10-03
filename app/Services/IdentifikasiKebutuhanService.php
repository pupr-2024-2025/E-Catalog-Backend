<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Peralatan;
use App\Models\TenagaKerja;
use Illuminate\Validation\ValidationException;

class IdentifikasiKebutuhanService
{
    public function storeMaterial(array $dataMaterial, string $identifikasiKebutuhanId)
    {
        $row = !empty($dataMaterial['id'])
            ? Material::find($dataMaterial['id'])
            : new Material();

        if ($row && $row->exists && $row->identifikasi_kebutuhan_id !== $identifikasiKebutuhanId) {
            throw ValidationException::withMessages([
                'id' => ['Tidak boleh mengubah data milik identifikasi lain.']
            ]);
        }

        $row->identifikasi_kebutuhan_id = $identifikasiKebutuhanId;
        $row->fill([
            'nama_material'       => $dataMaterial['nama_material'] ?? null,
            'spesifikasi'         => $dataMaterial['spesifikasi'] ?? null,
            'satuan'              => $dataMaterial['satuan'] ?? null,
            'ukuran'              => $dataMaterial['ukuran'] ?? null,
            'kodefikasi'          => $dataMaterial['kodefikasi'] ?? null,
            'kelompok_material'   => $dataMaterial['kelompok_material'] ?? null,
            'jumlah_kebutuhan'    => $dataMaterial['jumlah_kebutuhan'] ?? null,
            'merk'                => $dataMaterial['merk'] ?? null,
            'provincies_id'       => $dataMaterial['provincies_id'] ?? null,
            'cities_id'           => $dataMaterial['cities_id'] ?? null,
        ])->save();

        return $row->refresh();
    }

    public function storePeralatan(array $dataPeralatan, string  $identifikasiKebutuhanId)
    {
        $row = !empty($dataPeralatan['id'])
            ? Peralatan::find($dataPeralatan['id'])
            : new Peralatan();

        if ($row && $row->exists && $row->identifikasi_kebutuhan_id !== $identifikasiKebutuhanId) {
            throw ValidationException::withMessages([
                'id' => ['Tidak boleh mengubah data milik identifikasi lain.']
            ]);
        }

        $row->identifikasi_kebutuhan_id = $identifikasiKebutuhanId;
        $row->fill([
            'nama_peralatan'      => $dataPeralatan['nama_peralatan'] ?? null,
            'spesifikasi'         => $dataPeralatan['spesifikasi'] ?? null,
            'satuan'              => $dataPeralatan['satuan'] ?? null,
            'kapasitas'           => $dataPeralatan['kapasitas'] ?? null,
            'kodefikasi'          => $dataPeralatan['kodefikasi'] ?? null,
            'kelompok_peralatan'  => $dataPeralatan['kelompok_peralatan'] ?? null,
            'jumlah_kebutuhan'    => $dataPeralatan['jumlah_kebutuhan'] ?? null,
            'merk'                => $dataPeralatan['merk'] ?? null,
            'provincies_id'       => $dataPeralatan['provincies_id'] ?? null,
            'cities_id'           => $dataPeralatan['cities_id'] ?? null,
        ])->save();

        return $row->refresh();
    }

    public function storeTenagaKerja(array $dataTenagaKerja, string  $identifikasiKebutuhanId)
    {
        $row = !empty($dataTenagaKerja['id'])
            ? TenagaKerja::find($dataTenagaKerja['id'])
            : new TenagaKerja();

        if ($row && $row->exists && $row->identifikasi_kebutuhan_id !== $identifikasiKebutuhanId) {
            throw ValidationException::withMessages([
                'id' => ['Tidak boleh mengubah data milik identifikasi lain.']
            ]);
        }

        $row->identifikasi_kebutuhan_id = $identifikasiKebutuhanId;
        $row->fill([
            'jenis_tenaga_kerja'  => $dataTenagaKerja['jenis_tenaga_kerja'] ?? null,
            'kodefikasi'          => $dataTenagaKerja['kodefikasi'] ?? null,
            'satuan'              => $dataTenagaKerja['satuan'] ?? null,
            'jumlah_kebutuhan'    => $dataTenagaKerja['jumlah_kebutuhan'] ?? null,
            'provincies_id'       => $dataTenagaKerja['provincies_id'] ?? null,
            'cities_id'           => $dataTenagaKerja['cities_id'] ?? null,
        ])->save();

        return $row->refresh();
    }

    public function getIdentifikasiKebutuhanByPerencanaanId($jenisIdentifikasi, $id)
    {
        if ($jenisIdentifikasi == 'material') {
            return Material::where('identifikasi_kebutuhan_id', $id)->get();
        } elseif ($jenisIdentifikasi == 'peralatan') {
            return Peralatan::where('identifikasi_kebutuhan_id', $id)->get();
        } elseif ($jenisIdentifikasi == 'tenaga_kerja') {
            return TenagaKerja::where('identifikasi_kebutuhan_id', $id)->get();
        }
        return false;
    }
}
