<?php

namespace App\Exports;

use App\Models\PurchaseRequest;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PurchaseRequestExport implements FromArray, WithHeadings, WithEvents
{
    protected $data;
    protected $mergeInstructions = [];

    public function __construct($requests)
    {
        $this->data = $requests;
    }

    public function headings(): array
    {
        return [
            "Nomor PR",
            "Tanggal",
            "Cabang",
            "Kategori",
            "Vendor",
            "Item",
            "Qty",
            "Harga Unit",
            "Subtotal",
            "DPP",
            "PPN",
            "Grand Total"
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // Row 1 = heading

        foreach ($this->data as $pr) {
            $startPR = $rowIndex;

            foreach ($pr->vendors as $vendor) {
                $vendorName = $vendor->vendor->nama_vendor;
                $statusPKP  = $vendor->vendor->status_pkp;

                $startVendor = $rowIndex;

                foreach ($vendor->items as $item) {

                    $subtotal = $item->qty * $item->harga_unit;

                    if ($statusPKP === "PKP") {
                        $dpp = round(($subtotal * 100) / 111);
                        $ppn = round($dpp * 0.11);
                        $grand = $subtotal + $ppn;
                    } else {
                        $dpp = null;
                        $ppn = null;
                        $grand = $subtotal;
                    }

                    $rows[] = [
                        $pr->nomor_pr,
                        $pr->tanggal_pr,
                        $pr->cabang,
                        $pr->kategori,
                        $vendorName . " ($statusPKP)",
                        $item->nama_item,
                        $item->qty,
                        $item->harga_unit,
                        $subtotal,
                        $dpp,
                        $ppn,
                        $grand,
                    ];

                    $rowIndex++;
                }

                // simpan merge vendor
                $endVendor = $rowIndex - 1;
                if ($endVendor > $startVendor) {
                    $this->mergeInstructions[] = ["col" => 5, "start" => $startVendor, "end" => $endVendor];
                }
            }

            // simpan merge PR
            $endPR = $rowIndex - 1;
            if ($endPR > $startPR) {
                foreach ([1, 2, 3, 4] as $col) {
                    $this->mergeInstructions[] = ["col" => $col, "start" => $startPR, "end" => $endPR];
                }
            }
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                foreach ($this->mergeInstructions as $merge) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($merge["col"]);
                    $cell1 = $colLetter . $merge["start"];
                    $cell2 = $colLetter . $merge["end"];

                    $event->sheet->getDelegate()->mergeCells("$cell1:$cell2");
                    $event->sheet->getStyle("$cell1:$cell2")->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // Style header
                $event->sheet->getStyle("A1:L1")->getAlignment()->setHorizontal("center")->setVertical("center");
                $event->sheet->getStyle("A1:L1")->getFont()->setBold(true);
            }
        ];
    }
}
