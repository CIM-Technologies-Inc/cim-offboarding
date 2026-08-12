<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Form - {{ $employeeName }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Georgia, "Times New Roman", Times, serif;
            font-size: 13px;
            color: #111;
            margin: 0;
            padding: 24px;
            background: #e5e7eb;
        }
        .toolbar {
            max-width: 850px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .toolbar button {
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid #145a3a;
            background: #145a3a;
            color: #fff;
            cursor: pointer;
        }
        .toolbar button:hover { background: #0f4630; }

        .doc {
            max-width: 850px;
            margin: 0 auto;
            background: #fff;
            padding: 40px 50px;
            box-shadow: 0 0 16px rgba(0, 0, 0, 0.15);
        }

        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .header-table td { vertical-align: middle; padding: 0 10px; font-size: 10px; line-height: 1.4; }
        .logo-cell { width: 24%; }
        .logo-text { font-size: 22px; font-weight: bold; color: #145a3a; }
        .logo-sub { font-size: 12px; font-weight: normal; color: #333; }
        .logo-tagline { font-size: 8px; color: #666; margin-top: 2px; }
        .office-cell-makati { width: 44%; text-align: center; border-right: 1px solid #000; }
        .office-cell-cebu { width: 32%; text-align: right; }

        .doc-title { text-align: center; font-size: 24px; font-weight: bold; margin: 16px 0 20px; letter-spacing: 1px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .info-table th, .info-table td { border: 1px solid #000; padding: 6px 10px; font-size: 13px; text-align: left; }
        .info-table th { width: 17%; font-weight: bold; background: #f9fafb; }
        .info-table td { width: 33%; }

        .doc-paragraph { text-align: justify; margin: 0 0 12px; line-height: 1.5; }

        .clearance-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .clearance-table th {
            background: #145a3a; color: #fff; text-transform: uppercase;
            font-size: 12px; padding: 7px 10px; border: 1px solid #000; text-align: left;
        }
        .clearance-table td { border: 1px solid #000; padding: 8px 10px; font-size: 13px; height: 30px; }
        .signature-cell { text-align: center; }
        .signature-img { max-height: 30px; max-width: 110px; }

        .approval-block { text-align: center; margin-top: 36px; }
        .approval-label { margin: 0; }
        .signature-line { margin: 36px 0 2px; }
        .approver-name { font-weight: bold; margin: 0; }
        .approver-title { margin: 0; }

        .form-code { font-size: 10px; font-weight: bold; margin-top: 48px; }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .doc { box-shadow: none; padding: 0; max-width: none; }
            @page { size: letter; margin: 0.5in; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    @include('clearance-form._content')

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
