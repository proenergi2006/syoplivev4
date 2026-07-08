@php
    $printLanguage = strtolower(
        (string) ($lang ?? app()->getLocale() ?? 'id')
    );

    if (!in_array($printLanguage, ['id', 'en'], true)) {
        $printLanguage = 'id';
    }

    app()->setLocale($printLanguage);
@endphp

<!DOCTYPE html>
<html lang="{{ $printLanguage }}">
<head>
    <meta charset="utf-8">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=4" type="image/x-icon">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4" type="image/x-icon">
    
    <title>Purchase Order</title>
    <style>
        @page { margin: 22px 26px 24px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; line-height: 1.45; color: #243247; background: #fff; }
        table { width: 100%; border-collapse: collapse; }
        .bold { font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
        .header { margin-bottom: 14px; border-top: 5px solid #1f4e78; border-bottom: 1px solid #cdd7e3; padding: 12px 0 11px; }
        .header-table td { vertical-align: middle; }
        .document-title { margin: 0; color: #17365d; font-size: 27px; font-weight: bold; letter-spacing: 1.4px; line-height: 1.1; }
        .document-line { width: 62px; height: 4px; margin-top: 8px; background: #ef9d2d; }
        .company-logo { width: 140px; max-height: 72px; }
        .top-info-table { border-collapse: separate; border-spacing: 0; margin-bottom: 14px; }
        .top-info-table > tbody > tr > td { vertical-align: top; }
        .top-info-left { width: 52%; padding-right: 7px; }
        .top-info-right { width: 48%; padding-left: 7px; }
        .info-card, .meta-card, .delivery-card { border: 1px solid #c9d4e2; background: #fff; }
        .info-card-title { padding: 7px 10px; background: #1f4e78; color: #fff; font-size: 9px; font-weight: bold; letter-spacing: .7px; text-transform: uppercase; }
        .info-card-body { min-height: 142px; padding: 10px 11px; }
        .vendor-name { margin-bottom: 4px; color: #17365d; font-size: 13px; font-weight: bold; }
        .vendor-address { min-height: 34px; color: #38485d; line-height: 1.55; }
        .contact-divider { margin: 8px 0; border: 0; border-top: 1px solid #dce3ec; }
        .contact-line { margin-top: 2px; }
        .meta-card { background: #f8fafc; }
        .po-meta td { padding: 6px 8px; border-bottom: 1px solid #dce3ec; vertical-align: top; }
        .po-meta tr:last-child td { border-bottom: 0; }
        .po-meta-label { width: 34%; color: #6c7889; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .po-meta-separator { width: 4%; text-align: center; color: #8995a5; }
        .po-meta-value { width: 62%; color: #17365d; font-size: 10px; }
        .po-number-value { color: #1f4e78; font-size: 12px; font-weight: bold; }
        .delivery-card { margin-top: 9px; }
        .delivery-card-title { padding: 6px 9px; background: #eaf1f8; color: #17365d; font-size: 9px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
        .delivery-card-body { padding: 9px 10px; line-height: 1.5; }
        .company-name { margin-bottom: 3px; color: #17365d; font-size: 11px; font-weight: bold; }
        .tax-address-title { margin-top: 7px; padding-top: 7px; border-top: 1px dashed #c9d4e2; font-weight: bold; }
        .items { margin-top: 2px; table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items tfoot { display: table-row-group; }
        .items tr { page-break-inside: avoid; }
        .items th { padding: 8px 6px; border: 1px solid #17365d; background: #1f4e78; color: #fff; font-size: 9px; font-weight: bold; letter-spacing: .2px; text-align: center; vertical-align: middle; }
        .items td { padding: 7px 6px; border: 1px solid #c8d1dc; vertical-align: top; word-wrap: break-word; word-break: break-word; }
        .items tbody tr:nth-child(even) td { background: #f7f9fc; }
        .items tbody td:first-child { color: #6c7889; }
        .items tfoot td { padding: 6px; border: 1px solid #c8d1dc; background: #f8fafc; }
        .summary-label, .summary-value { font-weight: bold; }
        .summary-label { color: #526276; }
        .grand-total-label, .grand-total-value { border-color: #17365d !important; background: #eaf1f8 !important; color: #17365d; font-size: 12px; font-weight: bold; }
        .grand-total-label { text-transform: uppercase; letter-spacing: .4px; }
        .terbilang-box { margin-top: 10px; border-left: 5px solid #ef9d2d; background: #fff8ed; padding: 8px 11px; page-break-inside: avoid; }
        .terbilang-label { color: #9a5a00; font-size: 9px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
        .terbilang-text { margin-top: 3px; color: #4a3b28; font-size: 10px; font-style: italic; line-height: 1.5; }
        .terms { margin-top: 11px; border: 1px solid #d7dee8; background: #fbfcfe; padding: 9px 11px; line-height: 1.6; page-break-inside: avoid; }
        .terms-title { margin-bottom: 5px; color: #17365d; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; }
        .term-line { margin-top: 2px; }
        .signature-wrapper { margin-top: 13px; page-break-inside: avoid; }
        .signature { table-layout: fixed; border-collapse: collapse; }
        .signature td { border: 1px solid #bfc9d6; }
        .signature-header-cell { height: 25px; padding: 5px 6px; background: #1f4e78; color: #fff; font-size: 9px; font-weight: bold; letter-spacing: .4px; text-align: center; text-transform: uppercase; vertical-align: middle; }
        .signature-cell { height: 115px; padding: 7px 6px; background: #fff; text-align: center; vertical-align: top; word-wrap: break-word; word-break: break-word; }
        .signature-title { margin-bottom: 4px; color: #17365d; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .signature-area { height: 58px; line-height: 58px; text-align: center; }
        .signature-img {
            display: inline-block;
            width: 200px;
            max-height: 52px;
            vertical-align: middle;
        }
        .signature-name { margin-top: 3px; color: #243247; font-size: 10px; font-weight: bold; line-height: 1.3; }
        .signature-date { margin-top: 2px; color: #798698; font-size: 8px; }
        .signature-placeholder { color: #9aa5b4; font-size: 9px; font-style: italic; }
        .signature-merged .signature-cell { height: 104px; }
        .signature-merged .signature-img {
            width: 130px;
            max-height: 48px;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td width="65%">
                    <div class="document-title">{{ __('purchase_order.document_title') }}</div>
                    {{-- <div class="document-line"></div> --}}
                </td>
                <td width="35%" align="right">
                    <img src="{{ public_path('logo-proenergi.png') }}" class="company-logo" alt="PT Pro Energi">
                </td>
            </tr>
        </table>
    </div>

    <table class="top-info-table">
        <tr>
            <td class="top-info-left">
                <div class="info-card">
                    <div class="info-card-title">{{ __('purchase_order.order_to') }}</div>
                    <div class="info-card-body">
                        <div class="vendor-name">{{ $po->vendor->nama_vendor ?? '-' }}</div>
                        <div class="vendor-address">{{ $po->vendor->alamat ?? '-' }}</div>

                        @if (!empty($po->vendor->telepon))
                            <div class="contact-line"><span class="bold">{{ __('purchase_order.phone') }}:</span> {{ $po->vendor->telepon }}</div>
                        @endif

                        @if (!empty($po->vendor->email))
                            <div class="contact-line"><span class="bold">Email:</span> {{ $po->vendor->email }}</div>
                        @endif

                        <hr class="contact-divider">

                        @if (!empty($po->vendor->nama_pic))
                            <div class="contact-line">
                                <span class="bold">{{ __('purchase_order.attention') }}:</span> {{ $po->vendor->nama_pic }}
                                @if (!empty($po->vendor->jabatan_pic))
                                    ({{ $po->vendor->jabatan_pic }})
                                @endif
                            </div>
                        @endif

                        <div class="contact-line">
                            <span class="bold">{{ __('purchase_order.phone') }}:</span>
                            {{ $po->vendor->telp_pic ?? $po->vendor->phone_vendor ?? '-' }}
                        </div>

                        @if (!empty($po->vendor->email_pic))
                            <div class="contact-line"><span class="bold">Email:</span> {{ $po->vendor->email_pic }}</div>
                        @endif
                    </div>
                </div>
            </td>

            <td class="top-info-right">
                <div class="meta-card">
                    <table class="po-meta">
                        <tr>
                            <td class="po-meta-label">{{ __('purchase_order.po_number') }}</td>
                            <td class="po-meta-separator">:</td>
                            <td class="po-meta-value po-number-value">{{ $po->nomor_po }}</td>
                        </tr>
                        <tr>
                            <td class="po-meta-label">{{ __('purchase_order.po_date') }}</td>
                            <td class="po-meta-separator">:</td>
                            <td class="po-meta-value">{{ \Carbon\Carbon::parse($po->tanggal_po)->format('d-M-Y') }}</td>
                        </tr>
                        <tr>
                            <td class="po-meta-label">{{ __('purchase_order.top') }}</td>
                            <td class="po-meta-separator">:</td>
                            <td class="po-meta-value">{{ $po->vendor->top ? $po->vendor->top . ' ' . __('purchase_order.days') : $po->vendor->jenis_pembayaran }}</td>
                        </tr>
                    </table>
                </div>

                <div class="delivery-card">
                    <div class="delivery-card-title">{{ __('purchase_order.delivery_billing_information') }}</div>
                    <div class="delivery-card-body">
                        <div class="company-name">PT PRO ENERGI</div>
                        <div>Gedung Graha Irama LT. 6 Unit G</div>
                        <div>Jl. HR Rasuna Said Blok X1, Kav. 1 - 2</div>
                        <div>Jakarta Selatan 12950</div>

                        <div class="tax-address-title">{{ __('purchase_order.tax_invoice_address') }} : PT PRO ENERGI</div>
                        <div>Graha Irama Lantai 6 Unit G</div>
                        <div>Jl. HR Rasuna Said Kav 1-2X</div>
                        <div>RT.006-RW.04 Kel. Kuningan Timur Kec Setiabudi Jak-sel</div>
                        <div class="bold" style="margin-top: 5px;">{{ __('purchase_order.tax_id') }} 0025.2732.2806.2000</div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="35%">{{ __('purchase_order.item_name') }}</th>
                <th width="10%">{{ __('purchase_order.qty') }}</th>
                <th width="12%">{{ __('purchase_order.unit') }}</th>
                <th width="18%">{{ __('purchase_order.unit_price') }}</th>
                <th width="20%">{{ __('purchase_order.subtotal') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($po->items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ ucfirst($item->nama_item) }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="center">{{ $item->unit->nama ?? '-' }}</td>
                    <td class="right nowrap">Rp {{ number_format($item->harga_unit, 0, ',', '.') }}</td>
                    <td class="right bold nowrap">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @if (($po->vendor->status_pkp ?? '') === 'PKP')
                <tr>
                    <td colspan="5" class="right summary-label">{{ __('purchase_order.subtotal') }}</td>
                    <td class="right summary-value nowrap">Rp {{ number_format($po->items->sum('subtotal'), 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="right summary-label">{{ __('purchase_order.dpp') }}</td>
                    <td class="right summary-value nowrap">Rp {{ number_format($po->dpp, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="right summary-label">{{ __('purchase_order.vat') }}</td>
                    <td class="right summary-value nowrap">Rp {{ number_format($po->ppn, 0, ',', '.') }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="5" class="right grand-total-label">{{ __('purchase_order.grand_total') }}</td>
                <td class="right grand-total-value nowrap">Rp {{ number_format($po->total_nilai, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="terbilang-box">
        <div class="terbilang-label">{{ __('purchase_order.amount_in_words') }}</div>
        <div class="terbilang-text">{{ $terbilang }}</div>
    </div>

    <div class="terms">
        <div class="terms-title">{{ __('purchase_order.terms_conditions') }}</div>
        <div class="term-line">1. {{ __('purchase_order.term_po_number') }}</div>
        <div class="term-line">2. {{ __('purchase_order.term_attach_po') }}</div>
        <div class="term-line">3. {{ __('purchase_order.payment_terms') }}: {{ $po->vendor->top ? $po->vendor->top . ' ' . __('purchase_order.days') : $po->vendor->jenis_pembayaran }}</div>
        <div class="term-line">4. {{ __('purchase_order.reference_pr') }}: {{ $po->purchaseRequests->pluck('nomor_pr')->implode(', ') }}</div>
    </div>

    @php
        $approvalSignatures = $po->approvals
            ->filter(function ($approval) {
                return strtoupper(trim((string) $approval->status)) === 'APPROVED';
            })
            ->sortBy(function ($approval) {
                return sprintf('%010d-%010d', (int) $approval->step_order, (int) $approval->id);
            })
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
                'WAITING' => __('purchase_order.waiting_approval'),
                'PENDING' => __('purchase_order.not_processed'),
                'REJECTED' => __('purchase_order.rejected'),
                'CANCELLED' => __('purchase_order.cancelled'),
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
                        <div class="signature-title">{{ __('purchase_order.created_by') }}</div>
                        <div class="signature-area">
                            @if ($requesterSignatureExists)
                                <img src="{{ $requesterSignature }}" class="signature-img" alt="{{ __('purchase_order.requester_signature') }}">
                            @endif
                        </div>
                        <div class="signature-name">{{ optional($po->requesterSignedBy)->name ?? '-' }}</div>
                        <div class="signature-date">{{ $po->requester_signed_at ? \Carbon\Carbon::parse($po->requester_signed_at)->format('d/m/Y H:i') : '-' }}</div>
                    </td>

                    <td width="50%" class="signature-cell">
                        <div class="signature-title">{{ __('purchase_order.approved_by') }}</div>

                        @if ($firstApproval && strtoupper((string) $firstApproval->status) === 'APPROVED')
                            <div class="signature-area">
                                @if ($approverSignatureExists)
                                    <img src="{{ $approverSignature }}" class="signature-img" alt="{{ __('purchase_order.approver_signature') }}">
                                @endif
                            </div>
                            <div class="signature-name">{{ $firstApproval->approver_name_snapshot ?: ($firstApproval->label ?: '-') }}</div>
                            <div class="signature-date">{{ $firstApproval->approved_at ? \Carbon\Carbon::parse($firstApproval->approved_at)->format('d/m/Y H:i') : '-' }}</div>
                        @else
                            <div class="signature-area signature-placeholder">{{ $firstApproval ? $getApprovalPlaceholder($firstApproval) : __('purchase_order.waiting_approval') }}</div>
                            <div class="signature-name">{{ $firstApproval->label ?? '-' }}</div>
                            <div class="signature-date">-</div>
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
                <tr>
                    <td width="{{ $columnWidth }}%" class="signature-header-cell">{{ __('purchase_order.created_by') }}</td>
                    <td width="{{ $columnWidth * $approvalCount }}%" colspan="{{ $approvalCount }}" class="signature-header-cell">{{ __('purchase_order.approved_by') }}</td>
                </tr>
                <tr>
                    <td width="{{ $columnWidth }}%" class="signature-cell">
                        <div class="signature-area">
                            @if ($requesterSignatureExists)
                                <img src="{{ $requesterSignature }}" class="signature-img" alt="{{ __('purchase_order.requester_signature') }}">
                            @endif
                        </div>
                        <div class="signature-name">{{ optional($po->requesterSignedBy)->name ?? '-' }}</div>
                        <div class="signature-date">{{ $po->requester_signed_at ? \Carbon\Carbon::parse($po->requester_signed_at)->format('d/m/Y H:i') : '-' }}</div>
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
                                        <img src="{{ $approvalSignature }}" class="signature-img" alt="{{ __('purchase_order.approver_signature') }}">
                                    @endif
                                </div>
                                <div class="signature-name">{{ $approval->approver_name_snapshot ?: ($approval->label ?: '-') }}</div>
                                <div class="signature-date">{{ $approval->approved_at ? \Carbon\Carbon::parse($approval->approved_at)->format('d/m/Y H:i') : '-' }}</div>
                            @else
                                <div class="signature-area signature-placeholder">{{ $getApprovalPlaceholder($approval) }}</div>
                                <div class="signature-name">{{ $approval->label ?? '-' }}</div>
                                <div class="signature-date">-</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
</body>
</html>
