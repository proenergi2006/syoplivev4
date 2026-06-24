@php
    $tanggalPr = $pr->tanggal_pr
        ? \Carbon\Carbon::parse($pr->tanggal_pr)->format('d/m/Y')
        : '-';

    $cabang = $pr->cabangData
        ? trim(
            ($pr->cabangData->inisial_cabang ?? '-')
            . ' - '
            . ($pr->cabangData->nama_cabang ?? '-')
        )
        : '-';

    $department = $pr->departmentData
        ? trim(
            ($pr->departmentData->kode ?? '-')
            . ' - '
            . ($pr->departmentData->nama ?? '-')
        )
        : '-';
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <title>
        Purchase Requisition {{ $pr->nomor_pr }}
    </title>

    <style>
        @page {
            margin: 22px 26px 28px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #243247;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.42;
            background: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: bold;
        }

        .nowrap {
            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | Header Dokumen
        |--------------------------------------------------------------------------
        */
        .header {
            margin-bottom: 14px;
            padding: 12px 0 11px;
            border-top: 5px solid #1f4e78;
            border-bottom: 1px solid #cdd7e3;
        }

        .header-table td {
            vertical-align: middle;
        }

        .company-logo {
            width: 140px;
            max-height: 72px;
        }

        .document-title {
            margin: 0;
            color: #17365d;
            font-size: 24px;
            font-weight: bold;
            line-height: 1.1;
            letter-spacing: 1px;
            text-align: right;
            text-transform: uppercase;
        }

        .document-number {
            margin-top: 7px;
            color: #1f4e78;
            font-size: 11px;
            font-weight: bold;
            text-align: right;
        }

        .document-line {
            width: 62px;
            height: 4px;
            margin-top: 7px;
            margin-left: auto;
            background: #ef9d2d;
        }

        /*
        |--------------------------------------------------------------------------
        | Informasi PR
        |--------------------------------------------------------------------------
        */
        .info-card {
            margin-bottom: 14px;
            border: 1px solid #c9d4e2;
            background: #f8fafc;
        }

        .info-card-title {
            padding: 7px 10px;
            color: #ffffff;
            background: #1f4e78;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .info-table td {
            padding: 7px 9px;
            border-bottom: 1px solid #dce3ec;
            vertical-align: top;
        }

        .info-table tr:last-child td {
            border-bottom: 0;
        }

        .info-label {
            width: 16%;
            color: #6c7889;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .info-separator {
            width: 2%;
            color: #8995a5;
            text-align: center;
        }

        .info-value {
            width: 32%;
            color: #243247;
            font-size: 10px;
            font-weight: 600;
        }

        /*
        |--------------------------------------------------------------------------
        | Tabel Item
        |--------------------------------------------------------------------------
        */
        .item-table {
            table-layout: fixed;
            margin-top: 2px;
        }

        .item-table thead {
            display: table-header-group;
        }

        .item-table tr {
            page-break-inside: avoid;
        }

        .item-table th {
            padding: 8px 6px;
            border: 1px solid #17365d;
            color: #ffffff;
            background: #1f4e78;
            font-size: 8.5px;
            font-weight: bold;
            letter-spacing: 0.2px;
            text-align: center;
            vertical-align: middle;
        }

        .item-table td {
            padding: 7px 6px;
            border: 1px solid #c8d1dc;
            vertical-align: top;
            word-wrap: break-word;
            word-break: break-word;
        }

        .item-table tbody tr:nth-child(even) td {
            background: #f7f9fc;
        }

        .item-table tbody td:first-child {
            color: #6c7889;
        }

        /*
        |--------------------------------------------------------------------------
        | Ringkasan Nilai
        |--------------------------------------------------------------------------
        */
        .summary-wrapper {
            margin-top: 10px;
            page-break-inside: avoid;
        }

        .summary-table {
            width: 48%;
            margin-left: auto;
        }

        .summary-table td {
            padding: 7px 9px;
            border: 1px solid #17365d;
        }

        .summary-label {
            width: 45%;
            color: #17365d;
            background: #eaf1f8;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .summary-value {
            color: #17365d;
            background: #f8fafc;
            font-size: 12px;
            font-weight: bold;
        }

        /*
        |--------------------------------------------------------------------------
        | Terbilang dan Catatan
        |--------------------------------------------------------------------------
        */
        .additional-info-table {
            margin-top: 11px;
            border-collapse: separate;
            border-spacing: 0;
            page-break-inside: avoid;
        }

        .additional-info-table > tbody > tr > td {
            width: 50%;
            vertical-align: top;
        }

        .additional-left {
            padding-right: 5px;
        }

        .additional-right {
            padding-left: 5px;
        }

        .detail-box {
            min-height: 74px;
            padding: 9px 11px;
            border: 1px solid #d7dee8;
            background: #fbfcfe;
        }

        .terbilang-box {
            border-left: 5px solid #ef9d2d;
            background: #fff8ed;
        }

        .box-title {
            margin-bottom: 5px;
            color: #17365d;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .terbilang-box .box-title {
            color: #9a5a00;
        }

        .box-content {
            color: #38485d;
            line-height: 1.55;
        }

        .terbilang-text {
            color: #4a3b28;
            font-style: italic;
        }

        /*
        |--------------------------------------------------------------------------
        | Persetujuan
        |--------------------------------------------------------------------------
        */
        .approval-section {
            margin-top: 16px;
            page-break-inside: avoid;
        }

        .approval-title {
            padding: 7px 10px;
            color: #ffffff;
            background: #1f4e78;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .approval-table {
            width: 100%;
            margin-bottom: 9px;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
        }

        .approval-table td {
            height: 112px;
            padding: 0;
            border: 1px solid #bfc9d6;
            background: #ffffff;
            text-align: center;
            vertical-align: top;
            word-wrap: break-word;
            word-break: break-word;
        }

        .approval-position {
            min-height: 28px;
            padding: 6px 5px;
            color: #17365d;
            background: #eaf1f8;
            font-size: 8.5px;
            font-weight: bold;
            line-height: 1.25;
            text-transform: uppercase;
        }

        .signature-area {
            height: 49px;
            line-height: 49px;
            margin: 3px 0 1px;
            text-align: center;
        }

        .signature-image {
            display: inline-block;
            max-width: 105px;
            max-height: 45px;
            vertical-align: middle;
        }

        .approval-name {
            padding: 0 5px;
            color: #243247;
            font-size: 9px;
            font-weight: bold;
            line-height: 1.25;
        }

        .approval-date {
            margin-top: 3px;
            padding: 0 5px 6px;
            color: #798698;
            font-size: 8px;
        }

        /*
        |--------------------------------------------------------------------------
        | Footer
        |--------------------------------------------------------------------------
        */
        .footer {
            position: fixed;
            right: 0;
            bottom: -16px;
            left: 0;
            color: #7d8999;
            font-size: 8px;
            text-align: center;
        }
    </style>
</head>

<body>
    {{-- ============================================================
         HEADER
         ============================================================ --}}
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 42%;">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                        alt="PT Pro Energi"
                    >
                </td>

                <td style="width: 58%;">
                    <div class="document-title">
                        Purchase Requisition
                    </div>

                    <div class="document-number">
                        {{ $pr->nomor_pr ?? '-' }}
                    </div>

                    {{-- <div class="document-line"></div> --}}
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         INFORMASI PURCHASE REQUISITION
         ============================================================ --}}
    <div class="info-card">
        <div class="info-card-title">
            Informasi Purchase Requisition
        </div>

        <table class="info-table">
            <tr>
                <td class="info-label">
                    Nomor PR
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $pr->nomor_pr ?? '-' }}
                </td>

                <td class="info-label">
                    Tanggal
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $tanggalPr }}
                </td>
            </tr>

            <tr>
                <td class="info-label">
                    Cabang
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $cabang }}
                </td>

                <td class="info-label">
                    Department
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $department }}
                </td>
            </tr>

            <tr>
                <td class="info-label">
                    Kategori
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $pr->kategori ?? '-' }}
                </td>

                <td class="info-label">
                    Tipe
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $pr->pr_type ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="info-label">
                    Dibuat Oleh
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $pr->creator?->name ?? '-' }}
                </td>

                <td class="info-label">
                    Disubmit Oleh
                </td>

                <td class="info-separator">
                    :
                </td>

                <td class="info-value">
                    {{ $pr->submitter?->name ?? '-' }}
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         DAFTAR ITEM
         ============================================================ --}}
    <table class="item-table">
        <thead>
            <tr>
                <th style="width: 4%;">
                    No
                </th>

                <th style="width: 20%;">
                    Nama Item
                </th>

                <th style="width: 25%;">
                    Keterangan
                </th>

                <th style="width: 8%;">
                    Qty
                </th>

                <th style="width: 9%;">
                    Satuan
                </th>

                <th style="width: 14%;">
                    Estimasi Harga
                </th>

                <th style="width: 20%;">
                    Subtotal
                </th>
            </tr>
        </thead>

        <tbody>
            @forelse ($pr->items as $index => $item)
                @php
                    $qty = (float) ($item->qty ?? 0);
                    $hargaUnit = (float) ($item->harga_unit ?? 0);

                    $subtotal = (float) (
                        $item->subtotal
                        ?? ($qty * $hargaUnit)
                    );
                @endphp

                <tr>
                    <td class="text-center">
                        {{ $index + 1 }}
                    </td>

                    <td>
                        {{ ucwords($item->nama_item) ?? '-' }}
                    </td>

                    <td>
                        {{ $item->keterangan ?? '-' }}
                    </td>

                    <td class="text-center">
                        {{ rtrim(
                            rtrim(
                                number_format(
                                    $item->qty,
                                    2,
                                    ',',
                                    '.'
                                ),
                                '0'
                            ),
                            ','
                        ) }}
                    </td>

                    <td class="text-center">
                        {{ $item->unit?->nama ?? '-' }}
                    </td>

                    <td class="text-right nowrap">
                        Rp {{ number_format(
                            $hargaUnit,
                            0,
                            ',',
                            '.'
                        ) }}
                    </td>

                    <td class="text-right text-bold nowrap">
                        Rp {{ number_format(
                            $subtotal,
                            0,
                            ',',
                            '.'
                        ) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="7"
                        class="text-center"
                    >
                        Tidak ada item.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ============================================================
         TOTAL ESTIMASI
         ============================================================ --}}
    <div class="summary-wrapper">
        <table class="summary-table">
            <tr>
                <td class="text-center summary-label">
                    Total Estimasi
                </td>

                <td class="text-right summary-value nowrap">
                    Rp {{ number_format(
                        $totalAmount,
                        0,
                        ',',
                        '.'
                    ) }}
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         TERBILANG DAN CATATAN
         ============================================================ --}}
    <table class="additional-info-table">
        <tr>
            <td class="additional-left">
                <div class="detail-box terbilang-box">
                    <div class="box-title">
                        Terbilang
                    </div>

                    <div class="box-content terbilang-text">
                        {{ $terbilang }}
                    </div>
                </div>
            </td>

            <td class="additional-right">
                <div class="detail-box">
                    <div class="box-title">
                        Catatan
                    </div>

                    <div class="box-content">
                        {{ $pr->notes ?: '-' }}
                    </div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ============================================================
         PERSETUJUAN
         ============================================================ --}}
    <div class="approval-section">
        <div class="approval-title">
            Persetujuan
        </div>

        @foreach ($signers->chunk(3) as $signerRow)
            @php
                $columnCount = max(
                    $signerRow->count(),
                    1
                );

                $columnWidth = 100 / $columnCount;
            @endphp

            <table class="approval-table">
                <tr>
                    @foreach ($signerRow as $signer)
                        @php
                            $signaturePath = $signer->signature_path
                                ? storage_path(
                                    'app/public/'
                                    . ltrim(
                                        $signer->signature_path,
                                        '/'
                                    )
                                )
                                : null;
                        @endphp

                        <td style="width: {{ $columnWidth }}%;">
                            <div class="approval-position">
                                {{ $signer->label ?? '-' }}
                            </div>

                            <div class="signature-area">
                                @if (
                                    $signaturePath
                                    && file_exists($signaturePath)
                                )
                                    <img
                                        src="{{ $signaturePath }}"
                                        class="signature-image"
                                        alt="Signature"
                                    >
                                @else
                                    <div style="height: 45px;"></div>
                                @endif
                            </div>

                            <div class="approval-name">
                                {{ $signer->name ?? '-' }}
                            </div>

                            <div class="approval-date">
                                @if (!empty($signer->signed_at))
                                    {{ \Carbon\Carbon::parse(
                                        $signer->signed_at
                                    )->format('d/m/Y H:i') }}
                                @else
                                    -
                                @endif
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endforeach
    </div>

    <div class="footer">
        Dokumen ini dicetak otomatis melalui sistem SYOP v4.
    </div>
</body>
</html>
