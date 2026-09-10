{{-- Per-checklist-item status list, shared by the "Offboarding Status" and "Timeline" tabs
     of status-timeline-modal.blade.php so both use identical icons/colors. Expects a `step`
     Alpine variable in scope with a `checklistItems` array of {title, status, timestamp,
     actorPrefix, actorName, remark, completedLate}. `timestamp`/`actorPrefix`/`actorName` are only ever
     populated server-side for 'completed' (checked_at/checkedBy) or 'on_hold' (held_at/heldBy)
     — never for 'pending'/'in_progress'/'declined' — see
     `OffboardingRequest::approverActivityTimeline()`. `actorPrefix` is 'Checked by'/'Hold by'
     when a genuine task assignee (per-item signatory or whole-checklist delegate) actually
     performed the action, or literally 'Clearance Signatory' when the Clearance Signatory did
     it themselves (no distinct assignee ever existed for this item) — always the ACTUAL
     recorded actor, never an assumed/configured one. `remark` is whatever they typed alongside
     the action — it can also be present on an 'in_progress' item (a remark left before the item
     is actually checked/held), but never on a genuinely untouched 'pending' one. `completedLate`
     is only ever true alongside status === 'completed' — items share their checklist's own due
     date (there's no per-item due date), so it compares this item's own `checked_at` against the
     assignment's `due_at`. --}}
<template x-if="step.checklistItems && step.checklistItems.length">
    <div class="mt-3 space-y-1.5 border-t border-gray-100 pt-2 dark:border-gray-800">
        <template x-for="(item, itemIndex) in step.checklistItems" :key="itemIndex">
            <div class="flex items-start gap-2 text-xs">
                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full"
                    :class="{
                        'bg-[#145a3a] dark:bg-[#3aa876]': item.status === 'completed' && !item.completedLate,
                        'bg-error-500': (item.status === 'completed' && item.completedLate) || item.status === 'declined',
                        'bg-amber-500': item.status === 'on_hold',
                        'bg-blue-500': item.status === 'in_progress',
                        'bg-gray-200 dark:bg-gray-700': item.status === 'pending'
                    }">
                    <svg x-show="item.status === 'completed'" width="10" height="10" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="white" />
                    </svg>
                    <svg x-show="item.status === 'on_hold'" width="10" height="10" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 4V9.5M8 11.8H8.01" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg x-show="item.status === 'declined'" width="10" height="10" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 4L12 12M12 4L4 12" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg x-show="item.status === 'in_progress'" width="8" height="8" viewBox="0 0 8 8" fill="white" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="4" cy="4" r="4" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-gray-600 dark:text-gray-300" x-text="item.title"></span>
                        <span class="ml-auto shrink-0 font-medium"
                            :class="{
                                'text-[#145a3a] dark:text-[#3aa876]': item.status === 'completed' && !item.completedLate,
                                'text-error-600 dark:text-error-400': (item.status === 'completed' && item.completedLate) || item.status === 'declined',
                                'text-amber-600 dark:text-amber-400': item.status === 'on_hold',
                                'text-blue-600 dark:text-blue-400': item.status === 'in_progress',
                                'text-gray-400': item.status === 'pending'
                            }"
                            x-text="{ completed: 'Completed', on_hold: 'On Hold', declined: 'Declined', in_progress: 'In Progress', pending: 'Pending' }[item.status]"></span>
                    </div>
                    {{-- Never shown for 'pending' — `item.timestamp` is only ever
                         populated server-side for 'completed'/'on_hold'. --}}
                    <template x-if="item.timestamp">
                        <p class="mt-0.5 text-gray-400" x-text="item.timestamp"></p>
                    </template>
                    <template x-if="item.actorPrefix && item.actorName">
                        <p class="mt-0.5 text-gray-400" x-text="item.actorPrefix + ': ' + item.actorName"></p>
                    </template>
                    <template x-if="item.remark">
                        <p class="mt-0.5 text-gray-400">Remark: <span class="text-gray-500 dark:text-gray-300" x-text="item.remark"></span></p>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>
