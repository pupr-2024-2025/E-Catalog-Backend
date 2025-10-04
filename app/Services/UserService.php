<?php

namespace App\Services;

use App\Models\Users;
use App\Models\PerencanaanData;
use App\Models\Roles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function checkNik($nik)
    {
        return Users::where('nik', $nik)->exists();
    }

    public function checkUserIfExist($userId)
    {
        return Users::join('accounts', 'users.id', '=', 'accounts.user_id')
            ->where('users.id', $userId)
            ->whereNull('users.email_verified_at')
            ->select('users.*')->first();
    }

    public function listUser()
    {
        $rows = Users::query()
            ->leftJoin('roles', 'users.id_roles', '=', 'roles.id')
            ->leftJoin('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
            ->leftJoin('satuan_balai_kerja', 'users.balai_kerja_id', '=', 'satuan_balai_kerja.id')
            ->leftJoin('team_teknis_balai_members as ttbm', function ($join) {
                $join->on('ttbm.user_id', '=', 'users.id')->whereNull('ttbm.deleted_at');
            })
            ->leftJoin('team_teknis_balai as ttb', 'ttbm.team_id', '=', 'ttb.id')
            ->where('users.status', 'verification')
            ->whereNotNull('users.email_verified_at')
            ->where('users.id_roles', '!=', 1)
            ->select([
                'users.id AS user_id',
                'users.nama_lengkap',
                'users.no_handphone',
                'users.nrp as nrp',
                DB::raw('satuan_kerja.nama AS satuan_kerja'),
                DB::raw('satuan_balai_kerja.nama AS balai_kerja'),
                'users.email',
                'users.surat_penugasan_url AS sk_penugasan',
                DB::raw('roles.nama as role'),
                DB::raw("CASE WHEN LOWER(roles.nama) = 'tim teknis balai' THEN GROUP_CONCAT(DISTINCT ttb.nama_team ORDER BY ttb.nama_team SEPARATOR ', ') ELSE NULL END as nama_team"),
            ])
            ->groupBy([
                'users.id',
                'users.nama_lengkap',
                'users.no_handphone',
                'users.nrp',
                'satuan_kerja.nama',
                'satuan_balai_kerja.nama',
                'users.email',
                'users.surat_penugasan_url',
                'roles.nama',
            ])
            ->orderBy('users.id', 'asc')
            ->get();

        return $rows->map(function ($r) {
            $suratUrl = $r->sk_penugasan ? url(Storage::url($r->sk_penugasan)) : null;
            return [
                'user_id' => (int) $r->user_id,
                'nama_lengkap' => $r->nama_lengkap,
                'no_handphone' => $r->no_handphone,
                'nrp' => $r->nrp,
                'satuan_kerja' => $r->satuan_kerja,
                'balai_kerja' => $r->balai_kerja,
                'email' => $r->email,
                'sk_penugasan' => $suratUrl,
                'role' => $r->role,
                'nama_team' => $r->nama_team,
            ];
        })->values();
    }

    public function listUserByRoleAndBalai($data)
    {
        $roleName = $data['role'];
        $balaiKey = $data['balai_key'];

        $users = Users::select([
            'users.id AS user_id',
            'users.nama_lengkap',
            'users.nrp',
            'satuan_kerja.nama AS satuan_kerja_name',
            'users.surat_penugasan_url as surat_penugasan'
        ])
            ->leftJoin('roles', 'users.id_roles', '=', 'roles.id')
            ->leftJoin('satuan_balai_kerja', 'users.balai_kerja_id', '=', 'satuan_balai_kerja.id')
            ->leftJoin('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
            ->where('users.status', 'active')
            ->whereNotNull('users.email_verified_at')
            ->where('users.id_roles', '!=', 1)
            ->where('roles.nama', $roleName)
            ->where('satuan_balai_kerja.id', $balaiKey)
            ->get();

        $result = $users->map(function ($user) use ($roleName) {
            $exists = false;

            if ($roleName === 'pengawas') {
                $exists = PerencanaanData::whereJsonContains('pengawas_id', (string)$user->user_id)->exists();
            } elseif ($roleName === 'pengolah data') {
                $exists = PerencanaanData::whereJsonContains('pengolah_data_id', (string)$user->user_id)->exists();
            } elseif ($roleName === 'petugas lapangan') {
                $exists = PerencanaanData::whereJsonContains('petugas_lapangan_id', (string)$user->user_id)->exists();
            } elseif ($roleName === 'tim teknis balai') {
                $exists = PerencanaanData::whereJsonContains('team_teknis_balai_id', (string)$user->user_id)->exists();
            }

            return [
                'user_id' => $user->user_id,
                'nama_lengkap' => $user->nama_lengkap,
                'nrp' => $user->nrp,
                'satuan_kerja_name' => $user->satuan_kerja_name,
                'status_penugasan' => $exists ? 'ditugaskan' : 'tidak ditugaskan',
                'surat_penugasan' => $user->surat_penugasan
            ];
        });

        return $result;
    }

    public function listUserByRoleAndBalaiStatusStandby($data)
    {
        $roleName = $data['role'];
        $balaiKey = $data['balai_key'];

        $users = Users::select([
            'users.id AS user_id',
            'users.nama_lengkap',
            'users.nrp',
            'satuan_kerja.nama AS satuan_kerja_name',
            'users.surat_penugasan_url as surat_penugasan'
        ])
            ->leftJoin('roles', 'users.id_roles', '=', 'roles.id')
            ->leftJoin('satuan_balai_kerja', 'users.balai_kerja_id', '=', 'satuan_balai_kerja.id')
            ->leftJoin('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
            ->where('users.status', 'active')
            ->whereNotNull('users.email_verified_at')
            ->where('users.id_roles', '!=', 1)
            ->where('roles.nama', $roleName)
            ->where('satuan_balai_kerja.id', $balaiKey)
            ->get();

        $result = $users->filter(function ($user) use ($roleName) {
            if ($roleName === 'pengawas') {
                return !PerencanaanData::whereJsonContains('pengawas_id', (string)$user->user_id)->exists();
            } elseif ($roleName === 'pengolah data') {
                return !PerencanaanData::whereJsonContains('pengolah_data_id', (string)$user->user_id)->exists();
            } elseif ($roleName === 'petugas lapangan') {
                return !PerencanaanData::whereJsonContains('petugas_lapangan_id', (string)$user->user_id)->exists();
            } else if ($roleName === 'tim teknis balai') {
                return !PerencanaanData::whereJsonContains('team_teknis_balai_id', (string)$user->user_id)->exists();
            }
            return true;
        })->map(function ($user) {
            return [
                'user_id' => $user->user_id,
                'nama_lengkap' => $user->nama_lengkap,
                'nrp' => $user->nrp,
                'satuan_kerja_name' => $user->satuan_kerja_name,
                'status_penugasan' => 'tidak ditugaskan',
                'surat_penugasan' => $user->surat_penugasan
            ];
        })->values();

        return $result;
    }

    public function updateRole($userId, $ignored = null)
    {
        return DB::transaction(function () use ($userId) {
            $guestId = Roles::whereRaw('LOWER(nama) = ?', ['guest'])->value('id');
            if (!$guestId) return null;

            Users::where('id', $userId)->update(['id_roles' => $guestId]);

            DB::table('team_teknis_balai_members')->where('user_id', $userId)->delete();
            DB::table('pengawas')->where('user_id', $userId)->delete();
            DB::table('petugas_lapangan')->where('user_id', $userId)->delete();
            DB::table('pengolah_data')->where('user_id', $userId)->delete();

            $cols = ['pengawas_id', 'petugas_lapangan_id', 'pengolah_data_id', 'team_teknis_balai_id'];
            $rows = PerencanaanData::query()->where(function ($q) use ($cols, $userId) {
                foreach ($cols as $c) $q->orWhereJsonContains($c, (string) $userId);
            })->get(array_merge(['id'], $cols));

            foreach ($rows as $r) {
                $updates = [];
                foreach ($cols as $c) {
                    $arr = json_decode($r->{$c}, true);
                    if (is_array($arr)) {
                        $next = array_values(array_filter($arr, fn($v) => (string) $v !== (string) $userId));
                        if ($next !== $arr) $updates[$c] = json_encode($next);
                    }
                }
                if ($updates) PerencanaanData::where('id', $r->id)->update($updates);
            }

            return Users::find($userId);
        });
    }



    public function listUserByNamaBalaiOrIdBalai(array $data)
    {
        $idBalai   = $data['id_balai']   ?? $data['balai_key'] ?? null;
        $namaBalai = $data['nama_balai'] ?? null;

        $q = Users::query()
            ->select([
                'users.id AS user_id',
                'users.nama_lengkap',
                'users.nrp',
                'satuan_kerja.nama AS satuan_kerja_name',
                'users.surat_penugasan_url as surat_penugasan',
                'roles.nama as role',
            ])
            ->leftJoin('roles', 'users.id_roles', '=', 'roles.id')
            ->leftJoin('satuan_balai_kerja', 'users.balai_kerja_id', '=', 'satuan_balai_kerja.id')
            ->leftJoin('satuan_kerja', 'users.satuan_kerja_id', '=', 'satuan_kerja.id')
            ->where('users.status', 'active')
            ->whereNotNull('users.email_verified_at');

        if (!is_null($idBalai)) {
            $q->where('satuan_balai_kerja.id', $idBalai);
        } elseif (!is_null($namaBalai) && $namaBalai !== '') {
            $q->whereRaw('LOWER(satuan_balai_kerja.nama) = ?', [mb_strtolower($namaBalai)]);
        }

        $users = $q->get();

        return $users->map(function ($user) {
            return [
                'user_id'           => (int) $user->user_id,
                'nama_lengkap'      => $user->nama_lengkap,
                'nrp'               => $user->nrp,
                'satuan_kerja_name' => $user->satuan_kerja_name,
                'role'              => $user->role,
                'surat_penugasan'   => $user->surat_penugasan,
            ];
        })->values();
    }
}
