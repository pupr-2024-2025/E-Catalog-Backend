<?php

namespace App\Http\Controllers;

use App\Models\Roles;
use App\Models\Users;
use App\Services\UserService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class UsersController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_lengkap' => 'required|string|max:255',
            'nik' => 'required|integer',
            'email' => 'required|string|email|max:255',
            'nrp' => 'string|max:255',
            'satuan_kerja_id' => 'required',
            'balai_kerja_id' => 'required',
            'no_handphone' => 'required|string',
            'surat_penugasan_url' => 'required|file|mimes:pdf,doc,docx|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'validasi gagal!',
                'errors' => $validator->errors()
            ]);
        }

        $checkNik = $this->userService->checkNik($request->nik);
        if ($checkNik) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nik sudah terdaftar!',
                'data' => []
            ]);
        }

        try {

            if ($request->hasFile('surat_penugasan_url')) {
                $filePath = $request->file('surat_penugasan_url')->store('sk_penugasan');
            }

            $user = new Users();
            $user->nama_lengkap = $request->nama_lengkap;
            $user->no_handphone = $request->no_handphone;
            $user->nik = $request->nik;
            $user->email = $request->email;
            $user->nrp = $request->nrp;
            $user->surat_penugasan_url = $filePath;
            $user->satuan_kerja_id = $request->satuan_kerja_id;
            $user->balai_kerja_id = $request->balai_kerja_id;
            $user->status = 'register';
            $user->id_roles = 2; //menyusul tergantung ntarnya
            $user->save();

            event(new Registered($user)); //send email verification

            return response()->json([
                'status' => 'success',
                'message' => 'Pengguna berhasil disimpan',
                'data' => $user
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan pengguna',
                'error' => $th->getMessage()
            ]);
        }
    }

    public function updateRole(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role'    => ['required', 'string'],
        ]);

        if ($validated->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validasi gagal.',
                'errors'  => $validated->errors(),
            ], 422);
        }

        $userId = (int) $request->input('user_id');
        $roleNameRaw = trim((string) $request->input('role'));

        $role = Roles::query()
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($roleNameRaw)])
            ->first();

        if (!$role) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Role tidak ditemukan.',
            ], 422);
        }

        Users::where('id', $userId)->update(['id_roles' => $role->id]);

        $user = Users::query()
            ->leftJoin('roles', 'users.id_roles', '=', 'roles.id')
            ->leftJoin('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
            ->select([
                'users.id as user_id',
                'users.nama_lengkap',
                'users.nrp',
                'satuan_kerja.nama as satuan_kerja_name',
                'roles.nama as role',
                'users.surat_penugasan_url as surat_penugasan',
            ])
            ->where('users.id', $userId)
            ->first();

        return response()->json([
            'status'  => 'success',
            'message' => 'Role pengguna berhasil diperbarui.',
            'data'    => $user,
        ], 200);
    }


    public function getUserById($id)
    {
        try {

            $token = JWTAuth::parseToken();
            $payload = $token->getPayload();
            $payloadId = $payload['sub'];

            if ($payloadId !== $id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorize Access'
                ], 401);
            }

            $getUser = $this->userService->checkUserIfExist($id);
            if (is_null($getUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'data dengan id ' . $id . ' tidak ditemukan!',
                    'data' => []
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'berhasil menampilkan data',
                'data' => $getUser
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'gagal mendaptakan data',
                'data' => []
            ]);
        }
    }

    public function listRole()
    {
        $getRole = Roles::select('id', 'nama')->get();
        return response()->json([
            'status' => 'success',
            'message' => 'berhasil menampilkan data',
            'data' => $getRole
        ]);
    }

    public function getListUserVerification()
    {
        $list = $this->userService->listUser();
        return response()->json([
            'status' => 'success',
            'message' => 'berhasil menampilkan data',
            'data' => $list
        ]);
    }

    public function getProfileUser(Request $request)
    {
        $authUser = $request->attributes->get('auth_user', []);
        $userIdSipasti = $authUser['user_id_sipasti'] ?? null;
        if (!$userIdSipasti) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User ID tidak ditemukan di token'
            ], 400);
        }

        $user = Users::where("user_id_sipasti", $userIdSipasti)->first();
        if (!$user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User tidak ditemukan'
            ], 404);
        }
        $data = [
            "nama_lengkap" => $user->nama_lengkap,
            "no_handphone" => $user->no_handphone,
            "nik" => $user->nik,
            "status" => $user->status,
            "email" => $user->email,
            "role" => $user->role->nama,
            "nrp" => $user->nrp,
            "nip" => $user->nip,
            "user_id_sipasti" => $user->user_id_sipasti,
            "satuan_kerja" => $user->satuanKerja->nama ?? null,
            "balai_kerja" => $user->balaiSatuanKerja->nama ?? null,
            "balai_id" => $user->balaiSatuanKerja->id,
            "surat_penugasan_url" => $user->surat_penugasan_url,
        ];

        return response()->json([
            'status'  => 'success',
            'message' => 'Berhasil mendapatkan data user',
            'data'    => $data,
        ]);
    }

    public function listByRoleAndByBalai(Request $request)
    {
        $authUser = $request->attributes->get('auth_user', []);
        $balaiKey =  $request->query("balai_key") ?? $authUser['balai_kerja_id'];
        $role = $request->query('role');

        // ✅ Validasi parameter
        $validator = Validator::make([
            'balai_key' => $balaiKey,
            'role'      => $role,
        ], [
            'balai_key' => 'required|integer|min:1',
            'role'      => ['required', 'string', Rule::in([
                'pengawas',
                'petugas lapangan',
                'pengolah data',
                'tim teknis balai',
                'guest'
            ])],
        ], [
            'balai_key.required' => 'Parameter balai_key wajib disertakan.',
            'balai_key.integer'  => 'Parameter balai_key harus berupa angka.',
            'balai_key.min'      => 'Parameter balai_key minimal bernilai 1.',
            'role.required'      => 'Parameter role wajib disertakan.',
            'role.string'        => 'Parameter role harus berupa teks.',
            'role.in'            => 'Role tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = [
            'balai_key' => (int) $balaiKey, // pastikan integer
            'role'      => $role,
        ];

        $result = $this->userService->listUserByRoleAndBalai($data);

        if (!$result) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan saat mengambil data.',
                'data' => []
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil menampilkan data.',
            'data' => $result
        ], 200);
    }

    public function listByRoleAndByBalaiStatusStandby(Request $request)
    {

        $balaiKey = $request->query('balai_key');
        $role = $request->query('role');

        // ✅ Step 2: Validate both parameters
        $validator = Validator::make([
            'balai_key' => $balaiKey,
            'role'      => $role,
        ], [
            'balai_key' => 'required|string|min:1',
            'role'      => ['required', 'string', Rule::in(['pengawas', 'petugas lapangan', 'pengolah data', 'tim teknis balai'])],
        ], [
            'balai_key.required' => 'Parameter balai_key wajib disertakan.',
            'balai_key.string'   => 'Parameter balai_key harus berupa teks.',
            'role.required'      => 'Parameter role wajib disertakan.',
            'role.in'            => 'Role tidak valid.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = [
            'balai_key' => $balaiKey,
            'role'      => $role,
        ];

        $result = $this->userService->listUserByRoleAndBalaiStatusStandby($data);

        if (!$result) {
            return response()->json([
                'status' => 'error',
                'message' => 'terjadi kesalahan',
                'data' => []
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'berhasil menampilkan data',
            'data' => $result
        ], 200);
    }

    public function listUserByNamaBalai(Request $request)
    {
        // Ambil dari token (fallback)
        $authUser      = $request->attributes->get('auth_user', []);
        $authBalaiId   = $authUser['balai_kerja_id'] ?? null;   // ID balai dari token
        $authBalaiName = $authUser['balai_kerja']    ?? null;   // NAMA balai dari token (jika ada)

        // Ambil dari query (prioritas), fallback ke auth
        $balaiKey  = $request->query('balai_key',  $authBalaiId);
        $namaBalai = $request->query('nama_balai', $authBalaiName);

        // Validasi: salah satu wajib ada
        $validator = Validator::make(
            ['balaiKey' => $balaiKey, 'namaBalai' => $namaBalai],
            [
                'balaiKey'  => ['nullable', 'required_without:namaBalai', 'bail', 'integer'],
                'namaBalai' => ['nullable', 'required_without:balaiKey', 'bail', 'string'],
            ],
            [
                'balaiKey.required_without'  => 'Parameter balai_key wajib diisi jika nama_balai tidak ada.',
                'namaBalai.required_without' => 'Parameter nama_balai wajib diisi jika balai_key tidak ada.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Siapkan payload ke service (dua nama kunci untuk kompatibilitas)
        $data = array_filter([
            'id_balai'   => $balaiKey,
            'balai_key'  => $balaiKey,
            'nama_balai' => $namaBalai,
        ], static fn($v) => !is_null($v) && $v !== '');

        try {
            $result = $this->userService->listUserByNamaBalaiOrIdBalai($data);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'terjadi kesalahan',
                'data'    => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Pastikan $result array biasa (bukan Collection) agar outputnya konsisten
        if ($result instanceof \Illuminate\Support\Collection) {
            $result = $result->toArray();
        }

        // ——— Struktur respons HARUS persis seperti contoh ———
        return response()->json([
            'status'  => 'success',
            'message' => 'berhasil menampilkan data',
            'data'    => $result,
        ], Response::HTTP_OK);
    }

    public function unassignRoleAndPenugasan(Request $request) {}
}
