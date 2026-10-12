{{--
    Static, server-rendered mirror of the Offboarding Status/Timeline modal's
    own "Timeline" tab (`status-timeline-modal.blade.php`, the
    `x-for="(step, index) in selected.timeline"` block) — same data source
    (`OffboardingRequest::approverActivityTimeline()`, decorated with
    `badgeLabel`/`badgeClass` by `ClearanceFormController::decorateTimelineStepsForPdf()`),
    rendered as plain HTML instead of interactive Alpine markup. Deliberately
    omits everything interactive-only the live tab itself only ever shows as
    a button/form ("Notify Approver" + its email-template picker,
    `reminderSentAt`) or already keeps commented out there (delegation
    details, the signature-image block) — a printed document has no action
    to take, only a record to show.

    Also omits the per-item checklist breakdown and the item-level
    "delegation event" plain steps (checklist assigned/delegate completed/
    item cleared by other/item held/ready for approval) that the live tabs
    still show in full — `decorateTimelineStepsForPdf()` strips both before
    this view ever sees them, so this document reads as one summary card
    per checklist (department/signatory, assigned/due/cleared dates, hold
    history, due-date extensions) rather than repeating the same ground at
    the per-item level too. See that method's own docblock for the full
    reasoning.

    Expects `$timelineSteps` (array) plus `$headerImageSrc`/`$footerImageSrc`
    (already in scope from `clearance-form.pdf-with-timeline`'s own `$data`,
    same variables `clearance-form._content` uses) and `$employeeName`/
    `$employeeNumber`.
--}}
<div class="timeline-page">
    <img src="{{ $headerImageSrc }}" alt="CIM Technologies, Inc." class="banner-image header-image" />

    <h1 class="doc-title">OFFBOARDING TIMELINE</h1>
    <p class="timeline-subtitle">{{ $employeeName }} &mdash; {{ $employeeNumber }}</p>

    @foreach ($timelineSteps as $step)
        @if (empty($step['rich']))
            {{-- Plain step — same fields/fallback the live tab's own
                 `!step.rich` branch shows: label, optional "Reason:
                 {comment}", then the date or "Not yet reached". --}}
            <div class="timeline-step timeline-step-plain">
                <p class="timeline-step-label">{{ $step['label'] }}</p>
                @if (! empty($step['comment']))
                    <p class="timeline-step-reason">Reason: {{ $step['comment'] }}</p>
                @endif
                <p class="timeline-step-date">{{ $step['date'] ?? 'Not yet reached' }}</p>
            </div>
        @else
            <div class="timeline-step timeline-step-rich">
                <div class="timeline-step-header">
                    <span class="timeline-step-department">{{ $step['department'] ?? 'Department' }}</span>
                    <span class="timeline-badge {{ $step['badgeClass'] }}">{{ $step['badgeLabel'] }}</span>
                </div>
                <p class="timeline-step-approver">Clearance Signatory: {{ $step['approverName'] ?? '—' }}</p>

                <div class="timeline-step-details">
                    <p>Date Assigned: {{ $step['assignedAt'] ?? '—' }}</p>

                    @if (! empty($step['dueAt']))
                        <p class="{{ $step['isOverdue'] ? 'timeline-overdue' : '' }}">
                            Until End of the Day: {{ $step['dueAt'] }}
                            @if ($step['isOverdue']) &mdash; Overdue @endif
                        </p>
                    @endif

                    @if (! empty($step['clearanceSigningDueAt']))
                        <p class="{{ $step['isClearanceSigningOverdue'] ? 'timeline-overdue' : '' }}">
                            Clearance Signing Due Date: {{ $step['clearanceSigningDueAt'] }}
                            @if ($step['isClearanceSigningOverdue']) &mdash; Overdue @endif
                        </p>
                    @endif

                    <p>
                        First Viewed: {{ $step['firstViewedAt'] ?? 'Not viewed yet' }}
                        @if (! empty($step['firstViewedByName'])) by {{ $step['firstViewedByName'] }} @endif
                    </p>

                    @if (! empty($step['declinedAt']))
                        <p class="timeline-hold">
                            On Hold: {{ $step['declinedAt'] }}
                            @if (! empty($step['declinedByName'])) by {{ $step['declinedByName'] }} @endif
                        </p>
                    @endif
                    @if (! empty($step['declineReason']))
                        <p class="timeline-hold">Reason: {{ $step['declineReason'] }}</p>
                    @endif
                    @if (! empty($step['onHoldRemovedAt']))
                        <p>
                            On Hold Removed: {{ $step['onHoldRemovedAt'] }}
                            @if (! empty($step['onHoldRemovedByName'])) by {{ $step['onHoldRemovedByName'] }} @endif
                        </p>
                    @endif
                    @if (! empty($step['onHoldRemovalReason']))
                        <p>Hold Removal Reason: {{ $step['onHoldRemovalReason'] }}</p>
                    @endif

                    @if (! empty($step['isReadyForApproval']))
                        <p class="timeline-cleared-label">Ready For Approval</p>
                    @endif

                    @if ($step['status'] === 'approved')
                        <p class="{{ ! empty($step['wasCompletedLate']) ? 'timeline-overdue' : '' }}">
                            Completed: {{ $step['approvedAt'] }}
                            @if (! empty($step['wasCompletedLate'])) &mdash; Completed Beyond Deadline @endif
                        </p>
                    @endif

                    @if (! empty($step['approvalMethod']))
                        <p>Approval Method: {{ $step['approvalMethod'] }}</p>
                    @endif
                    @if (! empty($step['remarks']))
                        <p>Remarks: {{ $step['remarks'] }}</p>
                    @endif
                    @if (! empty($step['approvalRemarks']))
                        <p class="timeline-remarks-box">Remarks: {{ $step['approvalRemarks'] }}</p>
                    @endif
                </div>

                @if (! empty($step['dueDateExtensions']))
                    <div class="timeline-extensions">
                        <p class="timeline-extensions-title">
                            Last Working Day Extended
                            @if (! empty($step['originalDueDate']))
                                (Originally due: {{ $step['originalDueDate'] }})
                            @endif
                        </p>
                        <ul>
                            @foreach ($step['dueDateExtensions'] as $extension)
                                <li>
                                    {{ $extension['previousDueDate'] }} &rarr; {{ $extension['newDueDate'] }}
                                    (+{{ $extension['additionalDays'] }} day{{ $extension['additionalDays'] != 1 ? 's' : '' }})
                                    by {{ $extension['extendedBy'] }} on {{ $extension['extendedAt'] }}
                                    @if (! empty($extension['reason']))
                                        <div>Reason: {{ $extension['reason'] }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

            </div>
        @endif
    @endforeach

    <img src="{{ $footerImageSrc }}" alt="" class="banner-image footer-image" />
</div>
