<?php

use App\Models\DocumentCounter;
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
    function mapBranchCode(string $cabang): string
    {
        $map = [
            'JAKARTA'     => 'JKT',
            'SURABAYA'    => 'SBY',
            'SAMARINDA'   => 'SMD',
            'BANJARMASIN' => 'BJM',
            'PALEMBANG'   => 'PLB',
            'SULAWESI'    => 'SLW',
            'HO'          => 'HO',
        ];

        $key = strtoupper(trim($cabang));

        return $map[$key] ?? throw new Exception("Cabang tidak valid untuk dokumen: {$cabang}");
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

    $counter = DocumentCounter::firstOrCreate(
        [
            'doc_code'   => $docCode,
            'department' => $department,
            'branch'     => $branch,
            'year'       => $year,
        ],
        [
            'last_number' => 0
        ]
    );

    $counter->increment('last_number');
    $counter->refresh();

    $number = str_pad($counter->last_number, 4, '0', STR_PAD_LEFT);
    $roman  = getRomanMonth($month);

    /*
    |--------------------------------------------------------------------------
    | FORMAT PO
    |--------------------------------------------------------------------------
    | GA/V/2026/02.0001
    |--------------------------------------------------------------------------
    */
    if ($docCode === '02') {
        return "{$department}/{$roman}/{$year}/{$docCode}.{$number}";
    }

    /*
    |--------------------------------------------------------------------------
    | FORMAT PR
    |--------------------------------------------------------------------------
    | GA/HO/V/2026/01.0001
    |--------------------------------------------------------------------------
    */
    return "{$department}/{$branch}/{$roman}/{$year}/{$docCode}.{$number}";
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
