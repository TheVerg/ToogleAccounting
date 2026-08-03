<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 30px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 14px; margin-bottom: 18px; }
        .logo { width: 190px; height: 70px; object-fit: contain; }
        .title { text-align: right; font-size: 26px; font-weight: 800; letter-spacing: 1.5px; }
        .status { display: inline-block; margin-top: 6px; padding: 3px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 9px; font-weight: 800; text-transform: uppercase; }
        .muted { color: #6b7280; }
        .brand { font-size: 18px; font-weight: 800; }
        .small { font-size: 10px; }
        .box { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; }
        .section-title { margin-bottom: 5px; color: #4b5563; font-size: 9px; font-weight: 800; letter-spacing: .9px; text-transform: uppercase; }
        th { background: #f9fafb; border-bottom: 1px solid #d1d5db; color: #4b5563; font-size: 9px; letter-spacing: .7px; padding: 7px 8px; text-align: left; text-transform: uppercase; }
        td { border-bottom: .5px solid #e5e7eb; padding: 7px 8px; vertical-align: top; }
        .num { text-align: right; }
        .lines { margin-top: 18px; }
        .totals { margin-top: 14px; margin-left: auto; width: 42%; }
        .totals td { border-bottom: .5px solid #d1d5db; }
        .totals .total td { background: #111827; color: #fff; font-size: 13px; font-weight: 800; }
        .details td { border: 0; padding: 0; vertical-align: top; }
        .payment { margin-top: 18px; }
        .notes { margin-top: 18px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 62%; border: 0; padding: 0;">
                <table>
                    <tr>
                        <td style="width: 205px; border: 0; padding: 0;">
                            @if ($logo)
                                <img class="logo" src="{{ $logo }}" alt="Logo">
                            @endif
                        </td>
                        <td style="border: 0; padding: 0;">
                            <div class="brand">{{ $invoice->company->name }}</div>
                            @if ($invoice->company->address)
                                <div class="muted small">{!! nl2br(e($invoice->company->address)) !!}</div>
                            @endif
                            <div class="muted small">
                                {{ $invoice->company->email }}
                                @if ($invoice->company->email && $invoice->company->phone) | @endif
                                {{ $invoice->company->phone }}
                            </div>
                            @if ($invoice->company->tax_number)
                                <div class="muted small">Tax/VAT No. {{ $invoice->company->tax_number }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 38%; border: 0; padding: 0;">
                <div class="title">INVOICE</div>
                <div class="status">{{ str_replace('_', ' ', $invoice->status) }}</div>
            </td>
        </tr>
    </table>

    <table class="details">
        <tr>
            <td style="width: 48%; padding-right: 10px;">
                <div class="section-title">Bill to</div>
                <div class="box">
                    <strong>{{ $invoice->contact?->name }}</strong>
                    @if ($invoice->contact?->contact_person)
                        <div class="muted">Attn: {{ $invoice->contact->contact_person }}</div>
                    @endif
                    @if ($invoice->contact?->billing_address || $invoice->contact?->address)
                        <div class="small">{!! nl2br(e($invoice->contact->billing_address ?: $invoice->contact->address)) !!}</div>
                    @endif
                    <div class="muted small">
                        {{ $invoice->contact?->email }}
                        @if ($invoice->contact?->email && $invoice->contact?->phone) | @endif
                        {{ $invoice->contact?->phone }}
                    </div>
                    @if ($invoice->contact?->tax_number)
                        <div class="muted small">Tax/VAT No. {{ $invoice->contact->tax_number }}</div>
                    @endif
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; padding-left: 10px;">
                <div class="section-title">Invoice details</div>
                <div class="box">
                    <table>
                        <tr><td style="border: 0; padding: 3px 0;" class="muted">Invoice no.</td><td style="border: 0; padding: 3px 0;" class="num"><strong>{{ $invoice->invoice_no }}</strong></td></tr>
                        <tr><td style="border: 0; padding: 3px 0;" class="muted">Issue date</td><td style="border: 0; padding: 3px 0;" class="num">{{ $invoice->issue_date?->format('d M Y') }}</td></tr>
                        <tr><td style="border: 0; padding: 3px 0;" class="muted">Due date</td><td style="border: 0; padding: 3px 0;" class="num">{{ $invoice->due_date?->format('d M Y') ?? 'On receipt' }}</td></tr>
                        <tr><td style="border: 0; padding: 3px 0;" class="muted">Currency</td><td style="border: 0; padding: 3px 0;" class="num">{{ $invoice->company->currency }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th style="width: 13%;" class="num">Qty</th>
                <th style="width: 18%;" class="num">Unit price</th>
                <th style="width: 18%;" class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @if ($item->account)
                            <div class="muted small">{{ $item->account->code }} - {{ $item->account->name }}</div>
                        @endif
                        @if ($item->taxes->isNotEmpty())
                            <div class="muted small">{{ $item->taxes->map(fn ($tax) => $tax->tax_name.' '.number_format((float) $tax->rate, 2).'%')->join(' + ') }}</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="num">{{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Tax{{ $invoice->taxGroup ? ' ('.$invoice->taxGroup->name.')' : ($invoice->taxRate ? ' ('.$invoice->taxRate->name.')' : '') }}</td>
            <td class="num">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
        </tr>
        <tr><td>Price treatment</td><td class="num">Tax {{ $invoice->tax_mode }}</td></tr>
        <tr class="total">
            <td>Total</td>
            <td class="num">{{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
        <tr>
            <td>Amount paid</td>
            <td class="num">{{ number_format((float) $invoice->amount_paid, 2) }}</td>
        </tr>
        <tr>
            <td><strong>Balance due</strong></td>
            <td class="num"><strong>{{ number_format((float) $invoice->balanceDue(), 2) }}</strong></td>
        </tr>
    </table>

    <div class="payment">
        <div class="section-title">Payment details</div>
        <div class="box small">
            @if ($invoice->company->bank_name || $invoice->company->bank_account_number)
                <table>
                    <tr><td style="border: 0; padding: 2px 0;" class="muted">Bank</td><td style="border: 0; padding: 2px 0;">{{ $invoice->company->bank_name ?? 'N/A' }}</td></tr>
                    <tr><td style="border: 0; padding: 2px 0;" class="muted">Account name</td><td style="border: 0; padding: 2px 0;">{{ $invoice->company->bank_account_name ?? $invoice->company->name }}</td></tr>
                    <tr><td style="border: 0; padding: 2px 0;" class="muted">Account number</td><td style="border: 0; padding: 2px 0;">{{ $invoice->company->bank_account_number ?? 'N/A' }}</td></tr>
                    @if ($invoice->company->bank_branch_code)
                        <tr><td style="border: 0; padding: 2px 0;" class="muted">Branch/code</td><td style="border: 0; padding: 2px 0;">{{ $invoice->company->bank_branch_code }}</td></tr>
                    @endif
                    @if ($invoice->company->bank_swift_code)
                        <tr><td style="border: 0; padding: 2px 0;" class="muted">SWIFT</td><td style="border: 0; padding: 2px 0;">{{ $invoice->company->bank_swift_code }}</td></tr>
                    @endif
                </table>
            @else
                <span class="muted">Bank details have not been added for this company.</span>
            @endif
            @if ($invoice->company->payment_instructions)
                <div style="margin-top: 8px;">{!! nl2br(e($invoice->company->payment_instructions)) !!}</div>
            @endif
        </div>
    </div>

    @if ($invoice->notes)
        <div class="notes">
            <div class="section-title">Notes</div>
            <div class="box small">{!! nl2br(e($invoice->notes)) !!}</div>
        </div>
    @endif

    @if (($fiscal ?? null) !== null)
        {{-- ZIMRA fiscalisation block: QR verification code is a legal requirement --}}
        <div style="margin-top: 14px; border: 1px solid #cbd5e1; padding: 10px;">
            <table style="width: 100%; border: 0;">
                <tr>
                    <td style="border: 0; vertical-align: top;">
                        <div class="section-title">ZIMRA Fiscal Invoice</div>
                        <table style="border: 0;" class="small">
                            <tr><td style="border: 0; padding: 1px 8px 1px 0;" class="muted">Device ID</td><td style="border: 0; padding: 1px 0;">{{ $fiscal->device->device_id }}</td></tr>
                            <tr><td style="border: 0; padding: 1px 8px 1px 0;" class="muted">Fiscal day / receipt</td><td style="border: 0; padding: 1px 0;">{{ $fiscal->day?->fiscal_day_no }} / {{ $fiscal->receipt_counter }}</td></tr>
                            <tr><td style="border: 0; padding: 1px 8px 1px 0;" class="muted">Receipt global no</td><td style="border: 0; padding: 1px 0;">{{ $fiscal->receipt_global_no }}</td></tr>
                            @if ($fiscal->qr_data)
                                <tr><td style="border: 0; padding: 1px 8px 1px 0;" class="muted">Verification code</td><td style="border: 0; padding: 1px 0;">{{ $fiscal->qr_data }}</td></tr>
                            @endif
                            <tr><td style="border: 0; padding: 1px 8px 1px 0;" class="muted">Verify at</td><td style="border: 0; padding: 1px 0;">{{ $fiscal->verification_url }}</td></tr>
                        </table>
                    </td>
                    @if (($fiscalQr ?? null) !== null)
                        <td style="border: 0; width: 150px; text-align: right; vertical-align: top;">
                            <img src="{{ $fiscalQr }}" alt="ZIMRA verification QR" style="width: 140px; height: 140px;" />
                        </td>
                    @endif
                </tr>
            </table>
        </div>
    @endif
</body>
</html>
