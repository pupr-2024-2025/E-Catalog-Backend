<?php

namespace App\Services;

use App\Models\DataVendor;
use App\Models\PerencanaanData;
use App\Models\ShortlistVendor;
use App\Models\KuisionerPdfData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShortlistVendorService
{
    private function getIdentifikasiKebutuhanByIdentifikasiId($id)
    {
        $getDataIdentifikasi = PerencanaanData::with([
            'material:id,identifikasi_kebutuhan_id,nama_material,spesifikasi,ukuran',
            'peralatan:id,identifikasi_kebutuhan_id,nama_peralatan,spesifikasi,kapasitas',
            'tenagaKerja:id,identifikasi_kebutuhan_id,jenis_tenaga_kerja'
        ])->select('identifikasi_kebutuhan_id')->where('identifikasi_kebutuhan_id', $id)
            ->get();

        $phrasesOf = function (string $rel, string $col) use ($getDataIdentifikasi): Collection {
            return $getDataIdentifikasi
                ->flatMap(fn($i) => optional($i->{$rel})->pluck($col))
                ->filter()
                ->map(fn($s) => trim((string) $s));
        };

        $makeKeywords = function (Collection $strings): Collection {
            return $strings
                ->map(fn($s) => mb_strtolower($s, 'UTF-8'))
                ->flatMap(function ($str) {
                    $parts = preg_split('/\s+/u', trim($str));
                    if (!$parts || $parts[0] === '') return [];
                    $out = [$parts[0]];
                    if (count($parts) > 1) $out[] = $parts[0] . ' ' . $parts[1];
                    return $out;
                })
                ->map(fn($s) => trim(preg_replace('/\s+/u', ' ', $s)))
                ->filter();
        };

        $materials   = $phrasesOf('material',   'nama_material');
        $peralatans  = $phrasesOf('peralatan',  'nama_peralatan');
        $tenagaKerja = $phrasesOf('tenagaKerja', 'jenis_tenaga_kerja');

        $keywordsByKey = [
            'material'      => $makeKeywords($materials)->unique()->values()->all(),
            'peralatan'     => $makeKeywords($peralatans)->unique()->values()->all(),
            'tenaga_kerja'  => $makeKeywords($tenagaKerja)->unique()->values()->all(),
        ];

        return $keywordsByKey;
    }

    private function selectedVendorIdMap(int $identifikasiId): array
    {
        $ids = ShortlistVendor::where('shortlist_vendor_id', $identifikasiId)
            ->pluck('data_vendor_id')
            ->all();
        return array_fill_keys(array_map('strval', $ids), true);
    }

    private function selectedResourcesForVendor(int $identifikasiId, int $vendorId): array
    {
        $row = KuisionerPdfData::where('shortlist_id', $identifikasiId)
            ->where('vendor_id', $vendorId)
            ->first();

        return [
            'material'     => $row && $row->material_id     ? json_decode($row->material_id, true)     : [],
            'peralatan'    => $row && $row->peralatan_id    ? json_decode($row->peralatan_id, true)    : [],
            'tenaga_kerja' => $row && $row->tenaga_kerja_id ? json_decode($row->tenaga_kerja_id, true) : [],
        ];
    }

    public function getDataVendor($id)
    {
        $resultArray = $this->getIdentifikasiKebutuhanByIdentifikasiId($id);

        $queryDataVendors = DataVendor::query()
            ->withWhereHas('sumber_daya_vendor', function ($q) use ($resultArray) {
                $q->where(function ($or) use ($resultArray) {
                    foreach ($resultArray as $jenis => $terms) {
                        if (count($terms) !== 0) {
                            $or->orWhere(function ($w) use ($jenis, $terms) {
                                $w->where('jenis', $jenis)
                                    ->where(function ($names) use ($terms) {
                                        foreach ($terms as $t) {
                                            $names->orWhereRaw('LOWER(nama) LIKE ?', ['%' . mb_strtolower($t, 'UTF-8') . '%']);
                                        }
                                    });
                            });
                        }
                    }
                });
            })
            ->get();

        $result = [];
        foreach ($queryDataVendors as $vendor) {
            $grouped = collect($vendor->sumber_daya_vendor)->groupBy('jenis');

            foreach ($grouped as $jenis => $list) {
                $result[$jenis][] = [
                    'id' => $vendor->id,
                    'nama_vendor' => $vendor->nama_vendor,
                    'pemilik' => $vendor->nama_pic,
                    'alamat' => $vendor->alamat,
                    'kontak' => $vendor->no_telepon,
                    'sumber_daya' => $vendor->sumber_daya,
                    'sumber_daya_vendor' => $list->map(function ($sd) {
                        return [
                            'id'          => $sd['id'],
                            'jenis'       => $sd['jenis'],
                            'nama'        => $sd['nama'],
                            'spesifikasi' => $sd['spesifikasi']
                        ];
                    })->toArray()
                ];
            }
        }

        $identifikasiId = (int) $id;

        $selectedByVendorId = $this->selectedVendorIdMap($identifikasiId);

        foreach (['material', 'peralatan', 'tenaga_kerja'] as $jenis) {
            if (!isset($result[$jenis])) {
                continue;
            }

            $result[$jenis] = array_map(function (array $row) use ($identifikasiId, $jenis, $selectedByVendorId) {
                $vendorId = (int) $row['id'];

                $row['selected_resources'] = $this->selectedResourcesForVendor($identifikasiId, $vendorId);

                $isSelected = count($row['selected_resources'][$jenis] ?? []) > 0;

                if (!$isSelected && isset($selectedByVendorId[(string)$vendorId])) {
                    $isSelected = true;
                }

                $row['is_selected'] = $isSelected;

                return $row;
            }, $result[$jenis]);
        }

        return $result;
    }


    public function storeShortlistVendor($data, $shortlistVendorId)
    {
        $shortlistVendorArray = [
            'data_vendor_id' => $data['data_vendor_id'],
            'shortlist_vendor_id' => $shortlistVendorId,
            'nama_vendor' => $data['nama_vendor'],
            'pemilik_vendor' => $data['pemilik_vendor'],
            'alamat' => $data['alamat'],
            'kontak' => $data['kontak'],
            'sumber_daya' => $data['sumber_daya']
        ];

        $shortlistVendor = ShortlistVendor::updateOrCreate(
            [
                'data_vendor_id' => $data['data_vendor_id'],
                'shortlist_vendor_id' => $shortlistVendorId
            ],
            $shortlistVendorArray
        );

        return $shortlistVendor->toArray();
    }

    public function getShortlistVendorResult($id)
    {
        return ShortlistVendor::where('shortlist_vendor_id', $id)->get();
    }

    private function eleminationArray(array $array1, array $array2)
    {
        $matches = [];

        $lowercasedArray1 = array_map('strtolower', $array1);
        $lowercasedArray2 = array_map('strtolower', $array2);

        foreach ($lowercasedArray1 as $value1) {
            foreach ($lowercasedArray2 as $value2) {
                if (strpos($value1, $value2) !== false) {
                    $matches[] = $value1;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    public function getIdentifikasiByShortlist($shortlistId, $informasiUmumId)
    {
        $query = ShortlistVendor::with([
            'material' => function ($sub) {
                $sub->select('id', 'identifikasi_kebutuhan_id', 'nama_material', 'satuan', 'spesifikasi', 'merk');
            },
            'peralatan' => function ($sub) {
                $sub->select('id', 'identifikasi_kebutuhan_id', 'nama_peralatan', 'satuan', 'spesifikasi', 'merk');
            },
            'tenaga_kerja' => function ($sub) {
                $sub->select('id', 'identifikasi_kebutuhan_id', 'jenis_tenaga_kerja', 'satuan');
            },
        ])
            ->where('shortlist_vendor.id', $shortlistId)
            ->where(function ($q) use ($informasiUmumId) {
                $q->whereHas('material', fn($s) => $s->where('identifikasi_kebutuhan_id', $informasiUmumId))
                    ->orWhereHas('peralatan', fn($s) => $s->where('identifikasi_kebutuhan_id', $informasiUmumId))
                    ->orWhereHas('tenaga_kerja', fn($s) => $s->where('identifikasi_kebutuhan_id', $informasiUmumId));
            })
            ->select('id', 'data_vendor_id', 'shortlist_vendor_id', 'nama_vendor', 'pemilik_vendor', 'alamat', 'kontak', 'sumber_daya')
            ->first();

        if (!$query) {
            return [
                'id_vendor' => null,
                'identifikasi_kebutuhan' => [
                    'material' => [],
                    'peralatan' => [],
                    'tenaga_kerja' => [],
                ],
            ];
        }

        $tokens = [];
        if (!empty($query->sumber_daya)) {
            $parts = preg_split('/[;,]+/', (string)$query->sumber_daya);
            $tokens = collect($parts)
                ->map(fn($x) => mb_strtolower(trim($x)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($tokens)) {
            return [
                'id_vendor' => (int)$query->data_vendor_id,
                'identifikasi_kebutuhan' => [
                    'material' => [],
                    'peralatan' => [],
                    'tenaga_kerja' => [],
                ],
            ];
        }

        $identifikasi = [
            'material'     => [],
            'peralatan'    => [],
            'tenaga_kerja' => [],
        ];

        $contains = function (string $haystack, string $needle): bool {
            return mb_stripos($haystack, $needle) !== false;
        };

        foreach ($tokens as $t) {
            foreach ($query->material ?? [] as $row) {
                if ($contains(mb_strtolower($row->nama_material), $t)) {
                    $identifikasi['material'][$row->id] = $row;
                }
            }
            foreach ($query->peralatan ?? [] as $row) {
                if ($contains(mb_strtolower($row->nama_peralatan), $t)) {
                    $identifikasi['peralatan'][$row->id] = $row;
                }
            }
            foreach ($query->tenaga_kerja ?? [] as $row) {
                if ($contains(mb_strtolower($row->jenis_tenaga_kerja), $t)) {
                    $identifikasi['tenaga_kerja'][$row->id] = $row;
                }
            }
        }

        foreach ($identifikasi as $k => $v) {
            $identifikasi[$k] = array_values($v);
        }

        return $identifikasi;
    }

    public function saveKuisionerPdfData($idVendor, $idShortlistVendor, $material, $peralatan, $tenagaKerja)
    {
        $kuisionerData = KuisionerPdfData::updateOrCreate(
            ['shortlist_id' => $idShortlistVendor, 'vendor_id' => $idVendor],
            [
                'material_id' => (count($material)) ? json_encode($material) : null,
                'peralatan_id' => (count($peralatan)) ? json_encode($peralatan) : null,
                'tenaga_kerja_id' => (count($tenagaKerja)) ? json_encode($tenagaKerja) : null,
            ]
        );

        return $kuisionerData->toArray();
    }

    public function saveUrlPdf($vendorId, $shortlistVendorId, $url)
    {
        $dataVendor = DataVendor::find($vendorId);
        if (!$dataVendor) {
            throw new \Exception("DataVendor with id $vendorId not found.");
        }

        $namaVendor = $dataVendor->nama_vendor;
        $pemilikVendor = $dataVendor->nama_pic;
        $alamat = $dataVendor->alamat;
        $no_telepon = $dataVendor->no_telepon ?? $dataVendor->no_hp;

        $data = ShortlistVendor::updateOrCreate(
            ['data_vendor_id' => $vendorId, 'shortlist_vendor_id' => $shortlistVendorId],
            [
                'url_kuisioner' => $url,
                'nama_vendor' => $namaVendor,
                'pemilik_vendor' => $pemilikVendor,
                'alamat' => $alamat,
                'kontak' => $no_telepon
            ]
        );
        return $data['url_kuisioner'];
    }
}
