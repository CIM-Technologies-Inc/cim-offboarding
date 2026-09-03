<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Form - {{ $employeeName }}</title>
    <style>
        @page { margin: 36px 50px; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12px; color: #111; }

        .banner-image { width: 100%; display: block; }
        .header-image { margin-bottom: 6px; }
        .footer-image { margin-top: 24px; }

        .doc-title { text-align: center; font-size: 22px; font-weight: bold; margin: 16px 0 18px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-table th, .info-table td { border: 1px solid #000; padding: 4px 8px; font-size: 12px; text-align: left; }
        .info-table th { width: 17%; font-weight: bold; }
        .info-table td { width: 33%; }

        .doc-paragraph { text-align: justify; margin: 0 0 10px; line-height: 1.4; font-size: 12px; }

        .clearance-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .clearance-table th {
            background-color: #196B24; color: #fff; text-transform: uppercase;
            font-size: 11px; padding: 5px 8px; border: 1px solid #000; text-align: left;
        }
        .clearance-table td { border: 1px solid #000; padding: 6px 8px; font-size: 12px; height: 24px; }
        .signature-cell { text-align: center; }
        .signature-img { max-height: 36px; max-width: 135px; }
        .final-approver-signature-img { max-height: 54px; max-width: 203px; }

        .approval-block { text-align: center; margin-top: 30px; }
        .approval-label { margin: 0; font-size: 12px; }
        .signature-line { margin: 30px 0 2px; }
        .approver-name { font-weight: bold; margin: 0; font-size: 12px; }
        .approver-title { margin: 0; font-size: 12px; }
        .approver-date { margin: 4px 0 0; font-size: 10px; color: #4b5563; }

        .form-code { font-size: 10px; font-weight: bold; margin-top: 30px; }
    </style>
</head>
<body>
    @include('clearance-form._content')
</body>
</html>
