@php
    $frontendUrl = rtrim(
        config(
            'app.frontend_url',
            env('FRONTEND_URL', config('app.url'))
        ),
        '/'
    );

    $vendorUrl = $frontendUrl . '/master/vendor';

    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';

    /*
    |--------------------------------------------------------------------------
    | Normalisasi mode email
    |--------------------------------------------------------------------------
    */
    $emailMode = strtolower(
        trim((string) ($mode ?? $type ?? 'approval_request'))
    );

    /*
    |--------------------------------------------------------------------------
    | Nama recipient dan actor
    |--------------------------------------------------------------------------
    */
    $recipientName = $recipient->name
        ?? $recipient->fullname
        ?? $recipient->email
        ?? 'Bapak/Ibu';

    $actorName = optional($actor)->name
        ?? optional($actor)->fullname
        ?? optional($actor)->email
        ?? 'Approver';

    /*
    |--------------------------------------------------------------------------
    | Judul email
    |--------------------------------------------------------------------------
    */
    $title = match ($emailMode) {
        'submitted'
            => 'Master Vendor Berhasil Disubmit',

        'approved'
            => 'Tahap Approval Vendor Disetujui',

        'final_approved'
            => 'Master Vendor Telah Disetujui',

        'rejected'
            => 'Master Vendor Ditolak',

        default
            => 'Approval Master Vendor',
    };

    /*
    |--------------------------------------------------------------------------
    | Deskripsi email
    |--------------------------------------------------------------------------
    */
    $description = match ($emailMode) {
        'submitted'
            => 'Data Master Vendor berhasil disubmit dan telah masuk ke proses approval.',

        'approved'
            => 'Tahap approval Master Vendor telah disetujui oleh '
                . $actorName
                . ' dan masih menunggu proses approval berikutnya.',

        'final_approved'
            => 'Master Vendor telah disetujui sepenuhnya oleh '
                . $actorName
                . ' dan seluruh proses approval telah selesai.',

        'rejected'
            => 'Master Vendor telah ditolak oleh '
                . $actorName
                . '.',

        default
            => 'Terdapat data Master Vendor yang membutuhkan review dan approval Anda.',
    };

    /*
    |--------------------------------------------------------------------------
    | Status yang ditampilkan
    |--------------------------------------------------------------------------
    */
    $displayStatus = match ($emailMode) {
        'submitted',
        'approval_request',
        'approved'
            => 'PENDING REVIEW',

        'final_approved'
            => 'APPROVED',

        'rejected'
            => 'REJECTED',

        default
            => $vendor->status_approval ?? '-',
    };

    /*
    |--------------------------------------------------------------------------
    | Warna status
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Informasi tambahan sesuai mode
    |--------------------------------------------------------------------------
    */
    $infoText = match ($emailMode) {
        'approval_request'
            => 'Email ini merupakan permintaan approval Master Vendor. Mohon lakukan pengecekan data vendor sebelum memberikan keputusan.',

        'submitted'
            => 'Master Vendor telah berhasil disubmit dan sedang menunggu proses approval dari approver yang ditentukan.',

        'approved'
            => 'Salah satu tahap approval telah selesai. Master Vendor masih menunggu approval pada tahap berikutnya.',

        'final_approved'
            => 'Seluruh proses approval Master Vendor telah selesai dan data Vendor telah berstatus APPROVED.',

        'rejected'
            => 'Proses approval Master Vendor telah dihentikan karena data Vendor ditolak.',

        default
            => 'Email ini khusus untuk proses review Vendor. Mohon lakukan pengecekan data vendor sebelum approval.',
    };

    /*
    |--------------------------------------------------------------------------
    | Teks tombol
    |--------------------------------------------------------------------------
    */
    $buttonText = match ($emailMode) {
        'approval_request'
            => 'Review Master Vendor',

        default
            => 'Buka Master Vendor',
    };
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">

    <title>{{ $title }}</title>
</head>

<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,sans-serif;color:#1f2937;">
<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    style="background:#eef2f7;padding:32px 0;"
>
<tr>
<td align="center">

<table
    width="660"
    cellpadding="0"
    cellspacing="0"
    style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;"
>

<tr>
<td style="padding:24px 30px;background:#0f766e;">
    <img
        src="{{ $logoUrl }}"
        alt="Pro Energi"
        style="height:40px;display:block;"
    >
</td>
</tr>

<tr>
<td style="padding:30px;">

    <div style="display:inline-block;padding:6px 12px;border-radius:999px;background:#ccfbf1;color:#115e59;font-size:12px;font-weight:bold;letter-spacing:.4px;margin-bottom:14px;">
        MASTER DATA VENDOR
    </div>

    <h2 style="margin:0 0 10px;font-size:24px;color:#111827;">
        {{ $title }}
    </h2>

    <p style="margin:0 0 22px;font-size:14px;line-height:1.7;color:#4b5563;">
        Dear <strong>{{ $recipientName }}</strong>,<br>
        {{ $description }}
    </p>

    <table
        width="100%"
        cellpadding="0"
        cellspacing="0"
        style="border-collapse:collapse;margin:18px 0;border-radius:12px;overflow:hidden;"
    >
        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;width:35%;font-size:13px;color:#64748b;">
                Nama Vendor
            </td>

            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;font-weight:bold;color:#111827;">
                {{ $vendor->nama_vendor }}
            </td>
        </tr>

        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">
                Kode Vendor
            </td>

            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">
                {{ $vendor->kode_vendor ?? '-' }}
            </td>
        </tr>

        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">
                Inisial
            </td>

            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">
                {{ $vendor->inisial_vendor ?? '-' }}
            </td>
        </tr>

        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">
                Status Approval
            </td>

            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">
                <span style="display:inline-block;padding:6px 12px;border-radius:999px;background:{{ $statusStyle['background'] }};color:{{ $statusStyle['color'] }};font-weight:bold;font-size:12px;letter-spacing:.3px;">
                    {{ strtoupper((string) $displayStatus) }}
                </span>
            </td>
        </tr>

        @if (!empty($notes))
            <tr>
                <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">
                    Catatan
                </td>

                <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">
                    {{ $notes }}
                </td>
            </tr>
        @endif
    </table>

    <div style="margin:24px 0;padding:16px 18px;border-radius:12px;background:#f0fdfa;border:1px solid #99f6e4;color:#115e59;font-size:13px;line-height:1.6;">
        {{ $infoText }}
    </div>

    <p style="margin:24px 0;">
        <a
            href="{{ $vendorUrl }}"
            style="display:inline-block;padding:13px 22px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:bold;font-size:14px;"
        >
            {{ $buttonText }}
        </a>
    </p>

    <p style="margin:20px 0 0;font-size:13px;color:#64748b;">
        Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.
    </p>
</td>
</tr>

<tr>
<td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;font-size:12px;color:#94a3b8;text-align:center;">
    Copyright © {{ date('Y') }}
    <a
        href="https://proenergi.com/en"
        style="color:#0f766e;"
    >
        Proenergi.com
    </a>
    All Right Reserved.
</td>
</tr>

</table>

</td>
</tr>
</table>
</body>
</html>
