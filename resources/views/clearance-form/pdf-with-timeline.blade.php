<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Clearance Form &amp; Timeline - {{ $employeeName }}</title>
    <style>
        @include('clearance-form._pdf-styles')

        /* Timeline section only — appended after the Clearance Form's own
           styles above so both share one dompdf-safe stylesheet. */
        .timeline-page { page-break-before: always; font-family: Arial, Helvetica, sans-serif; }
        .timeline-subtitle { text-align: center; color: #555; margin: 0 0 16px; font-size: 12px; }

        .timeline-step { border-bottom: 1px solid #ddd; padding: 8px 0; }
        .timeline-step-plain .timeline-step-label { font-weight: bold; margin: 0; font-size: 12px; }
        .timeline-step-reason { margin: 2px 0 0; font-size: 11px; color: #555; }
        .timeline-step-date { margin: 2px 0 0; font-size: 10px; color: #888; }

        .timeline-step-header { width: 100%; }
        .timeline-step-department { font-weight: bold; font-size: 12px; }
        .timeline-step-approver { margin: 1px 0 0; font-size: 11px; color: #555; }

        .timeline-badge {
            display: inline-block; float: right; padding: 2px 8px; border-radius: 10px;
            font-size: 9px; text-transform: uppercase; font-weight: bold;
        }
        .badge-pending { background: #e5e7eb; color: #4b5563; }
        .badge-progress { background: #dbeafe; color: #1d4ed8; }
        .badge-cleared { background: #d7f0e1; color: #145a3a; }
        .badge-error { background: #fee2e2; color: #b91c1c; }
        .badge-hold { background: #fef3c7; color: #92400e; }
        .badge-overdue { background: #ffedd5; color: #c2410c; }

        .timeline-step-details { margin-top: 4px; clear: both; }
        .timeline-step-details p { margin: 2px 0; font-size: 11px; color: #444; }
        .timeline-overdue { color: #b91c1c !important; font-weight: bold; }
        .timeline-hold { color: #c2410c !important; font-weight: bold; }
        .timeline-cleared-label { color: #145a3a !important; font-weight: bold; }
        .timeline-remarks-box {
            border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c !important;
            padding: 4px 8px; margin-top: 4px !important;
        }

        .timeline-extensions {
            border: 1px solid #145a3a; background: #f0fdf4; padding: 6px 8px; margin-top: 6px; font-size: 10px;
        }
        .timeline-extensions-title { font-weight: bold; color: #145a3a; margin: 0 0 4px; }
        .timeline-extensions ul { margin: 0; padding-left: 16px; }
        .timeline-extensions li { margin-bottom: 4px; }
    </style>
</head>
<body>
    @include('clearance-form._content')
    @include('clearance-form._timeline')
</body>
</html>
