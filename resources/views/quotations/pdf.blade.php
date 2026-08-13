<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $quotation->title }} · {{ $quotation->tenant->name }}</title>
<style>
    @page { margin: 28px; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; color: #172033; font-size: 12px; }
    .paper { border: 1px solid #e5eaf2; border-radius: 12px; overflow: hidden; }
    .accent { height: 10px; background: #0284c7; }
    .header { width: 100%; padding: 28px 30px 18px; border-bottom: 1px solid #edf0f5; }
    .brand, .meta { vertical-align: top; }
    .brand { width: 65%; }
    .meta { width: 35%; text-align: right; }
    .logo-badge { width: 68px; height: 68px; border-radius: 10px; background: #eaf6fd; color: #0284c7; text-align: center; line-height: 68px; font-weight: bold; font-size: 22px; }
    .logo-img { width: 68px; height: 68px; object-fit: contain; border-radius: 10px; }
    .company-profile p { margin: 1px 0; font-size: 10.5px; }
    h1 { margin: 0 0 6px; font-size: 22px; }
    p { margin: 3px 0; color: #64748b; line-height: 1.45; }
    .label { color: #64748b; font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
    .meta-title { color: #0284c7; font-size: 16px; font-weight: bold; margin: 3px 0; }
    .meta p { font-size: 10.5px; margin: 2px 0; }
    .info { width: 100%; padding: 20px 30px; }
    .info td { width: 50%; vertical-align: top; }
    .info strong { display: block; margin-top: 4px; font-size: 15px; }
    .items { width: calc(100% - 60px); margin: 4px 30px 18px; border-collapse: collapse; }
    .items th { padding: 10px; background: #172033; color: #fff; font-size: 10px; text-transform: uppercase; text-align: left; }
    .items td { padding: 10px; border-bottom: 1px solid #edf0f5; vertical-align: top; }
    .right { text-align: right; }
    .items th.right { text-align: right; }
    .summary { width: 290px; margin: 0 30px 22px auto; border-collapse: collapse; }
    .summary td { padding: 6px 0; color: #475569; }
    .summary .grand td { padding: 11px 12px; background: #0284c7; color: #fff; font-size: 16px; font-weight: bold; border-radius: 6px; }
    .footer { width: calc(100% - 60px); margin: 0 30px 26px; padding-top: 16px; border-top: 1px solid #edf0f5; border-collapse: collapse; }
    .footer td { width: 50%; vertical-align: top; padding-right: 14px; }
    .caption { color: #94a3b8; font-size: 10px; margin: 0 30px 20px; }
</style>
</head>
<body>
    <div class="paper">
        <div class="accent"></div>
        <table class="header">
            <tr>
                <td class="brand">
                    <table>
                        <tr>
                            <td style="width: 84px;">
                                @if($logoDataUri)
                                    <img src="{{ $logoDataUri }}" class="logo-img" alt="Logo">
                                @else
                                    <div class="logo-badge">{{ strtoupper(mb_substr($quotation->tenant->name ?: 'CT', 0, 2)) }}</div>
                                @endif
                            </td>
                            <td>
                                @if($logoDataUri)
                                    @if($companyProfile)
                                        <div class="company-profile">
                                            <p>{{ collect([$companyProfile['empresa']['nit'] ?? null ? 'NIT '.$companyProfile['empresa']['nit'] : null, $companyProfile['empresa']['nrc'] ?? null ? 'NRC '.$companyProfile['empresa']['nrc'] : null])->filter()->join(' · ') }}</p>
                                            <p>{{ $companyProfile['sucursal']['direccion'] ?? '' }}</p>
                                            <p>{{ collect([$companyProfile['sucursal']['telefono'] ?? null, $companyProfile['sucursal']['email'] ?? null])->filter()->join(' · ') }}</p>
                                        </div>
                                    @endif
                                @else
                                    <h1>{{ $quotation->tenant->name }}</h1>
                                    @if($quotation->core_sucursal_name)
                                        <p>{{ $quotation->core_sucursal_name }}</p>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="meta">
                    <div class="label">Cotización</div>
                    <div class="meta-title">{{ 'C-'.str_pad($quotation->quotation_number, 6, '0', STR_PAD_LEFT) }} · V{{ $quotation->version }}</div>
                    <p>Fecha: {{ $quotation->created_at?->timezone('America/El_Salvador')->format('d/m/Y') }}</p>
                    <p>Válida hasta: {{ optional($quotation->valid_until)->format('d/m/Y') ?: 'Sin vencimiento' }}</p>
                </td>
            </tr>
        </table>

        <table class="info">
            <tr>
                <td>
                    <span class="label">Cliente</span>
                    <strong>{{ $quotation->customer_name ?: 'Cliente no especificado' }}</strong>
                    <p>{{ collect([$quotation->customer_phone, $quotation->customer_email])->filter()->join(' · ') }}</p>
                </td>
                <td>
                    <span class="label">Propuesta</span>
                    <strong>{{ $quotation->title }}</strong>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th class="right">Cant.</th>
                    <th class="right">Precio</th>
                    <th class="right">Descuento</th>
                    <th class="right">Total</th>
                </tr>
            </thead>
            <tbody>
            @foreach($quotation->lines as $line)
                <tr>
                    <td>{{ $line->description_snapshot }}@if(! $line->price_includes_tax)<br><span style="color:#94a3b8;font-size:9px;">+ IVA (13%)</span>@endif</td>
                    <td class="right">{{ number_format($line->quantity, 2) }}</td>
                    <td class="right">${{ number_format($line->unit_price, 2) }}</td>
                    <td class="right">{{ $line->discount_amount > 0 ? '-$'.number_format($line->discount_amount, 2) : '—' }}</td>
                    <td class="right">${{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="summary">
            <tr><td>Subtotal</td><td class="right">${{ number_format($quotation->subtotal, 2) }}</td></tr>
            @if($quotation->discount_total > 0)
                <tr><td>Descuento</td><td class="right">-${{ number_format($quotation->discount_total, 2) }}</td></tr>
            @endif
            @if($quotation->tax_total > 0)
                <tr><td>IVA</td><td class="right">${{ number_format($quotation->tax_total, 2) }}</td></tr>
            @endif
            <tr class="grand"><td>Total cotizado</td><td class="right">${{ number_format($quotation->total, 2) }}</td></tr>
            @if($quotation->requested_deposit > 0)
                <tr><td>Anticipo sugerido</td><td class="right">${{ number_format($quotation->requested_deposit, 2) }}</td></tr>
            @endif
        </table>

        @if($quotation->terms || $quotation->notes)
            <table class="footer">
                <tr>
                    <td>
                        <span class="label">Condiciones</span>
                        <p>{!! nl2br(e($quotation->terms)) !!}</p>
                    </td>
                    <td>
                        <span class="label">Notas</span>
                        <p>{!! nl2br(e($quotation->notes)) !!}</p>
                    </td>
                </tr>
            </table>
        @endif
    </div>
    <p class="caption">Documento generado por {{ $quotation->tenant->name }} el {{ now('America/El_Salvador')->format('d/m/Y H:i') }}</p>
</body>
</html>
