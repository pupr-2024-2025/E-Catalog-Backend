<?php

namespace App\Http\Middleware;

use App\Helpers\Helper;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Models\SatuanBalaiKerja;
use App\Models\Users;
use Illuminate\Support\Carbon;

class VerifySipastiJwt
{
    public function handle(Request $request, Closure $next)
    {
        // 1) Ambil token
        $auth  = $request->header('Authorization', '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $request->cookie('sipasti_token');
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // 2) Decode
        JWT::$leeway = (int) Config::get('services.sipasti.jwt_leeway', env('SIPASTI_JWT_LEEWAY', 5));
        try {
            $algo = Config::get('services.sipasti.jwt_algo', env('SIPASTI_JWT_ALGO', 'HS256'));
            if ($algo !== 'HS256') {
                return response()->json(['error' => 'Unsupported JWT algo'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $secret = Config::get('services.sipasti.jwt_secret', env('SIPASTI_JWT_SECRET'));
            if (!$secret) {
                return response()->json(['error' => 'JWT secret not configured'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (ExpiredException) {
            return response()->json(['error' => 'Token expired'], Response::HTTP_UNAUTHORIZED);
        } catch (BeforeValidException) {
            return response()->json(['error' => 'Token not yet valid'], Response::HTTP_UNAUTHORIZED);
        } catch (SignatureInvalidException) {
            return response()->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
        }

        // 3) Ambil klaim seperlunya
        $claims = (array) $decoded;
        $profile = [
            'id'         => $claims['sub']        ?? null,
            'email'      => $claims['email']      ?? null,
            'nama'       => $claims['nama']       ?? null,
            'role'       => $claims['role']       ?? null,       // role SIPASTI
            'balai_name' => $claims['balai_name'] ?? null,
        ];
        $userIdSipasti = (string) ($profile['id'] ?? '');
        $email         = isset($profile['email']) ? trim((string) $profile['email']) : null;
        if ($userIdSipasti === '' && !$email) {
            return response()->json(['error' => 'Invalid profile payload'], Response::HTTP_UNAUTHORIZED);
        }

        // 4) Map role dari SIPASTI → internal name (kepala-balai → pj balai; superadmin → superadmin; selain itu guest)
        $rawRole  = Str::lower((string)($profile['role'] ?? ''));
        $roleNameFromSipasti = match (true) {
            Str::contains($rawRole, 'kepala-balai') => 'pj balai',
            $rawRole === 'superadmin'               => 'superadmin',
            default                                 => 'guest',
        };

        // 5) Ambil role_id untuk guest & hasil mapping
        $roleMap = Helper::getRoleMap();            // mis: ['guest'=>1,'pj balai'=>2, ...]
        $guestId = $roleMap['guest'] ?? null;
        $sipastiRoleId = $roleMap[$roleNameFromSipasti] ?? $guestId;

        $where   = $userIdSipasti !== '' ? ['user_id_sipasti' => $userIdSipasti] : ['email' => $email];
        $existing = Users::where($where)->first();
        $currentRoleId = $existing->id_roles ?? null;

        // 7) Tentukan finalRoleId dengan aturan NO-DOWNGRADE:
        //    - Kalau user sudah punya role BUKAN guest -> JANGAN UBAH (pertahankan dari DB; biasanya dari e-Katalog)
        //    - Kalau masih kosong / guest -> isi dengan hasil mapping dari SIPASTI
        $isCurrentGuest = $guestId && $currentRoleId && ((int)$currentRoleId === (int)$guestId);
        $shouldFillFromSipasti = is_null($currentRoleId) || $isCurrentGuest;

        $finalRoleId = $shouldFillFromSipasti ? $sipastiRoleId : $currentRoleId;

        // 8) Resolve balai_kerja_id (read-only)
        $balaiId = null;
        $namaBalai = trim((string)($profile['balai_name'] ?? ''));
        if ($namaBalai !== '') {
            $balaiId = SatuanBalaiKerja::whereRaw('LOWER(nama) = ?', [Str::lower($namaBalai)])->value('id');
        }

        // 9) Simpan / perbarui user: hanya update kolom role jika memang finalRoleId berubah (tapi tetap aman no-downgrade)
        $cacheKey = 'user_profile_model:' . ($userIdSipasti ?: 'email:' . $email);
        $model = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($where, $profile, $email, $finalRoleId, $balaiId, $existing) {
            return Users::updateOrCreate(
                $where,
                [
                    'nama_lengkap'      => $profile['nama'],
                    'email'             => $email,
                    'no_handphone'      => $existing->no_handphone ?? ($profile['phone'] ?? null),
                    'nik'               => $existing->nik ?? ($profile['detail']['nik'] ?? ($profile['nik'] ?? null)),
                    'nrp'               => $existing->nrp ?? ($profile['detail']['nrp'] ?? ($profile['nrp'] ?? null)),
                    'nip'               => $existing->nip ?? ($profile['detail']['nip'] ?? ($profile['nip'] ?? null)),
                    'id_roles'          => $finalRoleId,       // ← sudah dilindungi no-downgrade
                    'email_verified_at' => $existing->email_verified_at ?? now(),
                    'status'            => $existing->status ?? 'active',
                    'balai_kerja_id'    => $balaiId ?? $existing->balai_kerja_id ?? null,
                    'user_id_sipasti'   => $where['user_id_sipasti'] ?? ($existing->user_id_sipasti ?? null),
                ]
            );
        });

        Auth::setUser($model);
        $request->attributes->add([
            'auth_user' => [
                'id'               => $model->id,
                'nama_lengkap'     => $model->nama_lengkap,
                'email'            => $model->email,
                'no_handphone'     => $model->no_handphone,
                'nik'              => $model->nik,
                'nrp'              => $model->nrp,
                'nip'              => $model->nip,
                'id_roles'         => $model->id_roles,
                'status'           => $model->status,
                'balai_kerja_id'   => $model->balai_kerja_id,
                'satuan_kerja_id'  => $model->satuan_kerja_id,
                'user_id_sipasti'  => $model->user_id_sipasti,
            ],
        ]);

        return $next($request);
    }
}
