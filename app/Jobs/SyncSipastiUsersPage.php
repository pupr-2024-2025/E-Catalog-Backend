<?php

namespace App\Jobs;

use App\Helpers\Helper;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncSipastiUsersPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $page;
    public int $perPage;

    public $tries = 5;
    public $backoff = [10, 30, 60];  // jeda retry (detik)
    public $timeout = 120;           // detik

    public function __construct(int $page = 1, int $perPage = 500)
    {
        $this->page = $page;
        $this->perPage = $perPage;
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        $roleMap = Helper::getRoleMap();
        $guestId  = $roleMap['guest'] ?? null;

        $resp = app('App\Services\UserService')->getUserSipasti(page: $this->page, perPage: $this->perPage);
        $items = $resp['data'] ?? [];
        if (empty($items)) return;

        $rows = collect($items)->map(function ($u) use ($roleMap, $guestId) {
            $raw = Str::lower((string)($u['role'] ?? ''));

            $roleName = match (true) {
                Str::contains($raw, 'kepala balai')     => 'pj balai',
                $raw === 'superadmin' || $raw === 'Superadmin' => 'superadmin',
                default                                  => 'guest',
            };

            $roleId = $roleMap[$roleName] ?? $guestId;

            return [
                'user_sipasti_id' => $u['id'],
                'nama_lengkap'  => $u['name'] ?? null,
                'no_handphone'  => $u['name'] ?? null,
                'nik'   => $u['name'] ?? null,
                'satuan_kerja_id'   => null,
                'balai_kerja_id'    => null,
                'status' => 'active',
                'email' => $u['email'] ?? null,
                'nip'   => $u['nip'] ?? null,
                'role_id'   => $roleId,
            ];
        });

        foreach ($rows->chunk($this->perPage) as $chunk) {
            DB::transaction(function () use ($chunk) {
                DB::table('users')->upsert(
                    $chunk->all(),
                    ['user_sipasti_id'],
                    ['name', 'email', 'phone', 'role', 'updated_at']
                );
            });
        }

        // Jika halaman penuh, berarti kemungkinan masih ada berikutnya
        if (count($items) === $this->perPage) {
            dispatch(new self($this->page + 1, $this->perPage))->onQueue('sync');
        }
    }
}
