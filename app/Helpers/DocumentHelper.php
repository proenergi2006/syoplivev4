<?php

use App\Models\DocumentCounter;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| MAP DEPARTMENT ID → KODE DOKUMEN
|--------------------------------------------------------------------------
| Berdasarkan:
| 1 IT
| 2 GA
| 3 LOGISTIK
| 4 HRD
| 5 ADMIN
*/

if (!function_exists('mapDepartmentCodeById')) {

    function mapDepartmentCodeById(int $departmentId): string
    {
        return match ($departmentId) {
            1 => 'IT',
            2 => 'GA',
            3 => 'LOG',
            4 => 'HRD',
            5 => 'ADM',
            6 => 'FIN',
            default => throw new Exception("Department ID tidak valid: {$departmentId}"),
        };
    }
}

/*
|--------------------------------------------------------------------------
| MAP CABANG → KODE DOKUMEN
|--------------------------------------------------------------------------
*/
if (!function_exists('mapBranchCode')) {

    function mapBranchCode(string $cabang): string
    {
        $map = [
            'JAKARTA'      => 'JKT',
            'SURABAYA'     => 'SBY',
            'SAMARINDA'    => 'SMD',
            'BANJARMASIN'  => 'BJM',
            'PALEMBANG'    => 'PLB',
            'SULAWESI'     => 'SLW',
            'HO'           => 'HO',
        ];

        $key = strtoupper(trim($cabang));

        return $map[$key] ?? throw new Exception("Cabang tidak valid untuk dokumen: {$cabang}");
    }
}


/*
|--------------------------------------------------------------------------
| CONVERT BULAN → ROMAWI
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| GENERATE DOCUMENT NUMBER (UMUM)
|--------------------------------------------------------------------------
| Format:
| 01.0001.LOG/HO/XII/2025
|
| Reset counter per:
| - doc_code
| - department
| - branch
| - year
*/
if (!function_exists('generateDocumentNumber')) {

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

        $number = str_pad($counter->last_number, 4, '0', STR_PAD_LEFT);
        $roman  = getRomanMonth($month);

        // FORMAT KHUSUS PO (tanpa branch)
        if ($branch === null) {
            return "{$docCode}.{$number}.{$department}/{$roman}/{$year}";
        }

        // FORMAT PR / DOKUMEN LAIN
        return "{$docCode}.{$number}.{$department}/{$branch}/{$roman}/{$year}";
    }
}

/*
|--------------------------------------------------------------------------
| GENERATE NOMOR PR RESMI
|--------------------------------------------------------------------------
| Dipanggil saat:
| - PR SUBMIT ke approval
|
| Contoh hasil:
| 01.0001.LOG/HO/XII/2025
*/
if (!function_exists('generatePRNumber')) {

    function generatePRNumber($pr): string
    {
        if (!$pr->tanggal_pr || !$pr->id_department || !$pr->cabang) {
            throw new Exception('Data PR tidak lengkap untuk generate nomor PR');
        }

        $docCode = '01'; // Kode PR

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

        $docCode = '02'; // PO

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
