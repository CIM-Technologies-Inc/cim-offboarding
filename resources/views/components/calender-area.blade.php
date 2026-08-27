@props(['offboardingEvents' => []])

<div>
    <script type="application/json" id="offboarding-events-data">{!! $offboardingEvents->toJson(JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="custom-calendar">
            <div id="calendar" class="min-h-screen"></div>
        </div>
    </div>

    <!-- Offboardee Status Modal — the Offboarding Status tab (per-checklist
         approval cards) is hidden here; the Calendar page only needs the
         chronological Timeline view. -->
    <x-offboarding.status-timeline-modal :hide-status-tab="true" />
</div>
