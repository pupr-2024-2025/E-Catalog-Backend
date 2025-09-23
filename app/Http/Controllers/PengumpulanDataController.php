<?php

namespace App\Http\Controllers;

use App\Models\Pengawas;
use App\Models\PengolahData;
use App\Models\PerencanaanData;
use App\Models\PetugasLapangan;
use App\Models\Users;
use App\Services\PengumpulanDataService;
use App\Services\PerencanaanDataService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PengumpulanDataController extends Controller
{
    protected $pengumpulanDataService;
    protected $perencanaanDataService;

    public function __construct(
        PengumpulanDataService $pengumpulanDataService,
        PerencanaanDataService $perencanaanDataService
    ) {
        $this->pengumpulanDataService = $pengumpulanDataService;
        $this->perencanaanDataService = $perencanaanDataService;
    }

    public function storeTeamTeknisBalai(Request $request)
    {
        $rules = [
            'nama_team' => 'required',
            'ketua_team' => 'required',
            'sekretaris_team' => 'required',
            // 'informasi_umum_id' => 'required',
            'anggota' => 'required',
            'sk_penugasan' => 'required|file|mimes:pdf,doc,docx|max:2048'
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'data' => []
            ], 400);
        }

        try {
            // 1) Upload file ke disk 'public' (NOTE: ini mengembalikan PATH relatif (bukan URL))
            $filePath = $request->file('sk_penugasan')->store('sk_penugasan', 'public');

            // 2) Konversi PATH -> URL publik (/storage/...) agar FE bisa "Lihat PDF"
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk('public');
            $fileURL = $disk->url($filePath);

            $ketua = (int) $request->input('ketua_team');
            $sekretaris = (int) $request->input('sekretaris_team');
            $arrayPetinggi = array_merge([$ketua], [$sekretaris]);

            $arrayAnggota = array_merge(explode(',', $request->input('anggota')));
            $arrayAnggota = array_map('intval', $arrayAnggota);

            $duplicates = array_intersect($arrayPetinggi, $arrayAnggota);

            if (!empty($duplicates)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Ketua atau Sekretaris tidak boleh rangkap menjadi anggota',
                    'error' => "Duplicate Data"
                ], 400);
            }

            $data = [
                'nama_team' => $request->input('nama_team'),
                'ketua_team' => $request->input('ketua_team'),
                'sekretaris_team' => $request->input('sekretaris_team'),
                'anggota' => $arrayAnggota,
                // 'informasi_umum_id' => $request->input('informasi_umum_id'),
                'sk_penugasan' => $fileURL, // kirim URL
                'relative_path' => $filePath // kirim relative path dari filenya
            ];

            $saveData = $this->pengumpulanDataService->storeTeamPengumpulanData($data);
            if ($saveData) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Data berhasil disimpan',
                    'data' => $saveData
                ], 201);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan data',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function getTeamPengumpulanData()
    {
        $data = $this->pengumpulanDataService->getAllTeamPengumpulanData();
        if ($data) {
            return response()->json([
                'status' => 'success',
                'message' => 'Data berhasil didapat',
                'data' => $data
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal Mendapatkan Data',
                'data' => []
            ]);
        }
    }

    public function assignTeamPengumpulanData(Request $request)
    {
        $rules = [
            'id_pengumpulan_data' => 'required',
            'id_team_pengumpulan_data' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ]);
        }

        try {
            $assignTeam = $this->pengumpulanDataService->assignTeamPengumpulanData($request);
            if ($assignTeam) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Data berhasil disimpan',
                    'data' => $assignTeam
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan data',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function listPengumpulanDataByNamaBalai(Request $request)
    {
        $namaBalai = $request->query('nama_balai');

        if (empty($namaBalai)) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ], 400);
        }

        $statusPengumpulan = config("constants.STATUS_PENGUMPULAN");

        $data = $this->perencanaanDataService->listPerencanaanDataByNamaBalai($namaBalai, $statusPengumpulan);
        if (!$data) {
            return response()->json([
                'status' => 'success',
                'message' => 'No data found for the given nama_balai',
                'data' => []
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => config('constants.SUCCESS_MESSAGE_GET'),
            'data' => $data
        ]);
    }

    public function listPengumpulanData()
    {
        $status = [
            config('constants.STATUS_PENGUMPULAN'),
            config('constants.STATUS_PENGISIAN_PETUGAS'),
            config('constants.STATUS_VERIFIKASI_PENGAWAS'),
            config('constants.STATUS_ENTRI_DATA'),
            config('constants.STATUS_PEMERIKSAAN'),
        ];
        $data = $this->perencanaanDataService->tableListPerencanaanData($status);
        if ($data) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $data
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function storePengawas(Request $request)
    {
        $rules = [
            'sk_penugasan' => 'required|file|mimes:pdf,doc,docx|max:2048',
            'user_id' => 'required',
            // 'informasi_umum_id' => 'required'
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ], 400);
        }

        $array = explode(',', $request['user_id']);

        // $informasiUmumId = $request['informasi_umum_id'];

        // TODO: tolong nanti diganti jika GCP sudah on
        $filePath = $request->file('sk_penugasan')->store('sk_penugasan', "public");
        /** @var FilesystemAdapter */
        $disk = Storage::disk("public");
        $fullPath = $disk->url($filePath);

        try {
            // PerencanaanData::where("informasi_umum_id", "=", $informasiUmumId)
            //     ->update(["pengawas_id" => $array]);

            Users::whereIn("id", $array)
                ->update([
                    "id_roles" => 4,
                    "surat_penugasan_url" => $fullPath
                ]);

            $data = [];

            foreach (collect($array) as $value) {
                $data[] = [
                    'user_id' => $value,
                    'sk_penugasan' => $filePath,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            $save = Pengawas::insert($data);
            if ($save) {
                return response()->json([
                    'status' => 'success',
                    'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                    'data' => $data
                ], 201);
            }
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ], 400);
        }
    }

    public function listUser(Request $request)
    {
        $roles = $this->pengumpulanDataService->getListRoles($request['role']);

        if (empty($roles)) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'error' => 'Role tidak terdefinisi'
            ], 400);
        }

        $getData = $this->pengumpulanDataService->listUserPengumpulan($roles);
        if ($getData) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $getData
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => config('constants.ERROR_MESSAGE_GET'),
            'data' => []
        ], 400);
    }

    public function storePetugasLapangan(Request $request)
    {
        $rules = [
            'sk_penugasan' => 'required|file|mimes:pdf,doc,docx|max:2048',
            'user_id' => 'required',
            // 'informasi_umum_id' => 'required'
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ], 400);
        }

        // TODO: tolong nanti diganti jika GCP sudah on
        $filePath = $request->file('sk_penugasan')->store('sk_penugasan', "public");
        /** @var FilesystemAdapter  */
        $disk = Storage::disk("public");
        $fullPath = $disk->url($filePath);

        $array = explode(',', $request['user_id']);
        // $informasiUmumId = $request['informasi_umum_id'];
        try {

            // PerencanaanData::where("informasi_umum_id", '=', $informasiUmumId)
            //     ->update(['petugas_lapangan_id' => $array]);

            Users::whereIn("id", $array)
                ->update([
                    "id_roles" => 5,
                    "surat_penugasan_url" => $fullPath
                ]);

            $data = [];

            foreach (collect($array) as $value) {
                $data[] = [
                    'user_id' => $value,
                    'sk_penugasan' => $filePath,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            $save = PetugasLapangan::insert($data);
            if ($save) {
                return response()->json([
                    'status' => 'success',
                    'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                    'data' => $data
                ], 201);
            }
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ], 400);
        }
    }

    public function storePengolahData(Request $request)
    {
        $rules = [
            'sk_penugasan' => 'required|file|mimes:pdf,doc,docx|max:2048',
            'user_id' => 'required',
            // 'informasi_umum_id' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ], 400);
        }

        // TODO: tolong nanti diganti jika GCP sudah on
        $filePath = $request->file('sk_penugasan')->store('sk_penugasan', "public");
        /** @var FilesystemAdapter */
        $disk = Storage::disk("public");
        $fullPath = $disk->url($filePath);

        $array = explode(',', $request['user_id']);
        // $informasiUmumId = $request['informasi_umum_id'];
        try {
            // PerencanaanData::where("informasi_umum_id", "=", $informasiUmumId)
            //     ->update([
            //         "pengolah_data_id" => $array,
            //     ]);

            Users::whereIn("id", $array)
                ->update([
                    "id_roles" => 7,
                    "surat_penugasan_url" => $fullPath
                ]);

            $data = [];

            foreach (collect($array) as $value) {
                $data[] = [
                    'user_id' => $value,
                    'sk_penugasan' => $filePath,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            $save = PengolahData::insert($data);
            if ($save) {
                return response()->json([
                    'status' => 'success',
                    'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                    'data' => $data
                ], 201);
            }
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ], 400);
        }
    }

    public function listPengawas()
    {
        $data = $this->pengumpulanDataService->listPenugasan('pengawas');

        if ($data) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $data
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function listPengolahData()
    {
        $data = $this->pengumpulanDataService->listPenugasan('pengolah data');

        if ($data) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $data
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function listPetugasLapangan()
    {
        $data = $this->pengumpulanDataService->listPenugasan('petugas lapangan');

        if ($data) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $data
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function assignPengawas(Request $request)
    {
        $rules = [
            'id_user' => 'required',
            'pengumpulan_data_id' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ]);
        }

        try {
            $assign = $this->pengumpulanDataService->assignPenugasan('pengawas', $request->id_user, $request->pengumpulan_data_id);

            if (empty($assign)) {
                return response()->json([
                    'status' => 'gagal',
                    'message' => config('constants.ERROR_MESSAGE_SAVE'),
                    'data' => $assign
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                'data' => $assign
            ], 200);
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ]);
        }
    }

    public function assignPengolahData(Request $request)
    {
        $rules = [
            'id_user' => 'required',
            'pengolah_data_id' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ]);
        }

        try {
            $assign = $this->pengumpulanDataService->assignPenugasan('pengolah data', $request->id_user, $request->pengolah_data_id);
            if ($assign) {
                return response()->json([
                    'status' => 'success',
                    'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                    'data' => $assign
                ]);
            }
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ]);
        }
    }

    public function assignPetugasLapangan(Request $request)
    {
        $rules = [
            'id_user' => 'required',
            'petugas_lapangan_id' => 'required',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'error' => $validator->errors()
            ]);
        }

        try {
            $assign = $this->pengumpulanDataService->assignPenugasan('petugas lapangan', $request->id_user, $request->petugas_lapangan_id);
            if ($assign) {
                return response()->json([
                    'status' => 'success',
                    'message' => config('constants.SUCCESS_MESSAGE_SAVE'),
                    'data' => $assign
                ]);
            }
        } catch (\Exception $th) {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_SAVE'),
                'error' => $th->getMessage()
            ]);
        }
    }

    public function tableListPengumpulanData()
    {
        $list = $this->perencanaanDataService->tableListPerencanaanData(config('constants.STATUS_PENGUMPULAN'));
        if (isset($list)) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $list
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function viewPdfKuisioner($id)
    {
        $kuisioner = $this->pengumpulanDataService->showKuisioner($id);
        if (isset($kuisioner)) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $kuisioner
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'data' => []
            ]);
        }
    }

    public function listVendorByPaket($id)
    {
        $list = $this->pengumpulanDataService->listVendorByPerencanaanId($id);
        if ($list->isNotEmpty()) {
            return response()->json([
                'status' => 'success',
                'message' => config('constants.SUCCESS_MESSAGE_GET'),
                'data' => $list
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => config('constants.ERROR_MESSAGE_GET'),
            'data' => []
        ], 404);
    }

    public function getEntriData($shortlistId)
    {
        try {
            $raw = $this->pengumpulanDataService->getEntriData($shortlistId);
            if (!$raw) {
                return response()->json([
                    'status'  => 'error',
                    'message' => config('constants.ERROR_MESSAGE_GET'),
                    'data'    => []
                ], 404);
            }

            $data = is_array($raw) && array_key_exists('data', $raw) ? $raw['data'] : $raw;
            $materials   = isset($data['material']) ? (array) $data['material'] : [];
            $peralatans  = isset($data['peralatan']) ? (array) $data['peralatan'] : [];
            $tenagaK     = isset($data['tenaga_kerja']) ? (array) $data['tenaga_kerja'] : [];

            $out = [
                'data_vendor_id'            => isset($data['data_vendor_id']) ? (int) $data['data_vendor_id'] : null,
                'identifikasi_kebutuhan_id' => isset($data['identifikasi_kebutuhan_id']) ? (int) $data['identifikasi_kebutuhan_id'] : null,
                'provinsi'                  => $data['provinsi'] ?? null,
                'kota'                      => $data['kota'] ?? null,
                'nama_responden'            => $data['nama_responden'] ?? null,
                'alamat'                    => $data['alamat'] ?? null,
                'no_telepon'                => $data['no_telepon'] ?? null,
                'kategori_responden'        => $data['kategori_responden'] ?? null,
                'keterangan_petugas_lapangan' => [
                    'nama_petugas_lapangan' => $data['keterangan_petugas_lapangan']['nama_petugas_lapangan'] ?? $data['nama_petugas_lapangan'] ?? null,
                    'nip_petugas_lapangan'  => $data['keterangan_petugas_lapangan']['nip_petugas_lapangan'] ?? $data['nip_petugas_lapangan'] ?? null,
                    'tanggal_survei'        => $this->formatDMY($data['keterangan_petugas_lapangan']['tanggal_survei'] ?? $data['tanggal_survei'] ?? null),
                    'nama_pengawas'         => $data['keterangan_petugas_lapangan']['nama_pengawas'] ?? $data['nama_pengawas'] ?? null,
                    'nip_pengawas'          => $data['keterangan_petugas_lapangan']['nip_pengawas'] ?? $data['nip_pengawas'] ?? null,
                    'tanggal_pengawasan'    => $this->formatDMY($data['keterangan_petugas_lapangan']['tanggal_pengawasan'] ?? $data['tanggal_pengawasan'] ?? null),
                ],
                'keterangan_pemberi_informasi' => [
                    'nama_pemberi_informasi' => $data['keterangan_pemberi_informasi']['nama_pemberi_informasi'] ?? $data['nama_pemberi_informasi'] ?? null,
                    'tanda_tangan_responden' => $data['keterangan_pemberi_informasi']['tanda_tangan_responden'] ?? null,
                ],
                'material' => array_map(function ($m) {
                    return [
                        'id'                           => isset($m['id']) ? (int) $m['id'] : null,
                        'identifikasi_kebutuhan_id'    => (string) ($m['identifikasi_kebutuhan_id'] ?? ''),
                        'nama_material'                => $m['nama_material'] ?? null,
                        'satuan'                       => $m['satuan'] ?? null,
                        'spesifikasi'                  => $m['spesifikasi'] ?? null,
                        'ukuran'                       => $m['ukuran'] ?? null,
                        'kodefikasi'                   => $m['kodefikasi'] ?? null,
                        'kelompok_material'            => $m['kelompok_material'] ?? null,
                        'jumlah_kebutuhan'             => $m['jumlah_kebutuhan'] ?? null,
                        'merk'                         => $m['merk'] ?? null,
                        'provincies_id'                => isset($m['provincies_id']) ? (int) $m['provincies_id'] : null,
                        'cities_id'                    => isset($m['cities_id']) ? (int) $m['cities_id'] : null,
                        'satuan_setempat'              => $m['satuan_setempat'] ?? null,
                        'satuan_setempat_panjang'      => isset($m['satuan_setempat_panjang']) ? (float) $m['satuan_setempat_panjang'] : null,
                        'satuan_setempat_lebar'        => isset($m['satuan_setempat_lebar']) ? (float) $m['satuan_setempat_lebar'] : null,
                        'satuan_setempat_tinggi'       => isset($m['satuan_setempat_tinggi']) ? (float) $m['satuan_setempat_tinggi'] : null,
                        'konversi_satuan_setempat'     => $m['konversi_satuan_setempat'] ?? null,
                        'harga_satuan_setempat'        => isset($m['harga_satuan_setempat']) ? (int) $m['harga_satuan_setempat'] : null,
                        'harga_konversi_satuan_setempat' => isset($m['harga_konversi_satuan_setempat']) ? (int) $m['harga_konversi_satuan_setempat'] : null,
                        'harga_khusus'                 => isset($m['harga_khusus']) ? (int) $m['harga_khusus'] : null,
                        'keterangan'                   => $m['keterangan'] ?? null,
                    ];
                }, $materials),
                'peralatan' => array_map(function ($p) {
                    return [
                        'id'                         => isset($p['id']) ? (int) $p['id'] : null,
                        'identifikasi_kebutuhan_id'  => (string) ($p['identifikasi_kebutuhan_id'] ?? ''),
                        'nama_peralatan'             => $p['nama_peralatan'] ?? null,
                        'satuan'                     => $p['satuan'] ?? null,
                        'spesifikasi'                => $p['spesifikasi'] ?? null,
                        'kapasitas'                  => $p['kapasitas'] ?? null,
                        'kodefikasi'                 => $p['kodefikasi'] ?? null,
                        'kelompok_peralatan'         => $p['kelompok_peralatan'] ?? null,
                        'jumlah_kebutuhan'           => $p['jumlah_kebutuhan'] ?? null,
                        'merk'                       => $p['merk'] ?? null,
                        'provincies_id'              => isset($p['provincies_id']) ? (int) $p['provincies_id'] : null,
                        'cities_id'                  => isset($p['cities_id']) ? (int) $p['cities_id'] : null,
                        'satuan_setempat'            => $p['satuan_setempat'] ?? null,
                        'harga_sewa_satuan_setempat' => isset($p['harga_sewa_satuan_setempat']) ? (int) $p['harga_sewa_satuan_setempat'] : null,
                        'harga_sewa_konversi'        => isset($p['harga_sewa_konversi']) ? (int) $p['harga_sewa_konversi'] : null,
                        'harga_pokok'                => isset($p['harga_pokok']) ? (int) $p['harga_pokok'] : null,
                    ];
                }, $peralatans),
                'tenaga_kerja' => array_map(function ($t) {
                    return [
                        'id'                        => isset($t['id']) ? (int) $t['id'] : null,
                        'identifikasi_kebutuhan_id' => (string) ($t['identifikasi_kebutuhan_id'] ?? ''),
                        'jenis_tenaga_kerja'        => $t['jenis_tenaga_kerja'] ?? null,
                        'satuan'                    => $t['satuan'] ?? null,
                        'jumlah_kebutuhan'          => $t['jumlah_kebutuhan'] ?? null,
                        'kodefikasi'                => $t['kodefikasi'] ?? null,
                        'provincies_id'             => isset($t['provincies_id']) ? (int) $t['provincies_id'] : null,
                        'cities_id'                 => isset($t['cities_id']) ? (int) $t['cities_id'] : null,
                        'harga_per_satuan_setempat' => isset($t['harga_per_satuan_setempat']) ? (string) $t['harga_per_satuan_setempat'] : null,
                        'harga_konversi_perjam'     => isset($t['harga_konversi_perjam']) ? (string) $t['harga_konversi_perjam'] : null,
                        'keterangan'                => $t['keterangan'] ?? null,
                    ];
                }, $tenagaK),
            ];

            if (isset($data['verifikasi_dokumen']) && is_array($data['verifikasi_dokumen'])) {
                $out['verifikasi_dokumen'] = array_map(function ($v) {
                    return [
                        'data_vendor_id'      => isset($v['data_vendor_id']) ? (int) $v['data_vendor_id'] : null,
                        'shortlist_vendor_id' => isset($v['shortlist_vendor_id']) ? (int) $v['shortlist_vendor_id'] : null,
                        'item_number'         => $v['item_number'] ?? null,
                        'status_pemeriksaan'  => $v['status_pemeriksaan'] ?? null,
                        'verified_by'         => $v['verified_by'] ?? null,
                    ];
                }, $data['verifikasi_dokumen']);
            } else {
                $out['verifikasi_dokumen'] = [];
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Berhasil Mendapatkan Data',
                'data'    => $out
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => config('constants.ERROR_MESSAGE_GET'),
                'error'   => $e->getMessage(),
                'data'    => []
            ], 500);
        }
    }

    private function formatDMY($dateStr)
    {
        if (!$dateStr) return null;
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dateStr)) {
            return $dateStr;
        }
        try {
            return \Carbon\Carbon::parse($dateStr)->format('d-m-Y');
        } catch (\Throwable $e) {
            return null;
        }
    }


    public function entriDataSave(Request $request)
    {
        // $rules = [
        //     'user_id_petugas_lapangan' => 'required',
        //     'user_id_pengawas' => 'required',
        //     'nama_pemberi_informasi' => 'required',
        //     'data_vendor_id' => 'required',
        //     'identifikasi_kebutuhan_id' => 'required',
        //     'tanggal_survei' => 'required',
        //     'tanggal_pengawasan' => 'required',
        // ];
        // $validator = Validator::make($request->all(), $rules);
        // if ($validator->fails()) {
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'validasi gagal!',
        //         'error' => $validator->errors()
        //     ]);
        // }

        try {
            $materialResult = [];
            foreach ($request->material as $material) {
                $materialResult[] = $this->pengumpulanDataService->updateIdentifikasi('material', $material['id'], $material);
            }

            $peralatanResult = [];
            foreach ($request->peralatan as $peralatan) {
                $peralatanResult[] = $this->pengumpulanDataService->updateIdentifikasi('peralatan', $peralatan['id'], $peralatan);
            }

            $tenagaKerjaResult = [];
            foreach ($request->tenaga_kerja as $tenaga_kerja) {
                $tenagaKerjaResult[] = $this->pengumpulanDataService->updateIdentifikasi('tenaga_kerja', $tenaga_kerja['id'], $tenaga_kerja);
            }

            $updateShortlist = $this->pengumpulanDataService->updateShortlistVendor($request->identifikasi_kebutuhan_id, $request->data_vendor_id, $request);
            $response = [
                'keterangan' => $updateShortlist,
                'material' => $materialResult,
                'peralatan' => $peralatanResult,
                'tenaga_kerja' => $tenagaKerjaResult,
            ];

            return response()->json([
                'status' => 'success',
                'message' => 'Data berhasil disimpan!',
                'data' => $response
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan data!',
                'error' => $e->getMessage()
            ]);
        }
    }

    public function verifikasiPengawas(Request $request)
    {
        $rules = [
            'identifikasi_kebutuhan_id' => 'required',
            'data_vendor_id'            => 'required',
            'berita_acara'              => 'nullable|file|mimes:pdf,doc,docx|max:2048',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => $validator->errors(),
                'data'    => []
            ]);
        }

        try {
            $filePath = null;

            $this->pengumpulanDataService->updateDataVerifikasiPengawas($request);
            $this->pengumpulanDataService->pemeriksaanDataList($request);

            $data = $this->pengumpulanDataService->changeStatusVerification(
                $request['identifikasi_kebutuhan_id'],
                $filePath
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Data berhasil disimpan',
                'data'    => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gagal menyimpan data',
                'error'   => $e->getMessage()
            ]);
        }
    }
}
