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
                    <td>{{ Strtoupper($item->nama_item) }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="center">{{ $item->satuan }}</td>
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

    <div class="signature-wrapper">
        <table class="signature">
            <tr>
                <td width="50%">
                    Dibuat oleh
                    <br>
                    @if ($po->requester_signature_path)
                        <img
                            src="{{ public_path('storage/' . $po->requester_signature_path) }}"
                            class="signature-img"
                        >
                    @endif
                    <br>
                    {{ optional($po->requesterSignedBy)->name ?? '-' }}
                    <br>
                    {{ $po->requester_signed_at ? \Carbon\Carbon::parse($po->requester_signed_at)->format('d/m/Y H:i') : '-' }}
                </td>

                <td width="50%">
                    Menyetujui
                    <br><br><br><br>
                    ______________________
                </td>
            </tr>
        </table>
    </div>

    <div class="page-number">
        Page <span class="pagenum"></span>
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