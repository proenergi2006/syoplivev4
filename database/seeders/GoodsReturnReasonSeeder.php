<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GoodsReturnReasonSeeder extends Seeder
{
    /**
     * Jalankan seeder master alasan retur barang.
     */
    public function run(): void
    {
        $reasons = [
            [
                'code' => 'DAMAGED',
                'name' => 'Barang Rusak',
                'description' => 'Barang diterima dalam kondisi rusak, cacat, pecah, atau tidak dapat digunakan sebagaimana mestinya.',
                'is_active' => true,
            ],
            [
                'code' => 'WRONG_ITEM',
                'name' => 'Barang Tidak Sesuai',
                'description' => 'Barang yang diterima berbeda dengan barang yang tercantum pada Purchase Order.',
                'is_active' => true,
            ],
            [
                'code' => 'WRONG_SPECIFICATION',
                'name' => 'Spesifikasi Tidak Sesuai',
                'description' => 'Barang yang diterima memiliki spesifikasi, ukuran, tipe, warna, atau karakteristik yang tidak sesuai dengan pesanan.',
                'is_active' => true,
            ],
            [
                'code' => 'QUALITY_ISSUE',
                'name' => 'Kualitas Tidak Sesuai',
                'description' => 'Barang yang diterima tidak memenuhi standar kualitas atau mutu yang telah ditentukan.',
                'is_active' => true,
            ],
            [
                'code' => 'EXCESS_QUANTITY',
                'name' => 'Kelebihan Jumlah Barang',
                'description' => 'Jumlah barang yang diterima melebihi jumlah yang tercantum pada Purchase Order.',
                'is_active' => true,
            ],
            [
                'code' => 'EXPIRED',
                'name' => 'Barang Kedaluwarsa',
                'description' => 'Barang yang diterima telah melewati atau mendekati batas waktu kedaluwarsa yang diperbolehkan.',
                'is_active' => true,
            ],
            [
                'code' => 'INCOMPLETE',
                'name' => 'Barang atau Kelengkapan Tidak Lengkap',
                'description' => 'Barang diterima tanpa kelengkapan, komponen, aksesori, atau dokumen pendukung yang seharusnya disertakan.',
                'is_active' => true,
            ],
            [
                'code' => 'OTHER',
                'name' => 'Alasan Lainnya',
                'description' => 'Retur dilakukan karena alasan lain yang tidak termasuk dalam kategori yang tersedia.',
                'is_active' => true,
            ],
        ];

        foreach ($reasons as $reason) {
            $existingReason = DB::table('goods_return_reasons')
                ->where('code', $reason['code'])
                ->first();

            if ($existingReason) {
                DB::table('goods_return_reasons')
                    ->where('code', $reason['code'])
                    ->update([
                        'name' => $reason['name'],
                        'description' => $reason['description'],
                        'is_active' => $reason['is_active'],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('goods_return_reasons')
                ->insert([
                    'code' => $reason['code'],
                    'name' => $reason['name'],
                    'description' => $reason['description'],
                    'is_active' => $reason['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }
}
