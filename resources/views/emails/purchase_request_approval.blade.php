@php
    $frontendUrl = rtrim(
        config(
            'app.frontend_url',
            env('FRONTEND_URL', config('app.url'))
        ),
        '/'
    );

    /*
    |--------------------------------------------------------------------------
    | Route teknis tetap purchase_request
    |--------------------------------------------------------------------------
    */
    $prUrl = $frontendUrl . '/non_trade/purchase_request';

    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';

    $currentMode = $mode ?? 'approval_request';

    /*
    |--------------------------------------------------------------------------
    | Nama yang tampil ke user: Purchase Requisition
    |--------------------------------------------------------------------------
    */
    $title = match ($currentMode) {
        'final_approved' => 'Purchase Requisition Telah Disetujui',
        'step_approved' => 'Update Approval Purchase Requisition',
        'rejected' => 'Purchase Requisition Ditolak',
        default => 'Approval Purchase Requisition',
    };

    $actorName = optional($actor)->name ?? '-';

    $description = match ($currentMode) {
        'final_approved' =>
            'Purchase Requisition Anda telah mendapatkan final approval oleh '
            . $actorName
            . '.',

        'step_approved' =>
            'Purchase Requisition Anda telah disetujui oleh '
            . $actorName
            . ' dan masih menunggu approval berikutnya.',

        'rejected' =>
            'Purchase Requisition Anda telah ditolak oleh '
            . $actorName
            . '.',

        default =>
            'Terdapat Purchase Requisition yang membutuhkan approval Anda.',
    };

    $displayStatus = match ($currentMode) {
        'approval_request' => 'IN PROGRESS',
        'final_approved' => 'APPROVED',
        'step_approved' => 'IN PROGRESS',
        'rejected' => 'REJECTED',
        default => $pr->status,
    };

    $statusStyle = match (strtoupper((string) $displayStatus)) {
        'APPROVED' => [
            'background' => '#dcfce7',
            'color' => '#166534',
        ],

        'REJECTED' => [
            'background' => '#fee2e2',
            'color' => '#991b1b',
        ],

        default => [
            'background' => '#fef3c7',
            'color' => '#92400e',
        ],
    };

    $tanggalPr = !empty($pr->tanggal_pr)
        ? \Carbon\Carbon::parse($pr->tanggal_pr)->format('d/m/Y')
        : '-';

    $recipientName = $recipient->name
        ?? $recipient->nama
        ?? 'Bapak/Ibu';

    $displayTotal = (float) ($totalAmount ?? 0);
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        {{ $title }}
    </title>
</head>

<body style="
    margin: 0;
    padding: 0;
    background: #f4f6f8;
    font-family: Arial, sans-serif;
    color: #1f2937;
