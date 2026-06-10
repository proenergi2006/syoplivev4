@php
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/');
    $poUrl = $frontendUrl . '/non_trade/purchase_order';
    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';

    $currentMode = $mode ?? 'approval_request';

    $title = match ($currentMode) {
        'final_approved' => 'Purchase Order Telah Disetujui',
        'step_approved' => 'Update Approval Purchase Order',
        'rejected' => 'Purchase Order Ditolak',
        default => 'Approval Purchase Order',
    };

    $description = match ($currentMode) {
        'final_approved' => 'Purchase Order Anda telah mendapatkan final approval oleh ' . optional($actor)->name . '.',
        'step_approved' => 'Purchase Order Anda telah disetujui oleh ' . optional($actor)->name . ' dan masih menunggu approval berikutnya.',
        'rejected' => 'Purchase Order Anda telah ditolak oleh ' . optional($actor)->name . '.',
        default => 'Terdapat Purchase Order yang membutuhkan approval Anda.',
    };

    $displayStatus = match ($currentMode) {
        'approval_request' => 'IN PROGRESS',
        'final_approved' => 'APPROVED',
        'step_approved' => 'IN PROGRESS',
        'rejected' => 'REJECTED',
        default => $po->status,
    };

    $statusStyle = match (strtoupper($displayStatus ?? '')) {
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
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <h2 style="margin:0 0 10px;font-size:22px;color:#111827;">
        {{ $title }}
    </h2>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
        <tr>
            <td align="center">
                <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:24px 28px;background:#a5abb9;">
                            <img src="{{ $logoUrl }}" alt="Pro Energi" style="height:42px;display:block;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <h2 style="margin:0 0 10px;font-size:22px;color:#111827;">
                                Approval Purchase Order
                            </h2>

                            <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#4b5563;">
                                Dear <strong>{{ $recipient->name }}</strong>,<br>
                                {{ $description }}
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:18px 0;">
                                <tr>
                                    <td style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;width:35%;font-size:13px;color:#6b7280;">No. PO</td>
                                    <td style="padding:12px;border:1px solid #e5e7eb;font-size:13px;font-weight:bold;">{{ $po->nomor_po }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Tanggal PO</td>
                                    <td style="padding:12px;border:1px solid #e5e7eb;font-size:13px;">{{ date('d/m/Y', strtotime($po->tanggal_po)) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Total Nilai</td>
                                    <td style="padding:12px;border:1px solid #e5e7eb;font-size:13px;font-weight:bold;">
                                        Rp {{ number_format($po->total_nilai ?? 0, 0, ',', '.') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Status</td>
                                    <td style="padding:12px;border:1px solid #e5e7eb;font-size:13px;">
                                        <span style="
                                            display:inline-block;
                                            padding:5px 10px;
                                            border-radius:999px;
                                            background:{{ $statusStyle['background'] }};
                                            color:{{ $statusStyle['color'] }};
                                            font-weight:bold;
                                            font-size:12px;
                                            letter-spacing:0.3px;
                                        ">
                                            {{ strtoupper($displayStatus) }}
                                        </span>
                                    </td>
                                </tr>
                                @if (!empty($notes))
                                    <tr>
                                        <td style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Catatan Penolakan</td>
                                        <td style="padding:12px;border:1px solid #e5e7eb;font-size:13px;color:#991b1b;line-height:1.5;">
                                            {!! nl2br(e($notes)) !!}
                                        </td>
                                    </tr>
                                @endif
                            </table>

                            <p style="margin:18px 0;font-size:14px;line-height:1.6;color:#4b5563;">
                                Silakan klik tombol berikut untuk membuka halaman Purchase Order di SYOP v4.
                            </p>

                            <p style="margin:24px 0;">
                                <a href="{{ $poUrl }}"
                                   style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;">
                                    Buka Purchase Order
                                </a>
                            </p>

                            <p style="margin:20px 0 0;font-size:13px;color:#6b7280;">
                                Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center;">
                           Copyright © 2026 <a href="https://proenergi.com/en">Proenergi.com</a> All Right Reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>