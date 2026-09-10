<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 30px 36px; }
        body { font-family: "Helvetica", Arial, sans-serif; font-size: 11px; color: #111; }

        .doc-title { font-size: 18px; font-weight: bold; margin: 0 0 2px; }
        .doc-subtitle { font-size: 10px; color: #4b5563; margin: 0 0 16px; }

        table.report-table { width: 100%; border-collapse: collapse; }
        table.report-table th {
            background-color: #145A3A; color: #fff; text-transform: uppercase;
            font-size: 9px; padding: 6px 8px; border: 1px solid #0f4630; text-align: left;
        }
        table.report-table td { border: 1px solid #d1d5db; padding: 5px 8px; font-size: 10px; }
        table.report-table tr:nth-child(even) td { background-color: #f9fafb; }

        .empty-note { padding: 16px 0; color: #6b7280; font-size: 11px; }
    </style>
</head>
<body>
    <p class="doc-title">{{ $title }}</p>
    <p class="doc-subtitle">Generated {{ $generatedAt }} &middot; {{ $rows->count() }} record(s)</p>

    @if ($rows->isEmpty())
        <p class="empty-note">No records match the selected filters.</p>
    @else
        <table class="report-table">
            <thead>
                <tr>
                    @foreach ($columns as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_keys($columns) as $key)
                            <td>{{ $row[$key] ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
