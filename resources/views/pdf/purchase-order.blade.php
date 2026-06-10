<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #111;
        }

        .header {
            width: 100%;
            border-bottom: 2px solid #111;
            margin-bottom: 12px;
            padding-bottom: 8px;
        }

        .company {
            font-size: 18px;
            font-weight: bold;
        }

        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            margin: 12px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .info td {
            padding: 3px 4px;
            vertical-align: top;
        }

        .items th,
        .items td {
            border: 1px solid #333;
            padding: 6px;
        }

        .items th {
            background: #efefef;
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .section-title {
            font-weight: bold;
            margin-top: 12px;
            margin-bottom: 4px;
        }

        .signature td {
            border: 1px solid #333;
            height: 95px;
            vertical-align: top;
            text-align: center;
            font-weight: bold;
            padding-top: 6px;
        }

        .signature-img {
            max-height: 55px;
            margin-top: 10px;
        }

        .terms {
            margin-top: 12px;
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td width="65%" valign="bottom">
                    <div class="document-title">
                        PURCHASE ORDER
                    </div>
                </td>

                <td width="35%" align="right">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                    >
                </td>
            </tr>
        </table>
    </div>
    <table class="po-top-info">
        <tr>
            <td width="50%" valign="top">
                <div class="box-title">Order To :</div>

                <div class="box-vendor-name">
                    {{ $po->vendor->nama_vendor ?? '-' }}
                </div>

                <div>{{ $po->vendor->alamat ?? '-' }}</div>

                @if (!empty($po->vendor->telepon))
                    <div>
                        Telp :
                        {{ $po->vendor->telepon }}
                    </div>
                @endif

                @if (!empty($po->vendor->email))
                    <div>
                        Email :
                        {{ $po->vendor->email }}
                    </div>
                @endif

                <hr>

                @if (!empty($po->vendor->nama_pic))
                    <div>
                        Up :
                        {{ $po->vendor->nama_pic }}

                        @if (!empty($po->vendor->jabatan_pic))
                            ({{ $po->vendor->jabatan_pic }})
                        @endif
                    </div>
                @endif

                <div>
                    Tlp.
                    {{ $po->vendor->telp_pic ?? $po->vendor->phone_vendor ?? '-' }}
                </div>


                @if (!empty($po->vendor->email_pic))
                    <div>
                        Email :
                        {{ $po->vendor->email_pic }}
                    </div>
                @endif
            </td>

            <td width="50%" valign="top">
                <table class="po-meta">
                    <tr>
                        <td width="35%">PO. Number</td>
                        <td width="5%">:</td>
                        <td width="60%" class="bold">{{ $po->nomor_po }}</td>
                    </tr>
                    <tr>
                        <td>PO. Date</td>
                        <td>:</td>
                        <td>{{ \Carbon\Carbon::parse($po->tanggal_po)->format('d-M-Y') }}</td>
                    </tr>
                    <tr>
                        <td>T.O.P</td>
                        <td>:</td>
                        <td>{{ $po->vendor->top ? $po->vendor->top . ' Hari' : $po->vendor->jenis_pembayaran }}</td>
                    </tr>
                </table>

                <div class="delivery-box">
                    <div class="box-vendor-name">PT PRO ENERGI</div>
                    <div>Gedung Graha Irama LT. 6 Unit G</div>
                    <div>Jl. HR Rasuna Said Blok X1, Kav. 1 - 2</div>
                    <div>Jakarta Selatan 12950</div>

                    <div class="bold mt-6">
                        Alamat Faktur Pajak : PT PRO ENERGI
                    </div>
                    <div>Graha Irama Lantai 6 Unit G</div>
                    <div>Jl. HR Rasuna Said Kav 1-2X</div>
                    <div>RT.006-RW.04 Kel. Kuningan Timur Kec Setiabudi Jak-sel</div>
                    <div class="bold mt-6">No NPWP 0025.2732.2806.2000</div>
                </div>
            </td>
        </tr>
    </table>
    <br>
    <table class="items">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="35%">Nama Item</th>
                <th width="10%">Qty</th>
                <th width="12%">Satuan</th>
                <th width="18%">Harga Unit</th>
                <th width="20%">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($po->items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ ucfirst($item->nama_item) }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="center">{{ $item->unit->nama ?? '-' }}</td>
                    <td class="right">Rp {{ number_format($item->harga_unit, 0, ',', '.') }}</td>
                    <td class="right bold">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @if (($po->vendor->status_pkp ?? '') === 'PKP')
                <tr>
                    <td colspan="5" class="right bold">Subtotal</td>
                    <td class="right bold">Rp {{ number_format($po->items->sum('subtotal'), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="right bold">DPP</td>
                    <td class="right bold">Rp {{ number_format($po->dpp, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="right bold">PPN</td>
                    <td class="right bold">Rp {{ number_format($po->ppn, 0, ',', '.') }}</td>
                </tr>
            @endif

            <tr>
                <td colspan="5" class="right grand-total-label">Grand Total</td>
                <td class="right grand-total-value">
                    Rp {{ number_format($po->total_nilai, 0, ',', '.') }}
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="terbilang-box">
        <div class="bold">Terbilang:</div>
        <div class="terbilang-text">
            {{ $terbilang }}
        </div>
    </div>

    <div class="terms">
        <div class="bold">Syarat dan ketentuan</div>
        <div>1. Harap mencantumkan nomor PO ini di invoice atau kwitansi.</div>
        <div>2. Harap melampirkan PO ini saat penagihan beserta nomor rekening pembayarannya.</div>
        <div>3. Syarat pembayaran: {{ $po->vendor->top ? $po->vendor->top . ' Hari' : $po->vendor->jenis_pembayaran }}</div>
        <div>
            4. PO ini mengacu pada PR nomor:
            {{ $po->purchaseRequests->pluck('nomor_pr')->implode(', ') }}
        </div>
    </div>

    <br>

    @php
        /*
        |--------------------------------------------------------------------------
        | Dynamic Signature Box
        |--------------------------------------------------------------------------
        | - Jika approval hanya 1, tampilan tetap seperti awal:
        |   Dibuat oleh | Menyetujui
        |
        | - Jika approval lebih dari 1, header "Menyetujui" dibuat merge/colspan.
        |--------------------------------------------------------------------------
        */

        $approvalSignatures = $po->approvals
            ->sortBy('step_order')
            ->values();

        $approvalCount = $approvalSignatures->count();

        $requesterSignature = $po->requester_signature_path
            ? public_path('storage/' . $po->requester_signature_path)
            : null;

        $requesterSignatureExists = $requesterSignature && file_exists($requesterSignature);

        $getApprovalSignaturePath = function ($approval) {
            return $approval && $approval->signature_path
                ? public_path('storage/' . $approval->signature_path)
                : null;
        };

        $getApprovalSignatureExists = function ($approval) use ($getApprovalSignaturePath) {
            $path = $getApprovalSignaturePath($approval);

            return $path && file_exists($path);
        };

        $getApprovalPlaceholder = function ($approval) {
            $status = strtoupper((string) ($approval->status ?? ''));

            return match ($status) {
                'WAITING' => 'Menunggu approval',
                'PENDING' => 'Belum diproses',
                'REJECTED' => 'Ditolak',
                'CANCELLED' => 'Dibatalkan',
                default => '-',
            };
        };
    @endphp

    <div class="signature-wrapper">
        @if ($approvalCount <= 1)
            @php
                $firstApproval = $approvalSignatures->first();

                $approverSignature = $getApprovalSignaturePath($firstApproval);
                $approverSignatureExists = $getApprovalSignatureExists($firstApproval);
            @endphp

            <table class="signature">
                <tr>
                    <td width="50%" class="signature-cell">
                        <div class="signature-title">Dibuat oleh</div>

                        <div class="signature-area">
                            @if ($requesterSignatureExists)
                                <img
                                    src="{{ $requesterSignature }}"
                                    class="signature-img"
                                >
                            @endif
                        </div>

                        <div class="signature-name">
                            {{ optional($po->requesterSignedBy)->name ?? '-' }}
                        </div>

                        <div class="signature-date">
                            {{ $po->requester_signed_at ? \Carbon\Carbon::parse($po->requester_signed_at)->format('d/m/Y H:i') : '-' }}
                        </div>
                    </td>

                    <td width="50%" class="signature-cell">
                        <div class="signature-title">Menyetujui</div>

                        @if ($firstApproval && strtoupper((string) $firstApproval->status) === 'APPROVED')
                            <div class="signature-area">
                                @if ($approverSignatureExists)
                                    <img
                                        src="{{ $approverSignature }}"
                                        class="signature-img"
                                    >
                                @endif
                            </div>

                            <div class="signature-name">
                                {{ $firstApproval->approver_name_snapshot ?: ($firstApproval->label ?: '-') }}
                            </div>

                            <div class="signature-date">
                                {{ $firstApproval->approved_at ? \Carbon\Carbon::parse($firstApproval->approved_at)->format('d/m/Y H:i') : '-' }}
                            </div>
                        @else
                            <div class="signature-area signature-placeholder">
                                {{ $firstApproval ? $getApprovalPlaceholder($firstApproval) : 'Menunggu approval' }}
                            </div>

                            <div class="signature-name">
                                {{ $firstApproval->label ?? '-' }}
                            </div>

                            <div class="signature-date">
                                -
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        @else
            @php
                $totalColumns = 1 + $approvalCount;
                $columnWidth = 100 / $totalColumns;
            @endphp

            <table class="signature signature-merged">
                <tr class="signature-header-row">
                    <td width="{{ $columnWidth }}%" class="signature-header-cell">
                        Dibuat oleh
                    </td>

                    <td width="{{ $columnWidth * $approvalCount }}%" colspan="{{ $approvalCount }}" class="signature-header-cell">
                        Menyetujui
                    </td>
                </tr>

                <tr>
                    <td width="{{ $columnWidth }}%" class="signature-cell">
                        <div class="signature-area">
                            @if ($requesterSignatureExists)
                                <img
                                    src="{{ $requesterSignature }}"
                                    class="signature-img"
                                >
                            @endif
                        </div>

                        <div class="signature-name">
                            {{ optional($po->requesterSignedBy)->name ?? '-' }}
                        </div>

                        <div class="signature-date">
                            {{ $po->requester_signed_at ? \Carbon\Carbon::parse($po->requester_signed_at)->format('d/m/Y H:i') : '-' }}
                        </div>
                    </td>

                    @foreach ($approvalSignatures as $approval)
                        @php
                            $approvalSignature = $getApprovalSignaturePath($approval);
                            $approvalSignatureExists = $getApprovalSignatureExists($approval);
                            $approvalStatus = strtoupper((string) $approval->status);
                        @endphp

                        <td width="{{ $columnWidth }}%" class="signature-cell">
                            @if ($approvalStatus === 'APPROVED')
                                <div class="signature-area">
                                    @if ($approvalSignatureExists)
                                        <img
                                            src="{{ $approvalSignature }}"
                                            class="signature-img"
                                        >
                                    @endif
                                </div>

                                <div class="signature-name">
                                    {{ $approval->approver_name_snapshot ?: ($approval->label ?: '-') }}
                                </div>

                                <div class="signature-date">
                                    {{ $approval->approved_at ? \Carbon\Carbon::parse($approval->approved_at)->format('d/m/Y H:i') : '-' }}
                                </div>
                            @else
                                <div class="signature-area signature-placeholder">
                                    {{ $getApprovalPlaceholder($approval) }}
                                </div>

                                <div class="signature-name">
                                    {{ $approval->label ?? '-' }}
                                </div>

                                <div class="signature-date">
                                    -
                                </div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
</body>
</html>
<style lang="scss" scoped>
    thead {
    display: table-header-group;
}

tfoot {
    display: table-row-group;
}

.items tr {
    page-break-inside: avoid;
}

.signature-wrapper {
    page-break-inside: avoid;
    margin-top: 14px;
}

.signature-header-cell {
    border: 1px solid #333;
    text-align: center;
    vertical-align: middle;
    font-weight: bold;
    padding: 5px 6px;
    font-size: 11px;
    height: 22px !important;
    line-height: 14px;
}

.signature-merged .signature-header-cell {
    height: 22px !important;
    padding-top: 5px !important;
    padding-bottom: 5px !important;
}

.signature-merged .signature-cell {
    height: 95px;
    padding: 8px 6px;
    vertical-align: top;
}

.signature-merged .signature-img {
    max-width: 110px;
    max-height: 52px;
}

.signature-cell {
    text-align: center;
    vertical-align: top;
    height: 135px;
    padding: 8px 6px;
    word-wrap: break-word;
    word-break: break-word;
}

.signature-img {
    max-width: 115px;
    max-height: 55px;
    object-fit: contain;
    display: inline-block;
    vertical-align: middle;
}

.signature-title {
    font-weight: bold;
    font-size: 11px;
    margin-bottom: 6px;
}

.signature-area {
    height: 58px;
    line-height: 58px;
    text-align: center;
}

.signature-name {
    font-weight: bold;
    font-size: 11px;
    margin-top: 4px;
}

.signature-date {
    font-size: 10px;
    margin-top: 3px;
}

.signature-placeholder {
    color: #999;
    font-size: 10px;
}

    .po-top-info {
    width: 100%;
    margin-top: 10px;
    margin-bottom: 14px;
    border-collapse: separate;
    border-spacing: 10px 0;
}

.terms {
    page-break-inside: avoid;
}

.items tfoot {
    page-break-inside: avoid;
}

.items td {
    word-wrap: break-word;
    word-break: break-word;
}

@page {
    margin: 24px;
}

.page-number {
    position: fixed;
    bottom: -10px;
    right: 0;
    font-size: 10px;
    color: #666;
}

.po-top-info > tbody > tr > td:first-child {
    border: 1px solid #111;
    border-radius: 18px;
    padding: 12px 14px;
    line-height: 1.55;
}

.po-top-info > tbody > tr > td:last-child {
    padding-left: 8px;
}

.box-title {
    font-weight: bold;
    margin-bottom: 4px;
}

.box-vendor-name {
    font-weight: bold;
    font-size: 13px;
    margin-bottom: 3px;
}

.po-meta {
    width: 100%;
    margin-bottom: 8px;
}

.po-meta td {
    padding: 3px 4px;
    vertical-align: top;
    font-size: 11px;
}

.delivery-box {
    border: 1px solid #111;
    border-radius: 18px;
    padding: 12px 14px;
    line-height: 1.55;
    min-height: 105px;
}

.mt-6 {
    margin-top: 6px;
}

.header {
    width: 100%;
    border-bottom: 2px solid #111;
    margin-bottom: 18px;
    padding-bottom: 10px;
}

.document-title {
    font-size: 24px;
    font-weight: bold;
    letter-spacing: 0.5px;
    margin-top: 6px;
}

.document-subtitle {
    margin-top: 6px;
    font-size: 11px;
    color: #555;
}

.company-logo {
    width: 150px;
    max-height: 80px;
    object-fit: contain;
}

    .items tfoot td {
    border: 1px solid #333;
    padding: 6px;
    background: #fafafa;
}

.grand-total-label {
    font-weight: bold;
    font-size: 12px;
    background: #eaeaea !important;
}

.grand-total-value {
    font-weight: bold;
    font-size: 12px;
    background: #eaeaea !important;
}

.terbilang-box {
    margin-top: 10px;
    border: 1px solid #333;
    padding: 8px 10px;
    min-height: 34px;
}

.terbilang-text {
    margin-top: 3px;
    font-style: italic;
    line-height: 1.5;
}
</style>