<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
        .header { width: 100%; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 12px; }
        .logo { width: 180px; height: 66px; object-fit: contain; }
        h1 { font-size: 16px; margin-bottom: 2px; color: #122036; }
        .meta { color: #64748b; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; text-transform: uppercase; font-size: 8px; letter-spacing: .8px;
             color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 5px; }
        td { padding: 4px 5px; border-bottom: .5px solid #e2e8f0; }
        td.num { text-align: right; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 210px; border: 0; padding: 0;">
                @if (! empty($logo))
                    <img class="logo" src="{{ $logo }}" alt="Logo">
                @endif
            </td>
            <td style="border: 0; padding: 0;">
                <h1>{{ $title }}</h1>
                <p class="meta">{{ $meta }}</p>
            </td>
        </tr>
    </table>
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td class="{{ is_numeric($cell) ? 'num' : '' }}">
                            {{ is_numeric($cell) ? number_format((float) $cell, 2) : $cell }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}">No records.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
