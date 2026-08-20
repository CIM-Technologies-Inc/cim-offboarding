<div class="doc">
    <img src="{{ $headerImageSrc }}" alt="CIM Technologies, Inc." class="banner-image header-image" />

    <h1 class="doc-title">CLEARANCE FORM</h1>

    <table class="info-table">
        <tr>
            <th>Employee Name</th>
            <td>{{ $employeeName }}</td>
            <th>Employee Number</th>
            <td>{{ $employeeNumber }}</td>
        </tr>
        <tr>
            <th>Position</th>
            <td>{{ $position }}</td>
            <th>Date Hired</th>
            <td>{{ $dateHired }}</td>
        </tr>
        <tr>
            <th>Department</th>
            <td>{{ $department }}</td>
            <th>Separation Date</th>
            <td>{{ $separationDate }}</td>
        </tr>
    </table>

    <p class="doc-paragraph">
        This employee has requested to be cleared from any liabilities from the company. Please sign below if the
        associate has no pending accountabilities from your department.
    </p>
    <p class="doc-paragraph">
        Finance Department shall not release his/her final pay unless the associate is cleared from all
        accountabilities or payables. <strong><em>Release of Waiver and Quitclaim</em></strong> should be signed by
        the associate and <strong><em>notarized.</em></strong>
    </p>
    <p class="doc-paragraph">
        Any accountabilities discovered not deducted after the release of the final pay should be borne by the
        clearing head/officer.
    </p>

    <table class="clearance-table">
        <thead>
            <tr>
                <th>DESIGNATION</th>
                <th>AUTHORIZED SIGNATORY</th>
                <th>SIGNATURE</th>
                <th>DATE</th>
                <th>REMARKS</th>
            </tr>
        </thead>
        <tbody>
            @if ($immediateHeadRow)
                <tr>
                    <td>{{ $immediateHeadRow['designation'] }}</td>
                    <td>{{ $immediateHeadRow['signatory'] }}</td>
                    <td class="signature-cell">
                        @if ($immediateHeadRow['signatureDataUri'])
                            <img src="{{ $immediateHeadRow['signatureDataUri'] }}" alt="Signature" class="signature-img" />
                        @endif
                    </td>
                    <td>{{ $immediateHeadRow['date'] ?? '' }}</td>
                    <td>{{ $immediateHeadRow['remarks'] }}</td>
                </tr>
            @else
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="clearance-table">
        <thead>
            <tr>
                <th>DEPARTMENT</th>
                <th>AUTHORIZED SIGNATORY</th>
                <th>SIGNATURE</th>
                <th>DATE</th>
                <th>REMARKS</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['department'] }}</td>
                    <td>{{ $row['signatory'] }}</td>
                    <td class="signature-cell">
                        @if ($row['signatureDataUri'])
                            <img src="{{ $row['signatureDataUri'] }}" alt="Signature" class="signature-img" />
                        @endif
                    </td>
                    <td>{{ $row['date'] ?? '' }}</td>
                    <td>{{ $row['remarks'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">&nbsp;</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="approval-block">
        <p class="approval-label">Approved for Payment by:</p>
        <p class="signature-line">___________________</p>
        <p class="approver-name">VICTORIANO T. YAP</p>
        <p class="approver-title">President</p>
    </div>

    <p class="form-code">CIM-HR-CAR FORM-02212025-REV.01</p>

    <img src="{{ $footerImageSrc }}" alt="" class="banner-image footer-image" />
</div>
