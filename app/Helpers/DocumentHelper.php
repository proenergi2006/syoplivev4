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
        if (!$po->tanggal_po || !$po->id_department) {
            throw new Exception('Data PO tidak lengkap untuk generate nomor PO');
        }

        $docCode = '02';

        $department = mapDepartmentCodeById((int) $po->id_department);
        $branch     = null;

        $month = (int) date('n', strtotime($po->tanggal_po));
        $year  = (int) date('Y', strtotime($po->tanggal_po));

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
        $gr->loadMissing('purchaseOrder');

        $po = $gr->purchaseOrder;

        if (!$po) {
            throw new Exception('Purchase Order tidak ditemukan untuk generate nomor GR');
        }

        if (!$po->id_department) {
            throw new Exception('Department PO tidak ditemukan untuk generate nomor GR');
        }

        $docCode = '03';

        $department = mapDepartmentCodeById((int) $po->id_department);

        $branch = null;

        $tanggalGr = $gr->tanggal_gr ?? $gr->posted_at ?? now();

        $month = (int) date('n', strtotime($tanggalGr));
        $year = (int) date('Y', strtotime($tanggalGr));

        return generateDocumentNumber(
            $docCode,
            $department,
            $branch,
            $month,
            $year
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
