<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Form - {{ $employeeName }}</title>
    <style>
        @page { margin: 40px 50px; }
        body { font-family: "Times New Roman", Times, serif; font-size: 11px; color: #111; }

        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .header-table td { vertical-align: middle; padding: 0 8px; font-size: 8px; line-height: 1.35; }
        .logo-cell { width: 24%; }
        .logo-text { font-size: 18px; font-weight: bold; color: #145a3a; }
        .logo-sub { font-size: 10px; font-weight: normal; color: #333; }
        .logo-tagline { font-size: 6.5px; color: #666; margin-top: 2px; }
        .office-cell-makati { width: 44%; text-align: center; border-right: 1px solid #000; }
        .office-cell-cebu { width: 32%; text-align: right; }

        .doc-title { text-align: center; font-size: 20px; font-weight: bold; margin: 14px 0 16px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-table th, .info-table td { border: 1px solid #000; padding: 4px 8px; font-size: 10.5px; text-align: left; }
        .info-table th { width: 17%; font-weight: bold; }
        .info-table td { width: 33%; }

        .doc-paragraph { text-align: justify; margin: 0 0 10px; line-height: 1.4; }

        .clearance-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .clearance-table th {
            background-color: #145a3a; color: #fff; text-transform: uppercase;
            font-size: 10px; padding: 5px 8px; border: 1px solid #000; text-align: left;
        }
        .clearance-table td { border: 1px solid #000; padding: 6px 8px; font-size: 10.5px; height: 24px; }
        .signature-cell { text-align: center; }
        .signature-img { max-height: 24px; max-width: 90px; }

        .approval-block { text-align: center; margin-top: 30px; }
        .approval-label { margin: 0; }
        .signature-line { margin: 30px 0 2px; }
        .approver-name { font-weight: bold; margin: 0; }
        .approver-title { margin: 0; }

        .form-code { font-size: 9px; font-weight: bold; margin-top: 40px; }
    </style>
</head>
<body>
    @include('clearance-form._content')
</body>
</html>
