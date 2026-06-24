<?php

use App\Models\DocumentCounter;
use App\Models\GoodsReceive;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (!function_exists('mapDepartmentCodeById')) {
    function mapDepartmentCodeById(int $departmentId): string
    {
        $department = DB::table('departments')
            ->select('id', 'kode', 'nama')
            ->where('id', $departmentId)
            ->first();

        if (!$department) {
            throw new Exception("Department ID tidak ditemukan: {$departmentId}");
        }

        $kode = trim((string) ($department->kode ?? ''));

        if ($kode !== '') {
            return strtoupper($kode);
        }

        $nama = trim((string) ($department->nama ?? ''));

        if ($nama !== '') {
            return strtoupper(Str::slug($nama, ''));
        }

        throw new Exception("Kode department belum tersedia untuk Department ID: {$departmentId}");
    }
}

if (!function_exists('mapBranchCode')) {
    function mapBranchCode($cabang): string
    {
        $value = trim((string) $cabang);

        if ($value === '') {
            throw new Exception('Cabang tidak boleh kosong untuk dokumen.');
        }

        /*
        |--------------------------------------------------------------------------
        | Jika cabang berupa ID
        |--------------------------------------------------------------------------
        | Contoh:
        | purchase_requests.cabang = 2
        | Maka ambil kode cabang dari table public.cabang
        |--------------------------------------------------------------------------
        */
        if (is_numeric($value)) {
            $branch = DB::table('cabang')
                ->select('id', 'nama_cabang', 'inisial_cabang')
                ->where('id', (int) $value)
                ->first();

            if (!$branch) {
                throw new Exception("Cabang ID tidak ditemukan: {$value}");
            }

            $branchCode = trim((string) ($branch->inisial_cabang ?? ''));

            if ($branchCode === '') {
                throw new Exception("Inisial cabang belum tersedia untuk cabang ID: {$value}");
            }

            return strtoupper($branchCode);
        }

        /*
        |--------------------------------------------------------------------------
        | Jika cabang bukan ID
        |--------------------------------------------------------------------------
        | Cari berdasarkan nama_cabang atau inisial_cabang.
        | Ini untuk antisipasi kalau suatu saat cabang dikirim sebagai text.
        |--------------------------------------------------------------------------
        */
        $branch = DB::table('cabang')
            ->select('id', 'nama_cabang', 'inisial_cabang')
            ->whereRaw('UPPER(nama_cabang) = ?', [strtoupper($value)])
            ->orWhereRaw('UPPER(inisial_cabang) = ?', [strtoupper($value)])
            ->first();

        if (!$branch) {
            throw new Exception("Cabang tidak valid untuk dokumen: {$cabang}");
        }

        $branchCode = trim((string) ($branch->inisial_cabang ?? ''));

        if ($branchCode === '') {
            throw new Exception("Inisial cabang belum tersedia untuk cabang: {$cabang}");
        }

        return strtoupper($branchCode);
    }
}

if (!function_exists('getRomanMonth')) {
    function getRomanMonth(int $month): string
    {
        return [
            1  => 'I',
            2  => 'II',
            3  => 'III',
            4  => 'IV',
            5  => 'V',
            6  => 'VI',
            7  => 'VII',
            8  => 'VIII',
            9  => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII',
        ][$month] ?? '';
    }
}

function generateDocumentNumber(
    string $docCode,
    string $department,
    ?string $branch,
    int $month,
    int $year
): string {
    $branch = $branch !== null ? trim((string) $branch) : null;
    $branch = $branch !== '' ? $branch : null;

    $counter = DocumentCounter::firstOrCreate(
        [
            'doc_code'   => $docCode,
            'department' => $department,
            'branch'     => $branch,
            'year'       => $year,
        ],
        [
            'last_number' => 0,
        ]
    );

    $counter->increment('last_number');
    $counter->refresh();

    $number = str_pad((string) $counter->last_number, 4, '0', STR_PAD_LEFT);
    $roman = getRomanMonth($month);

    $segments = array_filter([
        $department,
        $branch,
        $roman,
        $year,
    ], function ($value) {
        return $value !== null && $value !== '';
    });

    return implode('/', $segments) . "/{$docCode}.{$number}";
}

if (!function_exists('generatePRNumber')) {
    function generatePRNumber($pr): string
    {
        if (!$pr->tanggal_pr || !$pr->id_department || !$pr->cabang) {
            throw new Exception('Data PR tidak lengkap untuk generate nomor PR');
        }

        $docCode = '01';

        $department = mapDepartmentCodeById((int) $pr->id_department);
        $branch     = mapBranchCode($pr->cabang);

        $month = (int) date('n', strtotime($pr->tanggal_pr));
        $year  = (int) date('Y', strtotime($pr->tanggal_pr));

        return generateDocumentNumber(
            $docCode,
            $department,
            $branch,
            $month,
            $year
        );
    }
}