">
    <table
        width="100%"
        cellpadding="0"
        cellspacing="0"
        role="presentation"
        style="background: #f4f6f8; padding: 32px 0;"
    >
        <tr>
            <td align="center">
                <table
                    width="620"
                    cellpadding="0"
                    cellspacing="0"
                    role="presentation"
                    style="
                        width: 100%;
                        max-width: 620px;
                        background: #ffffff;
                        border-radius: 14px;
                        overflow: hidden;
                        border: 1px solid #e5e7eb;
                    "
                >
                    {{-- Header --}}
                    <tr>
                        <td style="
                            padding: 24px 28px;
                            background: #8af7ff;
                        ">
                            <img
                                src="{{ $logoUrl }}"
                                alt="Pro Energi"
                                style="
                                    height: 42px;
                                    display: block;
                                    border: 0;
                                "
                            >
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding: 28px;">
                            <h2 style="
                                margin: 0 0 10px;
                                font-size: 22px;
                                color: #111827;
                            ">
                                {{ $title }}
                            </h2>

                            <p style="
                                margin: 0 0 18px;
                                font-size: 14px;
                                line-height: 1.6;
                                color: #4b5563;
                            ">
                                Dear
                                <strong>
                                    {{ $recipientName }}
                                </strong>,
                                <br>

                                {{ $description }}
                            </p>

                            <table
                                width="100%"
                                cellpadding="0"
                                cellspacing="0"
                                role="presentation"
                                style="
                                    border-collapse: collapse;
                                    margin: 18px 0;
                                "
                            >
                                <tr>
                                    <td style="
                                        padding: 12px;
                                        background: #f9fafb;
                                        border: 1px solid #e5e7eb;
                                        width: 35%;
                                        font-size: 13px;
                                        color: #6b7280;
                                    ">
                                        No. PR
                                    </td>

                                    <td style="
                                        padding: 12px;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                        font-weight: bold;
                                    ">
                                        {{ $pr->nomor_pr ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="
                                        padding: 12px;
                                        background: #f9fafb;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                        color: #6b7280;
                                    ">
                                        Tanggal PR
                                    </td>

                                    <td style="
                                        padding: 12px;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                    ">
                                        {{ $tanggalPr }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="
                                        padding: 12px;
                                        background: #f9fafb;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                        color: #6b7280;
                                    ">
                                        Total Nilai
                                    </td>

                                    <td style="
                                        padding: 12px;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                        font-weight: bold;
                                    ">
                                        Rp {{ number_format(
                                            $displayTotal,
                                            0,
                                            ',',
                                            '.'
                                        ) }}
                                    </td>
                                </tr>

                                @if (!empty($stepOrder))
                                    <tr>
                                        <td style="
                                            padding: 12px;
                                            background: #f9fafb;
                                            border: 1px solid #e5e7eb;
                                            font-size: 13px;
                                            color: #6b7280;
                                        ">
                                            Tahap Approval
                                        </td>

                                        <td style="
                                            padding: 12px;
                                            border: 1px solid #e5e7eb;
                                            font-size: 13px;
                                        ">
                                            Tahap {{ $stepOrder }}

                                            @if (!empty($stepLabel))
                                                — {{ $stepLabel }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="
                                        padding: 12px;
                                        background: #f9fafb;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                        color: #6b7280;
                                    ">
                                        Status
                                    </td>

                                    <td style="
                                        padding: 12px;
                                        border: 1px solid #e5e7eb;
                                        font-size: 13px;
                                    ">
                                        <span style="
                                            display: inline-block;
                                            padding: 5px 10px;
                                            border-radius: 999px;
                                            background: {{ $statusStyle['background'] }};
                                            color: {{ $statusStyle['color'] }};
                                            font-weight: bold;
                                            font-size: 12px;
                                            letter-spacing: 0.3px;
                                        ">
                                            {{ strtoupper((string) $displayStatus) }}
                                        </span>
                                    </td>
                                </tr>

                                @if (!empty($notes))
                                    <tr>
                                        <td style="
                                            padding: 12px;
                                            background: #f9fafb;
                                            border: 1px solid #e5e7eb;
                                            font-size: 13px;
                                            color: #6b7280;
                                        ">
                                            Catatan Penolakan
                                        </td>

                                        <td style="
                                            padding: 12px;
                                            border: 1px solid #e5e7eb;
                                            font-size: 13px;
                                            color: #991b1b;
                                            line-height: 1.5;
                                        ">
                                            {!! nl2br(e($notes)) !!}
                                        </td>
                                    </tr>
                                @endif
                            </table>

                            <p style="
                                margin: 18px 0;
                                font-size: 14px;
                                line-height: 1.6;
                                color: #4b5563;
                            ">
                                Silakan klik tombol berikut untuk membuka halaman
                                Purchase Requisition di SYOP v4.
                            </p>

                            <p style="margin: 24px 0;">
                                <a
                                    href="{{ $prUrl }}"
                                    style="
                                        display: inline-block;
                                        padding: 12px 20px;
                                        background: #2563eb;
                                        color: #ffffff;
                                        text-decoration: none;
                                        border-radius: 8px;
                                        font-weight: bold;
                                        font-size: 14px;
                                    "
                                >
                                    Buka Purchase Requisition
                                </a>
                            </p>

                            <p style="
                                margin: 20px 0 0;
                                font-size: 13px;
                                color: #6b7280;
                            ">
                                Email ini dikirim otomatis oleh sistem SYOP v4.
                                Mohon tidak membalas email ini.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="
                            padding: 16px 28px;
                            background: #f9fafb;
                            border-top: 1px solid #e5e7eb;
                            font-size: 12px;
                            color: #9ca3af;
                            text-align: center;
                        ">
                            Copyright © {{ date('Y') }}

                            <a
                                href="https://proenergi.com/en"
                                style="color: #6b7280;"
                            >
                                Proenergi.com
                            </a>

                            All Rights Reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>