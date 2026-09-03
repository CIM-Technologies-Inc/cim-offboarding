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

        .banner-image { width: 100%; display: block; }
        .header-image { margin-bottom: 8px; }
        .footer-image { margin-top: 30px; }

        .doc-title { text-align: center; font-size: 24px; font-weight: bold; margin: 16px 0 20px; letter-spacing: 1px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .info-table th, .info-table td { border: 1px solid #000; padding: 6px 10px; font-size: 13px; text-align: left; }
        .info-table th { width: 17%; font-weight: bold; background: #f9fafb; }
        .info-table td { width: 33%; }

        .doc-paragraph { text-align: justify; margin: 0 0 12px; line-height: 1.5; }

        .clearance-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .clearance-table th {
            background: #196B24; color: #fff; text-transform: uppercase;
            font-size: 12px; padding: 7px 10px; border: 1px solid #000; text-align: left;
        }
        .clearance-table td { border: 1px solid #000; padding: 8px 10px; font-size: 13px; height: 30px; }
        .signature-cell { text-align: center; }
        .signature-img { max-height: 45px; max-width: 165px; }
        .final-approver-signature-img { max-height: 68px; max-width: 248px; }

        .approval-block { text-align: center; margin-top: 36px; }
        .approval-label { margin: 0; }
        .signature-line { margin: 36px 0 2px; }
        .approver-name { font-weight: bold; margin: 0; }
        .approver-title { margin: 0; }
        .approver-date { margin: 4px 0 0; font-size: 11px; color: #4b5563; }

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
