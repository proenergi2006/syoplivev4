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
            margin: 22px 24px 28px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #222;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: middle;
        }

        .company-logo {
            width: 150px;
            max-height: 80px;
            object-fit: contain;
        }

        .document-title {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            text-align: right;
            text-transform: uppercase;
        }

        .document-number {
            margin-top: 4px;
            font-size: 10px;
            text-align: right;
        }

        .separator {
            margin: 12px 0;
            border-top: 2px solid #333;
        }

        .info-table {
            margin-bottom: 14px;
        }

        .info-table td {
            padding: 4px 5px;
            vertical-align: top;
        }

        .info-label {
            width: 18%;
            color: #555;
            font-weight: bold;
        }

        .info-value {
            width: 32%;
        }

        .item-table {
            margin-top: 8px;
        }

        .item-table th,
        .item-table td {
            padding: 6px 5px;
            border: 1px solid #666;
            vertical-align: top;
        }

        .item-table th {
            background: #e8eaed;
            font-size: 9px;
            font-weight: bold;
            text-align: center;
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

        .summary-table {
            width: 50%;
            margin-top: 10px;
            margin-left: auto;
        }

        .summary-table td {
            padding: 5px 7px;
            border: 1px solid #777;
        }

        .summary-label {
            width: 45%;
            background: #f3f4f6;
            font-weight: bold;
        }

        .terbilang-box,
        .notes-box {
            margin-top: 12px;
            padding: 8px 10px;
            border: 1px solid #777;
        }

        .box-title {
            margin-bottom: 4px;
            color: #555;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .approval-section {
            margin-top: 22px;
        }

        .approval-title {
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: bold;
        }

        .approval-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
        }

        .approval-table td {
            height: 115px;
            padding: 7px;
            border: 1px solid #777;
            text-align: center;
            vertical-align: top;
        }

        .approval-position {
            min-height: 28px;
            font-size: 9px;
            font-weight: bold;
        }

        .signature-area {
            height: 48px;
            margin: 4px 0;
            text-align: center;
        }

        .signature-image {
            max-width: 110px;
            max-height: 46px;
        }

        .approval-name {
            font-size: 9px;
            font-weight: bold;
        }

        .approval-date {
            margin-top: 3px;
            color: #555;
            font-size: 8px;
        }

        .footer {
            position: fixed;
            right: 0;
            bottom: -15px;
            left: 0;
            color: #777;
            font-size: 8px;
            text-align: center;
        }
    </style>
</head>

<body>
    <table class="header-table">
        <tr>
            <td style="width: 45%;">
                <img
                    src="{{ public_path('logo-proenergi.png') }}"
                    class="company-logo"
                >
            </td>

            <td style="width: 55%;">
                <div class="document-title">
                    Purchase Requisition
                </div>

                <div class="document-number">
                    {{ $pr->nomor_pr ?? '-' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <table class="info-table">
        <tr>
            <td class="info-label">
                Nomor PR
            </td>

            <td class="info-value">
                : {{ $pr->nomor_pr ?? '-' }}
            </td>

            <td class="info-label">
                Tanggal
            </td>

            <td class="info-value">
                : {{ $tanggalPr }}
            </td>
        </tr>

        <tr>
            <td class="info-label">
                Cabang
            </td>

            <td class="info-value">
                : {{ $cabang }}
            </td>

            <td class="info-label">
                Department
            </td>

            <td class="info-value">
                : {{ $department }}
            </td>
        </tr>

        <tr>
            <td class="info-label">
                Kategori
            </td>

            <td class="info-value">
                : {{ $pr->kategori ?? '-' }}
            </td>

            <td class="info-label">
                Tipe
            </td>

            <td class="info-value">
                : {{ $pr->pr_type ?? '-' }}
            </td>
        </tr>

        <tr>
            <td class="info-label">
                Dibuat Oleh
            </td>

            <td class="info-value">
                : {{ $pr->creator?->name ?? '-' }}
            </td>

            <td class="info-label">
                Disubmit Oleh
            </td>

            <td class="info-value">
                : {{ $pr->submitter?->name ?? '-' }}
            </td>
        </tr>
    </table>

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

                <th style="width: 15%;">
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
                        {{ $item->nama_item ?? '-' }}
                    </td>

                    <td>
                        {{ $item->keterangan ?? '-' }}
                    </td>

                    <td class="text-right">
                        {{ number_format($qty, 2, ',', '.') }}
                    </td>

                    <td class="text-center">
                        {{ $item->unit?->nama
                            ?? '-' }}
                    </td>

                    <td class="text-right">
                        Rp {{ number_format(
                            $hargaUnit,
                            0,
                            ',',
                            '.'
                        ) }}
                    </td>

                    <td class="text-right">
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
                        colspan="8"
                        class="text-center"
                    >
                        Tidak ada item.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="text-center summary-label">
                Total Estimasi
            </td>

            <td class="text-right text-bold">
                Rp {{ number_format(
                    $totalAmount,
                    0,
                    ',',
                    '.'
                ) }}
            </td>
        </tr>
    </table>

    <div class="terbilang-box">
        <div class="box-title">
            Terbilang
        </div>

        <div>
            {{ $terbilang }}
        </div>
    </div>

    <div class="notes-box">
        <div class="box-title">
            Catatan
        </div>

        <div>
            {{ $pr->notes ?: '-' }}
        </div>
    </div>

    <div class="approval-section">
        <div class="approval-title">
            Persetujuan
        </div>

        @foreach ($signers->chunk(3) as $signerRow)
            @php
                $columnCount = max($signerRow->count(), 1);
                $columnWidth = 100 / $columnCount;
            @endphp

            <table
                class="approval-table"
                style="margin-bottom: 10px;"
            >
                <tr>
                    @foreach ($signerRow as $signer)
                        @php
                            $signaturePath = $signer->signature_path
                                ? storage_path(
                                    'app/public/'
                                    . ltrim($signer->signature_path, '/')
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
                                    <div style="
                                        height: 45px;
                                    "></div>
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