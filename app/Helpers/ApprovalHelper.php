<?php

namespace App\Helpers;

class ApprovalHelper
{
    public static function mapDepartmentById(int $departmentId): string
    {
        return match ($departmentId) {
            1, 2, 4, 5 => 'ADMIN/GA/HRD/IT',
            3          => 'LOGISTIC',
            default    => throw new \Exception('Department tidak valid'),
        };
    }

    public static function mapLocation(string $cabang): string
    {
        return strtoupper($cabang) === 'HO' ? 'HO' : 'CABANG';
    }
}
