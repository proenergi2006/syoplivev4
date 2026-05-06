<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MasterDokumenPendukungSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('master_dokumen_pendukung')->insert([
            [
                'id' => 1,
                'nama_dokumen' => 'Surat Referensi Bank / Surat Pernyataan No. Rekening dari Direktur',
                'slug' => Str::slug('Surat Referensi Bank'),
                'deskripsi' => '(wajib)',
                'is_required' => true
            ],
            [
                'id' => 2,
                'nama_dokumen' => 'Surat Pernyataan Pembayaran PPN Bermeterai',
                'slug' => Str::slug('Surat Pernyataan PPN'),
                'deskripsi' => '(wajib jika PKP, format terlampir)',
                'is_required' => false
            ],
            [
                'id' => 3,
                'nama_dokumen' => 'SIUPAL',
                'slug' => Str::slug('SIUPAL'),
                'deskripsi' => '(Jika Perusahaan Pelayaran)',
                'is_required' => false
            ],
            [
                'id' => 4,
                'nama_dokumen' => 'SIUJK / SBUJK',
                'slug' => Str::slug('SIUJK SBUJK'),
                'deskripsi' => '(Jika Perusahaan Konstruksi)',
                'is_required' => false
            ],
            [
                'id' => 5,
                'nama_dokumen' => 'Fotokopi E-KTP',
                'slug' => Str::slug('E-KTP'),
                'deskripsi' => '(Jika Orang Pribadi / Perorangan)',
                'is_required' => false
            ],
            [
                'id' => 6,
                'nama_dokumen' => 'Lampiran Surat Pernyataan Non PKP Bermeterai',
                'slug' => Str::slug('Non PKP'),
                'deskripsi' => '(Jika Non PKP)',
                'is_required' => false
            ],
            [
                'id' => 7,
                'nama_dokumen' => 'Fotokopi SPPKP',
                'slug' => Str::slug('SPPKP'),
                'deskripsi' => null,
                'is_required' => false
            ],
            [
                'id' => 8,
                'nama_dokumen' => 'Lampiran Surat Pernyataan Non NPWP Bermeterai',
                'slug' => Str::slug('Non NPWP'),
                'deskripsi' => '(Jika tidak melampirkan NPWP)',
                'is_required' => false
            ],
            [
                'id' => 9,
                'nama_dokumen' => 'Fotokopi NPWP',
                'slug' => Str::slug('NPWP'),
                'deskripsi' => null,
                'is_required' => false
            ],
            [
                'id' => 10,
                'nama_dokumen' => 'Dokumen Legalitas',
                'slug' => Str::slug('Dokumen Legalitas'),
                'deskripsi' => '(Akta Pendirian, Akta Terbaru, SIUP, NIB, TDP, Dokumen Pendukung Lainnya)',
                'is_required' => false
            ],
        ]);
    }
}
