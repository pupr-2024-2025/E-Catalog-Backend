<?php

namespace App\Services;

use App\Models\KategoriVendor;
use App\Models\KeteranganPetugasSurvey;
use App\Models\Material;
use App\Models\MaterialSurvey;
use App\Models\Pengawas;
use App\Models\PengolahData;
use App\Models\Peralatan;
use App\Models\PeralatanSurvey;
use App\Models\PerencanaanData;
use App\Models\PetugasLapangan;
use App\Models\Roles;
use App\Models\ShortlistVendor;
use App\Models\TeamTeknisBalai;
use App\Models\TenagaKerja;
use App\Models\TenagaKerjaSurvey;
use App\Models\Users;
use App\Models\VerifikasiValidasi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PengumpulanDataService
{
    public function storeTeamPengumpulanData($data)
    {
        return DB::transaction(function () use ($data) {
            // 1) Buat Team
            $team = TeamTeknisBalai::create([
                'nama_team'          => $data['nama_team'],
                'user_id_ketua'      => $data['ketua_team'] ?? null,
                'user_id_sekretaris' => $data['sekretaris_team'] ?? null,
                'user_id_anggota'    => $data["anggota"],        // pastikan cast array di model
                'url_sk_penugasan'   => $data['relative_path'],  // diisi relative pathnya
            ]);

            // 2) Kumpulkan semua user id: ketua, sekretaris, anggota
            $allMembers = array_merge(
                [$data['ketua_team'] ?? null],
                [$data['sekretaris_team'] ?? null],
                $data['anggota'] ?? []
            );

            // Buang null/kosong, paksa ke string, dan unik (jaga urutan: ketua, sekretaris, lalu anggota)
            $allMembers = array_values(array_unique(array_map('strval', array_filter($allMembers, function ($v) {
                return $v !== null && $v !== '';
            }))));

            // 3) Update PerencanaanData: simpan sebagai JSON
            // $idInformasiUmum = $data['informasi_umum_id']; // asumsi single value
            // PerencanaanData::where('informasi_umum_id', '=', $idInformasiUmum)
            //     ->update([
            //         'team_teknis_balai_id' => json_encode($allMembers),
            //     ]);

            // 4) Update role & surat penugasan semua anggota (ketua+sekretaris+anggota)
            if (!empty($allMembers)) {
                Users::whereIn('id', $allMembers)->update([
                    'id_roles'             => 2, // Tim Teknis Balai
                    'surat_penugasan_url'  => $data['sk_penugasan'], // kirim full urlnya
                ]);
            }

            return $team;
        });
    }

    public function getAllTeamPengumpulanData()
    {
        return TeamTeknisBalai::select('id', 'nama_team')->get();
    }

    public function assignTeamPengumpulanData($data)
    {
        return PerencanaanData::updateOrCreate(
            [
                'id' => $data['id_pengumpulan_data'],
            ],
            [
                'team_pengumpulan_data_id' => $data['id_team_pengumpulan_data'],
            ]
        );
    }

    public function listUserPengumpulan($role)
    {
        $query = Users::select('id AS user_id', 'nama_lengkap')
            ->where('status', 'active')
            ->where('id_roles', $role)
            ->whereNotNull('email_verified_at')
            ->whereNot('id_roles', 1)->get();

        $query = $query->filter(function ($item) use ($role) {
            $exists = false;

            if ($role == 'pengawas') {
                $exists = PerencanaanData::whereJsonContains('pengawas_id', (string) $item->user_id)->exists();
            } elseif ($role == 'pengolah data') {
                $exists = PerencanaanData::whereJsonContains('pengolah_data_id', (string) $item->user_id)->exists();
            } elseif ($role == 'petugas lapangan') {
                $exists = PerencanaanData::whereJsonContains('petugas_lapangan_id', (string) $item->user_id)->exists();
            }

            return !$exists;
        })->values();

        return $query;
    }

    public function getListRoles($rolesString)
    {
        $role = Roles::select('id')
            ->where('nama', $rolesString)->first();

        if ($role) {
            return $role->id;
        }
        return [];
    }

    public function listPenugasan($table)
    {
        if ($table == 'pengawas') {
            $data = Pengawas::select(
                'pengawas.id as pengawas_id',
                'pengawas.sk_penugasan',
                'users.nama_lengkap',
                'users.id as id_user',
                'users.nrp',
                'satuan_kerja.nama as satuan_kerja_nama',
            )
                ->join('users', 'pengawas.user_id', '=', 'users.id')
                ->join('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
                ->get();

            $data->transform(function ($item) {
                $exists = PerencanaanData::whereJsonContains('pengawas_id', (string) $item->id_user)
                    ->exists();

                $item->status = $exists ? 'ditugaskan' : 'belum ditugaskan';
                $item->url_sk_penugasan = Storage::url($item->sk_penugasan);
                unset($item->sk_penugasan);
                return $item;
            });

            return $data;
        } elseif ($table == 'pengolah data') {
            $data = PengolahData::select(
                'pengolah_data.id as pengolah_data_id',
                'pengolah_data.sk_penugasan',
                'users.nama_lengkap',
                'users.id as id_user',
                'users.nrp',
                'satuan_kerja.nama as satuan_kerja_nama',
            )
                ->join('users', 'pengolah_data.user_id', '=', 'users.id')
                ->join('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
                ->get();

            $data->transform(function ($item) {
                $exists = PerencanaanData::whereJsonContains('pengolah_data_id', (string) $item->id_user)
                    ->exists();

                $item->status = $exists ? 'ditugaskan' : 'belum ditugaskan';
                $item->url_sk_penugasan = Storage::url($item->sk_penugasan);
                unset($item->sk_penugasan);
                return $item;
            });

            return $data;
        } elseif ($table == 'petugas lapangan') {
            $data = PetugasLapangan::select(
                'petugas_lapangan.id as petugas_lapangan_id',
                'petugas_lapangan.sk_penugasan',
                'users.nama_lengkap',
                'users.id as id_user',
                'users.nrp',
                'satuan_kerja.nama as satuan_kerja_nama',
            )
                ->join('users', 'petugas_lapangan.user_id', '=', 'users.id')
                ->join('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
                ->get();

            $data->transform(function ($item) {
                $exists = PerencanaanData::whereJsonContains('petugas_lapangan_id', (string) $item->id_user)
                    ->exists();

                $item->status = $exists ? 'ditugaskan' : 'belum ditugaskan';
                $item->url_sk_penugasan = Storage::url($item->sk_penugasan);
                unset($item->sk_penugasan);
                return $item;
            });

            return $data;
        }
    }

    public function assignPenugasan($table, $idTable, $idPerencanaan)
    {
        $array = explode(',', $idTable);
        $arrayPerson = array_map('intval', $array);

        $userExists = Users::whereIn('id', $array)->get();
        $countUserExists = $userExists->count();

        if (count($arrayPerson) != $countUserExists) {
            return [];
        }

        if ($table == 'pengawas') {
            return PerencanaanData::updateOrCreate(
                [
                    'id' => $idPerencanaan,
                ],
                [
                    'pengawas_id' => $array,
                ]
            );
        } elseif ($table == 'pengolah data') {
            return PerencanaanData::updateOrCreate(
                [
                    'id' => $idPerencanaan,
                ],
                [
                    'pengolah_data_id' => $array,
                ]
            );
        } elseif ($table == 'petugas lapangan') {
            return PerencanaanData::updateOrCreate(
                [
                    'id' => $idPerencanaan,
                ],
                [
                    'petugas_lapangan_id' => $array,
                ]
            );
        }
    }

    public function listVendorByPerencanaanId($perencanaanId)
    {
        return ShortlistVendor::select(
            'id As shortlist_id',
            'shortlist_vendor_id As informasi_umum_id',
            'nama_vendor',
            'pemilik_vendor As pic',
            'alamat As alamat_vendor'
        )
            ->where('shortlist_vendor_id', $perencanaanId)
            ->get();
    }

    public function showKuisioner($shortlistId)
    {
        return ShortlistVendor::select(
            'url_kuisioner'
        )
            ->where('id', $shortlistId)
            ->first();
    }

    public function generateLinkKuisioner($id)
    {
        $expireDays = (int) config('constants.SURVEY_LINK_EXPIRE_DAYS', 10);

        $now = now();
        $payload = [
            'shortlist_id' => $id,
            'timestamp'    => $now->timestamp,
            'expired_at'   => $now->copy()->addDays($expireDays)->timestamp,
        ];

        $encryptedToken = Crypt::encryptString(json_encode($payload));

        $url = URL::to('/api/survey-kuisioner/get-data-survey') . '?token=' . urlencode($encryptedToken);

        return [
            'url'        => $url,
            'token'      => $encryptedToken,
            'expired_at' => $payload['expired_at'],
        ];
    }

    private function getPengawasPetugasLapangan($shortlistId)
    {
        $data = PerencanaanData::select(
            'id',
            'pengawas_id',
            'petugas_lapangan_id',
        )->where('shortlist_vendor_id', $shortlistId)->first();

        if (!$data) {
            return response()->json(['message' => 'Data not found']);
        }

        $pengawasIds = json_decode($data->pengawas_id);
        $petugasLapanganIds = json_decode($data->petugas_lapangan_id);

        $pengawas = Users::whereIn('id', $pengawasIds)->get(['nama_lengkap', 'nrp', 'id']);
        $petugasLapangan = Users::whereIn('id', $petugasLapanganIds)->get(['nama_lengkap', 'nrp', 'id']);

        $responseData = [
            'id' => $data->id,
            'pengawas' => $pengawas->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'name' => $user->nama_lengkap,
                    'nip' => $user->nrp,
                ];
            }),
            'petugas_lapangan' => $petugasLapangan->map(function ($user) {
                return [
                    'user_id' => $user->id,
                    'name' => $user->nama_lengkap,
                    'nip' => $user->nrp,
                ];
            }),
        ];

        return $responseData;
    }

    public function getDataForKuisioner($shortlistId)
    {
        $vendor = ShortlistVendor::select(
            'data_vendors.nama_vendor',
            'data_vendors.kategori_vendor_id',
            'data_vendors.alamat',
            'data_vendors.no_telepon',
            'data_vendors.provinsi_id',
            'data_vendors.kota_id',
            'provinces.nama_provinsi',
            'cities.nama_kota',
            'shortlist_vendor.shortlist_vendor_id As identifikasi_kebutuhan_id',
            'shortlist_vendor.petugas_lapangan_id',
            'shortlist_vendor.pengawas_id',
            'shortlist_vendor.nama_pemberi_informasi',
            'shortlist_vendor.tanggal_survei',
            'shortlist_vendor.tanggal_pengawasan',
            'data_vendors.id As vendor_id',
            'kuisioner_pdf_data.material_id',
            'kuisioner_pdf_data.peralatan_id',
            'kuisioner_pdf_data.tenaga_kerja_id',
        )
            ->join('data_vendors', 'shortlist_vendor.data_vendor_id', '=', 'data_vendors.id')
            ->join('provinces', 'data_vendors.provinsi_id', '=', 'provinces.kode_provinsi')
            ->join('cities', 'data_vendors.kota_id', '=', 'cities.kode_kota')
            ->join('kuisioner_pdf_data', 'data_vendors.id', '=', 'kuisioner_pdf_data.vendor_id')
            ->where('shortlist_vendor.id', $shortlistId)
            ->first();

        if (!$vendor) {
            throw new \Exception("Data tidak ditemukan untuk shortlist_id: {$shortlistId}");
        }

        $keteranganPetugas = $this->getKeteranganPetugas($vendor['petugas_lapangan_id']);
        $keteranganPengawas = $this->getKeteranganPetugas($vendor['pengawas_id']);

        $material = $this->getIdentifikasiSurvey('material', $vendor['material_id']);
        $peralatan = $this->getIdentifikasiSurvey('peralatan', $vendor['peralatan_id']);
        $tenagaKerja = $this->getIdentifikasiSurvey('tenaga_kerja', $vendor['tenaga_kerja_id']);

        $kategoriVendor = KategoriVendor::whereIn('id', json_decode($vendor['kategori_vendor_id'], true))
            ->select('nama_kategori_vendor as name')
            ->get();
        $stringKategoriVendor = $kategoriVendor->pluck('name')->implode(', ');

        $response = [
            'data_vendor_id' => $vendor['vendor_id'],
            'identifikasi_kebutuhan_id' => $vendor['identifikasi_kebutuhan_id'],
            'provinsi' => $vendor['nama_provinsi'],
            'kota' => $vendor['nama_kota'],
            'nama_responden' => $vendor['nama_vendor'],
            'alamat' => $vendor['alamat'],
            'no_telepon' => $vendor['no_telepon'],
            'kategori_responden' => $stringKategoriVendor,
            'keterangan_petugas_lapangan' => [
                'nama_petugas_lapangan' => isset($keteranganPetugas['nama']) ? $keteranganPetugas['nama'] : null,
                'nip_petugas_lapangan' => isset($keteranganPetugas['nip']) ? $keteranganPetugas['nip'] : null,
                'tanggal_survei' => isset($vendor['tanggal_survei']) ? Carbon::createFromFormat('Y-m-d', $vendor['tanggal_survei'])->format('d-m-Y') : null,
                'nama_pengawas' => isset($keteranganPengawas['nama']) ? $keteranganPengawas['nama'] : null,
                'nip_pengawas' => isset($keteranganPengawas['nip']) ? $keteranganPengawas['nip'] : null,
                'tanggal_pengawasan' => isset($vendor['tanggal_pengawasan']) ? Carbon::createFromFormat('Y-m-d', $vendor['tanggal_pengawasan'])->format('d-m-Y') : null,
            ],
            'keterangan_pemberi_informasi' => [
                'nama_pemberi_informasi' => isset($vendor['nama_pemberi_informasi']) ? $vendor['nama_pemberi_informasi'] : null,
                'tanda_tangan_responden' => isset($vendor['nama_pemberi_informasi'])
                    ? 'Ditandatangain oleh ' . $vendor['nama_pemberi_informasi'] . ' pada ' . Carbon::now()
                    : null
            ],
            'material' => $material,
            'peralatan' => $peralatan,
            'tenaga_kerja' => $tenagaKerja,
        ];
        return $response;
    }

    private function getKeteranganPetugas($id)
    {
        return Users::select('nama_lengkap As nama', 'nrp As nip')
            ->where('id', $id)->first();
    }

    private function getIdentifikasiSurvey($table, $id)
    {
        $idArray = json_decode($id, true);
        if (!is_array($idArray) || empty($idArray)) {
            return collect();
        }

        $checkMaterial = MaterialSurvey::whereIn('material_id', $idArray)->exists();
        $checkPeralatan = PeralatanSurvey::whereIn('peralatan_id', $idArray)->exists();
        $checkTenagaKerja = TenagaKerjaSurvey::whereIn('tenaga_kerja_id', $idArray)->exists();

        if ($table == 'material') {
            if ($checkMaterial) {
                return Material::select(
                    'material.id',
                    'material.identifikasi_kebutuhan_id',
                    'material.nama_material',
                    'material.satuan',
                    'material.spesifikasi',
                    'material.ukuran',
                    'material.kodefikasi',
                    'material.kelompok_material',
                    'material.jumlah_kebutuhan',
                    'material.merk',
                    'material.provincies_id',
                    'material.cities_id',
                    'material_survey.satuan_setempat',
                    'material_survey.satuan_setempat_panjang',
                    'material_survey.satuan_setempat_lebar',
                    'material_survey.satuan_setempat_tinggi',
                    'material_survey.konversi_satuan_setempat',
                    'material_survey.harga_satuan_setempat',
                    'material_survey.harga_konversi_satuan_setempat',
                    'material_survey.harga_khusus',
                    'material_survey.keterangan',
                )
                    ->join('material_survey', 'material.id', '=', 'material_survey.material_id')
                    ->whereIn('material.id', $idArray)
                    ->get();
            } else {
                return Material::whereIn('id', $idArray)->get();
            }
        } elseif ($table == 'peralatan') {
            if ($checkPeralatan) {
                return Peralatan::select(
                    'peralatan.id',
                    'peralatan.identifikasi_kebutuhan_id',
                    'peralatan.nama_peralatan',
                    'peralatan.satuan',
                    'peralatan.spesifikasi',
                    'peralatan.kapasitas',
                    'peralatan.kodefikasi',
                    'peralatan.kelompok_peralatan',
                    'peralatan.jumlah_kebutuhan',
                    'peralatan.merk',
                    'peralatan.provincies_id',
                    'peralatan.cities_id',
                    'peralatan_survey.satuan_setempat',
                    'peralatan_survey.harga_sewa_satuan_setempat',
                    'peralatan_survey.harga_sewa_konversi',
                    'peralatan_survey.harga_pokok',
                )
                    ->join('peralatan_survey', 'peralatan.id', '=', 'peralatan_survey.peralatan_id')
                    ->whereIn('peralatan.id', $idArray)
                    ->get();
            } else {
                return Peralatan::whereIn('id', $idArray)->get();
            }
        } elseif ($table == 'tenaga_kerja') {
            if ($checkTenagaKerja) {
                return TenagaKerja::select(
                    'tenaga_kerja.id',
                    'tenaga_kerja.identifikasi_kebutuhan_id',
                    'tenaga_kerja.jenis_tenaga_kerja',
                    'tenaga_kerja.satuan',
                    'tenaga_kerja.jumlah_kebutuhan',
                    'tenaga_kerja.kodefikasi',
                    'tenaga_kerja.provincies_id',
                    'tenaga_kerja.cities_id',
                    'tenaga_kerja_survey.harga_per_satuan_setempat',
                    'tenaga_kerja_survey.harga_konversi_perjam',
                    'tenaga_kerja_survey.keterangan',
                )
                    ->join('tenaga_kerja_survey', 'tenaga_kerja.id', '=', 'tenaga_kerja_survey.tenaga_kerja_id')
                    ->whereIn('tenaga_kerja.id', $idArray)
                    ->get();
            } else {
                return TenagaKerja::whereIn('id', $idArray)->get();
            }
        }

        return collect();
    }

    public function getEntriData($shortlistId)
    {
        $sv = ShortlistVendor::select('id', 'shortlist_vendor_id', 'data_vendor_id')
            ->where('id', $shortlistId)
            ->orWhere('shortlist_vendor_id', $shortlistId)
            ->first();

        if (!$sv) {
            return null;
        }

        $vendor = ShortlistVendor::from('shortlist_vendor')
            ->select(
                'data_vendors.id AS vendor_id',
                'data_vendors.nama_vendor',
                'data_vendors.kategori_vendor_id',
                'data_vendors.alamat',
                'data_vendors.no_telepon',
                'provinces.nama_provinsi',
                'cities.nama_kota',
                'shortlist_vendor.shortlist_vendor_id AS identifikasi_kebutuhan_id',
                'shortlist_vendor.petugas_lapangan_id AS sv_petugas_lapangan_id',
                'shortlist_vendor.pengawas_id AS sv_pengawas_id',
                'shortlist_vendor.nama_pemberi_informasi AS sv_nama_pemberi_informasi',
                'shortlist_vendor.tanggal_survei AS sv_tanggal_survei',
                'shortlist_vendor.tanggal_pengawasan AS sv_tanggal_pengawasan',
                'kuisioner_pdf_data.material_id',
                'kuisioner_pdf_data.peralatan_id',
                'kuisioner_pdf_data.tenaga_kerja_id'
            )
            ->join('data_vendors', 'shortlist_vendor.data_vendor_id', '=', 'data_vendors.id')
            ->join('provinces', 'data_vendors.provinsi_id', '=', 'provinces.kode_provinsi')
            ->join('cities', 'data_vendors.kota_id', '=', 'cities.kode_kota')
            ->leftJoin('kuisioner_pdf_data', 'data_vendors.id', '=', 'kuisioner_pdf_data.vendor_id')
            ->where('shortlist_vendor.id', $sv->id)
            ->first();

        if (!$vendor) {
            return null;
        }

        $kps = KeteranganPetugasSurvey::where('identifikasi_kebutuhan_id', $vendor->identifikasi_kebutuhan_id)->first();

        $userIdPetugas  = $kps->petugas_lapangan_id ?? $vendor->sv_petugas_lapangan_id;
        $userIdPengawas = $kps->pengawas_id ?? $vendor->sv_pengawas_id;
        $namaPI         = $kps->nama_pemberi_informasi ?? $vendor->sv_nama_pemberi_informasi;

        $tanggalSurvei = $kps && $kps->tanggal_survei
            ? Carbon::parse($kps->tanggal_survei)->format('d-m-Y')
            : ($vendor->sv_tanggal_survei ? Carbon::parse($vendor->sv_tanggal_survei)->format('d-m-Y') : null);

        $tanggalPengawasan = $kps && $kps->tanggal_pengawasan
            ? Carbon::parse($kps->tanggal_pengawasan)->format('d-m-Y')
            : ($vendor->sv_tanggal_pengawasan ? Carbon::parse($vendor->sv_tanggal_pengawasan)->format('d-m-Y') : null);

        $catatanBlokV = $kps->catatan_blok_v ?? null;

        $materialIds    = $vendor->material_id ? json_decode($vendor->material_id, true) : [];
        $peralatanIds   = $vendor->peralatan_id ? json_decode($vendor->peralatan_id, true) : [];
        $tenagaKerjaIds = $vendor->tenaga_kerja_id ? json_decode($vendor->tenaga_kerja_id, true) : [];

        $material = [];
        if (!empty($materialIds)) {
            $rows = $this->getIdentifikasiSurvey('material', $vendor->material_id);
            foreach ($rows as $r) {
                $material[] = [
                    'id'                         => (int) $r->id,
                    'material_id'                => (int) $r->id,
                    'identifikasi_kebutuhan_id'  => isset($r->identifikasi_kebutuhan_id) ? (string)$r->identifikasi_kebutuhan_id : (string)$vendor->identifikasi_kebutuhan_id,
                    'nama_material'              => $r->nama_material ?? null,
                    'satuan'                     => $r->satuan ?? null,
                    'spesifikasi'                => $r->spesifikasi ?? null,
                    'ukuran'                     => $r->ukuran ?? null,
                    'kodefikasi'                 => $r->kodefikasi ?? null,
                    'kelompok_material'          => $r->kelompok_material ?? null,
                    'jumlah_kebutuhan'           => $r->jumlah_kebutuhan ?? null,
                    'merk'                       => $r->merk ?? null,
                    'provincies_id'              => isset($r->provincies_id) ? (int)$r->provincies_id : null,
                    'cities_id'                  => isset($r->cities_id) ? (int)$r->cities_id : null,
                    'satuan_setempat'            => $r->satuan_setempat ?? null,
                    'satuan_setempat_panjang'    => isset($r->satuan_setempat_panjang) ? (float)$r->satuan_setempat_panjang : null,
                    'satuan_setempat_lebar'      => isset($r->satuan_setempat_lebar) ? (float)$r->satuan_setempat_lebar : null,
                    'satuan_setempat_tinggi'     => isset($r->satuan_setempat_tinggi) ? (float)$r->satuan_setempat_tinggi : null,
                    'konversi_satuan_setempat'   => $r->konversi_satuan_setempat ?? null,
                    'harga_satuan_setempat'      => isset($r->harga_satuan_setempat) ? (float)$r->harga_satuan_setempat : null,
                    'harga_konversi_satuan_setempat' => isset($r->harga_konversi_satuan_setempat) ? (float)$r->harga_konversi_satuan_setempat : null,
                    'harga_khusus'               => isset($r->harga_khusus) ? (float)$r->harga_khusus : null,
                    'keterangan'                 => $r->keterangan ?? null,
                ];
            }
        }

        $peralatan = [];
        if (!empty($peralatanIds)) {
            $rows = $this->getIdentifikasiSurvey('peralatan', $vendor->peralatan_id);
            foreach ($rows as $r) {
                $peralatan[] = [
                    'id'                         => (int) $r->id,
                    'peralatan_id'               => (int) $r->id,
                    'identifikasi_kebutuhan_id'  => isset($r->identifikasi_kebutuhan_id) ? (string)$r->identifikasi_kebutuhan_id : (string)$vendor->identifikasi_kebutuhan_id,
                    'nama_peralatan'             => $r->nama_peralatan ?? null,
                    'satuan'                     => $r->satuan ?? null,
                    'spesifikasi'                => $r->spesifikasi ?? null,
                    'kapasitas'                  => $r->kapasitas ?? null,
                    'kodefikasi'                 => $r->kodefikasi ?? null,
                    'kelompok_peralatan'         => $r->kelompok_peralatan ?? null,
                    'jumlah_kebutuhan'           => $r->jumlah_kebutuhan ?? null,
                    'merk'                       => $r->merk ?? null,
                    'provincies_id'              => isset($r->provincies_id) ? (int)$r->provincies_id : null,
                    'cities_id'                  => isset($r->cities_id) ? (int)$r->cities_id : null,
                    'satuan_setempat'            => $r->satuan_setempat ?? null,
                    'harga_sewa_satuan_setempat' => isset($r->harga_sewa_satuan_setempat) ? (float)$r->harga_sewa_satuan_setempat : null,
                    'harga_sewa_konversi'        => isset($r->harga_sewa_konversi) ? (float)$r->harga_sewa_konversi : null,
                    'harga_pokok'                => isset($r->harga_pokok) ? (float)$r->harga_pokok : null,
                    'keterangan'                 => $r->keterangan ?? null,
                ];
            }
        }

        $tenagaKerja = [];
        if (!empty($tenagaKerjaIds)) {
            $rows = $this->getIdentifikasiSurvey('tenaga_kerja', $vendor->tenaga_kerja_id);
            foreach ($rows as $r) {
                $tenagaKerja[] = [
                    'id'                         => (int) $r->id,
                    'tenaga_kerja_id'            => (int) $r->id,
                    'identifikasi_kebutuhan_id'  => isset($r->identifikasi_kebutuhan_id) ? (string)$r->identifikasi_kebutuhan_id : (string)$vendor->identifikasi_kebutuhan_id,
                    'jenis_tenaga_kerja'         => $r->jenis_tenaga_kerja ?? null,
                    'satuan'                     => $r->satuan ?? null,
                    'jumlah_kebutuhan'           => $r->jumlah_kebutuhan ?? null,
                    'kodefikasi'                 => $r->kodefikasi ?? null,
                    'provincies_id'              => isset($r->provincies_id) ? (int)$r->provincies_id : null,
                    'cities_id'                  => isset($r->cities_id) ? (int)$r->cities_id : null,
                    'harga_per_satuan_setempat'  => isset($r->harga_per_satuan_setempat) ? (float)$r->harga_per_satuan_setempat : null,
                    'harga_konversi_perjam'      => isset($r->harga_konversi_perjam) ? (float)$r->harga_konversi_perjam : null,
                    'keterangan'                 => $r->keterangan ?? null,
                ];
            }
        }

        $kategoriVendor = KategoriVendor::whereIn(
            'id',
            json_decode($vendor->kategori_vendor_id, true) ?? []
        )->selectRaw('nama_kategori_vendor as name')->get();
        $stringKategoriVendor = $kategoriVendor->pluck('name')->implode(', ');

        $verifRows = $this->getPemeriksaanDataList((int)$sv->data_vendor_id, (int)$sv->id);
        $verifikasi = [];
        foreach ($verifRows as $v) {
            $verifikasi[] = [
                'data_vendor_id'      => (int) $v->data_vendor_id,
                'shortlist_vendor_id' => (int) $v->shortlist_vendor_id,
                'item_number'         => $v->item_number,
                'status_pemeriksaan'  => $v->status_pemeriksaan,
                'verified_by'         => $v->verified_by,
            ];
        }

        return [
            'type_save'                => null,
            'user_id_petugas_lapangan' => $userIdPetugas ? (int)$userIdPetugas : null,
            'user_id_pengawas'         => $userIdPengawas ? (int)$userIdPengawas : null,
            'nama_pemberi_informasi'   => $namaPI,
            'identifikasi_kebutuhan_id' => (int) $vendor->identifikasi_kebutuhan_id,
            'data_vendor_id'           => (int) $vendor->vendor_id,
            'tanggal_survei'           => $tanggalSurvei,
            'tanggal_pengawasan'       => $tanggalPengawasan,
            'catatan_blok_v'           => $catatanBlokV,
            'material'                 => $material,
            'peralatan'                => $peralatan,
            'tenaga_kerja'             => $tenagaKerja,
            'provinsi'                 => $vendor->nama_provinsi,
            'kota'                     => $vendor->nama_kota,
            'nama_responden'           => $vendor->nama_vendor,
            'alamat'                   => $vendor->alamat,
            'no_telepon'               => $vendor->no_telepon,
            'kategori_responden'       => $stringKategoriVendor,
            'keterangan_petugas_lapangan' => [
                'nama_petugas_lapangan' => optional(Users::find($userIdPetugas))->nama_lengkap ?? null,
                'nip_petugas_lapangan'  => optional(Users::find($userIdPetugas))->nrp ?? null,
                'tanggal_survei'        => $tanggalSurvei,
                'nama_pengawas'         => optional(Users::find($userIdPengawas))->nama_lengkap ?? null,
                'nip_pengawas'          => optional(Users::find($userIdPengawas))->nrp ?? null,
                'tanggal_pengawasan'    => $tanggalPengawasan,
            ],
            'keterangan_pemberi_informasi' => [
                'nama_pemberi_informasi' => $namaPI,
                'tanda_tangan_responden' => $namaPI ? ('Ditandatangani oleh ' . $namaPI . ' pada ' . now()) : null,
            ],
            'verifikasi_dokumen'       => $verifikasi,
        ];
    }



    public function updateDataVerifikasiPengawas($data)
    {
        return ShortlistVendor::updateOrCreate(
            [
                'data_vendor_id' => $data['data_vendor_id'],
                'shortlist_vendor_id' => $data['identifikasi_kebutuhan_id'],
            ],
            [
                'catatan_blok_1' => $data['catatan_blok_1'],
                'catatan_blok_2' => $data['catatan_blok_2'],
                'catatan_blok_3' => $data['catatan_blok_3'],
                'catatan_blok_4' => $data['catatan_blok_4'],
            ]
        );
    }

    public function pemeriksaanDataList($data)
    {
        $result = [];
        foreach (json_decode($data['verifikasi_validasi']) as $value) {
            $result[] = VerifikasiValidasi::updateOrCreate(
                [
                    'data_vendor_id' => $data->data_vendor_id,
                    'shortlist_vendor_id' => $data->identifikasi_kebutuhan_id,
                    'item_number' => $value->id_pemeriksaan,
                ],
                [
                    'status_pemeriksaan' => $value->status_pemeriksaan,
                    'verified_by' => $value->verified_by,
                ]
            );
        }

        return $result;
    }

    public function changeStatusVerification($id, $filePath)
    {
        return PerencanaanData::updateOrCreate(
            [
                'identifikasi_kebutuhan_id' => $id,
            ],
            [
                'status' => config('constants.STATUS_PEMERIKSAAN'),
                // 'doc_berita_acara' => $filePath,
            ]
        );
    }

    public function changeStatusValidation($id, $filePath, $status)
    {
        return PerencanaanData::updateOrCreate(
            [
                'identifikasi_kebutuhan_id' => $id,
            ],
            [
                'status' => $status,
                // 'doc_berita_acara' => $filePath,
            ]
        );
    }

    public function updateIdentifikasi($table, $tableId, $data)
    {
        if ($table == 'material') {
            return Material::updateOrCreate(
                [
                    'id' => $tableId,
                ],
                [
                    'satuan_setempat' => $data['satuan_setempat'],
                    'satuan_setempat_panjang' => $data['satuan_setempat_panjang'],
                    'satuan_setempat_lebar' => $data['satuan_setempat_lebar'],
                    'satuan_setempat_tinggi' => $data['satuan_setempat_tinggi'],
                    'konversi_satuan_setempat' => $data['konversi_satuan_setempat'],
                    'harga_satuan_setempat' => $data['harga_satuan_setempat'],
                    'harga_konversi_satuan_setempat' => $data['harga_konversi_satuan_setempat'],
                    'harga_khusus' => $data['harga_khusus'],
                    'keterangan' => $data['keterangan'],
                ]
            );
        } elseif ($table == 'peralatan') {
            return Peralatan::updateOrCreate(
                [
                    'id' => $tableId,
                ],
                [
                    'satuan_setempat' => $data['satuan_setempat'],
                    'harga_sewa_satuan_setempat' => $data['harga_sewa_satuan_setempat'],
                    'harga_sewa_konversi' => $data['harga_sewa_konversi'],
                    'harga_pokok' => $data['harga_pokok'],
                    'keterangan' => $data['keterangan'],
                ]
            );
        } elseif ($table == 'tenaga_kerja') {
            return TenagaKerja::updateOrCreate(
                [
                    'id' => $tableId,
                ],
                [
                    'harga_per_satuan_setempat' => $data['harga_per_satuan_setempat'],
                    'harga_konversi_perjam' => $data['harga_konversi_perjam'],
                    'keterangan' => $data['keterangan'],
                ]
            );
        }
    }

    public function updateShortlistVendor($shortlistId, $vendorId, $data)
    {
        return ShortlistVendor::updateOrCreate(
            [
                'shortlist_vendor_id' => $shortlistId,
                'data_vendor_id' => $vendorId
            ],
            [
                'petugas_lapangan_id' => $data['user_id_petugas_lapangan'],
                'pengawas_id' => $data['user_id_pengawas'],
                'nama_pemberi_informasi' => $data['nama_pemberi_informasi'],
                'tanggal_survei' => Carbon::createFromFormat('d-m-Y', $data['tanggal_survei'])->format('Y-m-d'),
                'tanggal_pengawasan' => Carbon::createFromFormat('d-m-Y', $data['tanggal_pengawasan'])->format('Y-m-d'),
            ]
        );
    }

    public function storeIdentifikasiSurvey($data, $table)
    {
        if ($table == 'material') {
            return MaterialSurvey::updateOrCreate(
                [
                    'material_id' => $data['material_id'] ?? $data['id'],
                ],
                [
                    'satuan_setempat'                => $data['satuan_setempat'] ?? null,
                    'satuan_setempat_panjang'        => $data['satuan_setempat_panjang'] ?? null,
                    'satuan_setempat_lebar'          => $data['satuan_setempat_lebar'] ?? null,
                    'satuan_setempat_tinggi'         => $data['satuan_setempat_tinggi'] ?? null,
                    'konversi_satuan_setempat'       => $data['konversi_satuan_setempat'] ?? null,
                    'harga_satuan_setempat'          => $data['harga_satuan_setempat'] ?? null,
                    'harga_konversi_satuan_setempat' => $data['harga_konversi_satuan_setempat'] ?? null,
                    'harga_khusus'                   => $data['harga_khusus'] ?? null,
                    'keterangan'                     => $data['keterangan'] ?? null,
                ]
            );
        } elseif ($table == 'peralatan') {
            return PeralatanSurvey::updateOrCreate(
                [
                    'peralatan_id' => $data['peralatan_id'] ?? $data['id'],
                ],
                [
                    'satuan_setempat' => $data['satuan_setempat'],
                    'harga_sewa_satuan_setempat' => $data['harga_sewa_satuan_setempat'],
                    'harga_sewa_konversi' => $data['harga_sewa_konversi'],
                    'harga_pokok' => $data['harga_pokok'],
                    'keterangan' => $data['keterangan'],
                ]
            );
        } elseif ($table == 'tenaga_kerja') {
            return TenagaKerjaSurvey::updateOrCreate(
                [
                    'tenaga_kerja_id' => $data['tenaga_kerja_id'] ?? $data['id'],
                ],
                [
                    'harga_per_satuan_setempat' => $data['harga_per_satuan_setempat'] ?? null,
                    'harga_konversi_perjam'     => $data['harga_konversi_perjam'] ?? null,
                    'keterangan'                => $data['keterangan'] ?? null,
                ]
            );
        }
    }

    public function storeKeteranganPetugasSurvey($data)
    {
        $tglSurvei = isset($data['tanggal_survei']) && $data['tanggal_survei']
            ? Carbon::createFromFormat('d-m-Y', $data['tanggal_survei'])->format('Y-m-d')
            : null;

        $tglPengawasan = isset($data['tanggal_pengawasan']) && $data['tanggal_pengawasan']
            ? Carbon::createFromFormat('d-m-Y', $data['tanggal_pengawasan'])->format('Y-m-d')
            : null;

        return KeteranganPetugasSurvey::updateOrCreate(
            ['identifikasi_kebutuhan_id' => $data['identifikasi_kebutuhan_id']],
            [
                'petugas_lapangan_id'    => $data['user_id_petugas_lapangan'] ?? null,
                'pengawas_id'            => $data['user_id_pengawas'] ?? null,
                'tanggal_survei'         => $tglSurvei,
                'tanggal_pengawasan'     => $tglPengawasan,
                'nama_pemberi_informasi' => $data['nama_pemberi_informasi'] ?? null,
                'catatan_blok_v'         => $data['catatan_blok_v'] ?? null,
            ]
        );
    }



    public function changeStatus($id, $status)
    {
        if ($status == config('constants.STATUS_PENGISIAN_PETUGAS')) {
            $shortlistVendorId = ShortlistVendor::select('shortlist_vendor_id')
                ->where('id', $id)->first();

            return PerencanaanData::updateOrCreate(
                [
                    'shortlist_vendor_id' => $shortlistVendorId['shortlist_vendor_id'],
                ],
                [
                    'status' => $status
                ]
            );
        } else {
            return PerencanaanData::updateOrCreate(
                [
                    'shortlist_vendor_id' => $id,
                ],
                [
                    'status' => $status
                ]
            );
        }
    }

    public function getPemeriksaanDataList($dataVendorId, $shortlistVendorId)
    {
        $data = VerifikasiValidasi::select(
            'data_vendor_id',
            'shortlist_vendor_id',
            'item_number',
            'status_pemeriksaan',
            'verified_by',
        )->where('data_vendor_id', $dataVendorId)
            ->where('shortlist_vendor_id', $shortlistVendorId)->get();
        return $data;
    }
}
