<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $run->reference }} - {{ $item->employee_name }}</title>
    <style>
        @page { margin: 26px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        .header { width: 100%; border-bottom: 2px solid #111827; padding-bottom: 14px; margin-bottom: 16px; }
        .logo { width: 190px; height: 70px; object-fit: contain; vertical-align: middle; }
        .brand { font-size: 18px; font-weight: 700; letter-spacing: .2px; }
        .muted { color: #6b7280; }
        .title { text-align: right; font-size: 22px; font-weight: 800; letter-spacing: 1px; }
        .status { display: inline-block; padding: 3px 8px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .section { margin-top: 14px; }
        .section-title { font-size: 9px; font-weight: 800; letter-spacing: .9px; text-transform: uppercase; color: #4b5563; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; }
        .info td { padding: 5px 0; border-bottom: .5px solid #e5e7eb; }
        .label { color: #6b7280; width: 30%; }
        th { text-align: left; padding: 7px 8px; border-bottom: 1px solid #d1d5db; background: #f9fafb; color: #4b5563; text-transform: uppercase; font-size: 9px; letter-spacing: .7px; }
        td { padding: 7px 8px; border-bottom: .5px solid #e5e7eb; }
        .num { text-align: right; }
        .split { width: 100%; margin-top: 12px; }
        .split td { border: 0; padding: 0; vertical-align: top; }
        .panel { border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
        .summary { margin-top: 16px; border: 1px solid #111827; }
        .summary td { font-size: 12px; font-weight: 700; }
        .summary .net td { background: #111827; color: #ffffff; font-size: 15px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 70%;">
                <table>
                    <tr>
                        <td style="width: 205px; border: 0; padding: 0;">
                            @if ($logo)
                                <img class="logo" src="{{ $logo }}" alt="Logo">
                            @else
                                <div style="width: 58px; height: 58px; background: #111827; color: #fff; text-align: center; line-height: 58px; font-weight: 800;">H</div>
                            @endif
                        </td>
                        <td style="border: 0; padding: 0;">
                            <div class="brand">{{ $run->company->name }}</div>
                            <div class="muted">Payroll reference {{ $run->reference }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 30%; border: 0; padding: 0;">
                <div class="title">PAYSLIP</div>
                <div style="margin-top: 6px;"><span class="status">{{ $run->status }}</span></div>
            </td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td class="label">Employee</td>
            <td><strong>{{ $item->employee_name }}</strong></td>
            <td class="label">Pay period</td>
            <td>{{ $run->period_from->format('d M Y') }} - {{ $run->period_to->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Employee no.</td>
            <td>{{ $employee?->employee_no ?? 'N/A' }}</td>
            <td class="label">Pay date</td>
            <td>{{ $run->pay_date->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Job title</td>
            <td>{{ $item->job_title ?? $assignment?->job_title ?? 'N/A' }}</td>
            <td class="label">Department</td>
            <td>{{ $assignment?->department ?? $employee?->department ?? 'N/A' }}</td>
        </tr>
        <tr>
            <td class="label">Email</td>
            <td>{{ $employee?->email ?? $employee?->user?->email ?? 'N/A' }}</td>
            <td class="label">Paid from</td>
            <td>{{ $run->moneyAccount?->name ?? 'N/A' }}</td>
        </tr>
    </table>

    <table class="split">
        <tr>
            <td style="width: 49%; padding-right: 8px;">
                <div class="section-title">Earnings</div>
                <table class="panel">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($earnings as $line)
                            <tr>
                                <td>{{ $line['name'] ?? 'Earning' }}</td>
                                <td class="num">{{ number_format((float) ($line['amount'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2">No earnings.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td style="width: 49%; padding-left: 8px;">
                <div class="section-title">Deductions</div>
                <table class="panel">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deductions as $line)
                            <tr>
                                <td>{{ $line['name'] ?? 'Deduction' }}</td>
                                <td class="num">{{ number_format((float) ($line['amount'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2">No deductions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td>Gross pay</td>
            <td class="num">{{ number_format((float) $item->gross, 2) }}</td>
        </tr>
        <tr>
            <td>Total deductions</td>
            <td class="num">{{ number_format((float) $item->deductions_total, 2) }}</td>
        </tr>
        <tr class="net">
            <td>Net pay</td>
            <td class="num">{{ number_format((float) $item->net, 2) }}</td>
        </tr>
    </table>
</body>
</html>