if (!function_exists('generatePONumber')) {
    function generatePONumber($po): string
    {
        /*
        |--------------------------------------------------------------------------
        | Validasi data dokumen
        |--------------------------------------------------------------------------
        */
        if (empty($po->tanggal_po)) {
            throw new Exception(
                'Tanggal Purchase Order tidak ditemukan untuk generate nomor dokumen.'
            );
        }

        if (empty($po->id_department)) {
            throw new Exception(
                'Department Purchase Order tidak ditemukan untuk generate nomor dokumen.'
            );
        }

        if (empty($po->cabang)) {
            throw new Exception(
                'Cabang Purchase Order tidak ditemukan untuk generate nomor dokumen.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Kode dokumen Purchase Order
        |--------------------------------------------------------------------------
        */
        $docCode = '02';

        /*
        |--------------------------------------------------------------------------
        | Resolve department dan cabang dari snapshot PO
        |--------------------------------------------------------------------------
        */
        $department = mapDepartmentCodeById(
            (int) $po->id_department
        );

        $branch = mapBranchCode(
            (int) $po->cabang
        );

        if (empty($department)) {
            throw new Exception(
                'Kode department Purchase Order tidak ditemukan.'
            );
        }

        if (empty($branch)) {
            throw new Exception(
                'Kode cabang Purchase Order tidak ditemukan.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve periode dokumen
        |--------------------------------------------------------------------------
        */
        $timestamp = strtotime(
            (string) $po->tanggal_po
        );

        if ($timestamp === false) {
            throw new Exception(
                'Tanggal Purchase Order tidak valid untuk generate nomor dokumen.'
            );
        }

        $month = (int) date(
            'n',
            $timestamp
        );

        $year = (int) date(
            'Y',
            $timestamp
        );

        /*
        |--------------------------------------------------------------------------
        | Generate nomor final
        |--------------------------------------------------------------------------
        */
        return generateDocumentNumber(
            $docCode,
            $department,
            $branch,
            $month,
            $year
        );
    }
}

if (!function_exists('generateGRNumber')) {
    function generateGRNumber($gr): string
    {
        /*
        |--------------------------------------------------------------------------
        | Load Purchase Order sebagai fallback
        |--------------------------------------------------------------------------
        | Data utama tetap mengambil snapshot cabang dan department pada GR.
        |--------------------------------------------------------------------------
        */
        $gr->loadMissing([
            'purchaseOrder',
        ]);

        $po = $gr->purchaseOrder;

        if (!$po) {
            throw new Exception(
                'Purchase Order tidak ditemukan untuk generate nomor Goods Receipt.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve department
        |--------------------------------------------------------------------------
        | Prioritas:
        | 1. Snapshot department pada Goods Receipt
        | 2. Department dari Purchase Order
        |--------------------------------------------------------------------------
        */
        $departmentId = $gr->id_department
            ?? $po->id_department
            ?? null;

        if (!$departmentId) {
            throw new Exception(
                'Department Goods Receipt tidak ditemukan untuk generate nomor dokumen.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve cabang
        |--------------------------------------------------------------------------
        | Prioritas:
        | 1. Snapshot cabang pada Goods Receipt
        | 2. Cabang dari Purchase Order
        |--------------------------------------------------------------------------
        */
        $branchId = $gr->cabang
            ?? $po->cabang
            ?? null;

        if (!$branchId) {
            throw new Exception(
                'Cabang Goods Receipt tidak ditemukan untuk generate nomor dokumen.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Kode dokumen Goods Receipt
        |--------------------------------------------------------------------------
        */
        $docCode = '03';

        $department = mapDepartmentCodeById(
            (int) $departmentId
        );

        $branch = mapBranchCode(
            (int) $branchId
        );

        if (!$department) {
            throw new Exception(
                'Kode department Goods Receipt tidak ditemukan.'
            );
        }

        if (!$branch) {
            throw new Exception(
                'Kode cabang Goods Receipt tidak ditemukan.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Tanggal dokumen
        |--------------------------------------------------------------------------
        */
        $tanggalGr = $gr->tanggal_gr
            ?? $gr->posted_at
            ?? now();

        $timestamp = strtotime(
            (string) $tanggalGr
        );

        if ($timestamp === false) {
            throw new Exception(
                'Tanggal Goods Receipt tidak valid untuk generate nomor dokumen.'
            );
        }

        $month = (int) date(
            'n',
            $timestamp,
        );

        $year = (int) date(
            'Y',
            $timestamp,
        );

        return generateDocumentNumber(
            $docCode,
            $department,
            $branch,
            $month,
            $year,
        );
    }
}

if (!function_exists('generateGoodsReturnNumber')) {
    /**
     * Generate nomor dokumen Goods Return.
     */
    function generateGoodsReturnNumber(
        $goodsReturn,
    ): string {
        /*
        |--------------------------------------------------------------------------
        | Validasi data dokumen
        |--------------------------------------------------------------------------
        */
        if (
            empty($goodsReturn->tanggal_return)
            || empty($goodsReturn->id_department)
            || empty($goodsReturn->cabang)
        ) {
            throw new Exception(
                'Data Goods Return tidak lengkap untuk generate nomor dokumen.',
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Kode dokumen
        |--------------------------------------------------------------------------
        | 01 = Purchase Request
        | 02 = Purchase Order
        | 03 = Goods Receive
        | 04 = Goods Return
        |--------------------------------------------------------------------------
        */
        $docCode = '04';

        /*
        |--------------------------------------------------------------------------
        | Mapping department dan cabang
        |--------------------------------------------------------------------------
        | Menggunakan helper mapping yang sama dengan Goods Receive.
        |--------------------------------------------------------------------------
        */
        $department = mapDepartmentCodeById(
            (int) $goodsReturn->id_department,
        );

        $branch = mapBranchCode(
            (int) $goodsReturn->cabang,
        );

        $month = (int) date(
            'n',
            strtotime(
                (string) $goodsReturn->tanggal_return,
            ),
        );

        $year = (int) date(
            'Y',
            strtotime(
                (string) $goodsReturn->tanggal_return,
            ),
        );

        return generateDocumentNumber(
            $docCode,
            $department,
            $branch,
            $month,
            $year,
        );
    }
}
