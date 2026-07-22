<!DOCTYPE html>

<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order</title>
<style>
   

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 8.5pt;
    }

    table {
        border-collapse: collapse;
    }

    .box {
        border: 1px solid #000;
        padding: 8px;
        vertical-align: top;
    }

    .item-table {
        width: 100%;
        border: 1px solid #000;
        margin-top: 15px;
    }

    .item-table th,
    .item-table td {
        border: 1px solid #000;
        padding: 5px;
    }

    .summary-table {
        width: 35%;
        float: right;
        margin-top: 10px;
    }

    .summary-table td {
        border: 1px solid #000;
        padding: 5px;
    }

    .clearfix {
        clear: both;
    }

    .notes-box {
        border: 1px solid #000;
        min-height: 60px;
        padding: 8px;
    }

    .signature {
        margin-top: 70px;
    }

    .signature td {
        text-align: center;
    }
</style>

</head>

<body>

<!-- HEADER LOGO -->
<table width="100%">
    <tr>
        <td align="left">
            <img
                src="{{ public_path('images/logo-kiri-penawaran.png') }}"
                style="height:60px;"
            >
        </td>

        <td align="right">
            <img
                src="{{ public_path('images/logo-kanan-penawaran.png') }}"
                style="height:60px;"
            >
        </td>
    </tr>
</table>

<!-- TITLE -->
<table width="100%" style="margin-top:15px;">
    <tr>
        <td align="center">
            <h2 style="margin:0;">
                PURCHASE ORDER
            </h2>

            <hr style="width:180px;">
        </td>
    </tr>
</table>

<!-- PO INFO -->
<table width="100%" style="margin-top:15px;">
    <tr>
        <td>
            <strong>PO Number :</strong>
            {{ $po->nomor_po }}
        </td>

        <td align="right">
            <strong>PO Date :</strong>
            {{ \Carbon\Carbon::parse($po->tanggal_inven)->format('d/m/Y') }}
        </td>
    </tr>
</table>

<!-- BUYER / VENDOR -->
<table width="100%" style="margin-top:20px;">
    <tr>

        <td width="40%" class="box">

            <strong>PT PRO ENERGI</strong>

            <br><br>

            GRAHA IRAMA BUILDING LT.6 UNIT G
            <br>
            JL. HR RASUNA SAID KAV 1-2
            <br>
            KUNINGAN TIMUR
            <br>
            JAKARTA SELATAN

        </td>

        <td width="5%"></td>

        <td width="35%" class="box">

            <strong>VENDOR</strong>

            <br><br>

            {{ $po->vendor->nama_vendor }}

        </td>

        <td width="5%"></td>

        <td width="15%" class="box">

            <table width="100%">
                <tr>
                    <td>Terms</td>
                </tr>

                <tr>
                    <td>
                        {{ $po->terms }}
                        <?php if($po->terms == 'NET'){?>
                            {{ $po->terms_day }}
                        <?php }?>
                    </td>
                </tr>

                <tr>
                    <td style="padding-top:10px;">
                        Taxable : YES
                    </td>
                </tr>
            </table>

        </td>

    </tr>
</table>

<!-- ITEM TABLE -->
<table class="item-table">

    <thead>

        <tr>
            <th width="5%">No</th>
            <th width="20%">Item</th>
            <th width="25%">Description</th>
            <th width="15%">Qty (L)</th>
            <th width="15%">Unit Price</th>
            <th width="10%">Tax</th>
            <th width="15%">Amount</th>
        </tr>

    </thead>

    <tbody>

        <tr>

            <td align="center">
                1
            </td>

            <td>
                {{ $po->produk->jenis_produk ?? '-' }}
            </td>

            <td>
                {{ $po->produk->merk_dagang ?? '-' }}
            </td>

            <td align="right">
                {{ number_format($po->volume_po) }}
            </td>

            <td align="right">
                {{ number_format($po->harga_tebus, 2, '.', ',') }}
            </td>

            <td align="center">
                {{ $po->kd_tax }}
            </td>

            <td align="right">
                {{ number_format($po->subtotal, 2, '.', ',') }}
            </td>

        </tr>

    </tbody>

</table>

<table width="100%" style="margin-top:15px;">
    <tr>

        <!-- NOTES -->
        <td width="55%" valign="top">

            <strong>Notes :</strong>

            <div style="
                border:1px solid #000;
                min-height:100px;
                padding:8px;
                margin-top:5px;
            ">
                {{ $po->keterangan ?? '-' }}
            </div>

        </td>

        <td width="5%"></td>

        <!-- TOTAL -->
        <td width="40%" valign="top">

            <table
                width="100%"
                border="1"
                cellspacing="0"
                cellpadding="5"
            >

                <tr>
                    <td>Subtotal</td>
                    <td align="right">
                        {{ number_format($po->subtotal,2) }}
                    </td>
                </tr>

                <tr>
                    <td>DPP</td>
                    <td align="right">
                        {{ number_format($po->dpp_11_12,2) }}
                    </td>
                </tr>

                <tr>
                    <td>PPN 12%</td>
                    <td align="right">
                        {{ number_format($po->ppn_12,2) }}
                    </td>
                </tr>

                <tr>
                    <td>PPh 22</td>
                    <td align="right">
                        ({{ number_format($po->pph_22,2) }})
                    </td>
                </tr>

                <tr>
                    <td>
                        <strong>GRAND TOTAL</strong>
                    </td>

                    <td align="right">
                        <strong>
                            {{ number_format($po->total_order,2) }}
                        </strong>
                    </td>
                </tr>

            </table>

        </td>

    </tr>
</table>
<table width="100%" style="margin-top:15px;">
    <tr>

        <td width="5%">
            <strong>Say :</strong>
        </td>
        <!-- NOTES -->
        <td width="95%" valign="top">

            <div style="
                border:1px solid #000;
                padding:8px;
                margin-top:5px;
            ">
                {{ terbilang_inggris($po->total_order) ?? '-' }}
            </div>

        </td>
    </tr>
</table>

</body>
</html>
