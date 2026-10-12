{{--
    Retraction history — read-only, shown only for a Retracted request.
    Shared by both Card View and List View (@include'd from each) so the
    `status`/`cancelledByName`/`cancellationReason`/`cancelledAt` reactive
    fields and their display rules live in exactly one place.
--}}
@php
    $compact = $compact ?? false;
@endphp
@if ($compact)
    <template x-if="status === 'cancelled'">
        <p class="text-xs text-gray-400" @click.stop="">
            Retracted by
            <span class="font-medium text-gray-600 dark:text-gray-300" x-text="cancelledByName || '—'"></span>
            <template x-if="cancelledAt">
                <span x-text="'on ' + cancelledAt"></span>
            </template>
        </p>
    </template>
@else
    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]"
        x-show="status === 'cancelled'" @click.stop="">
        <div class="flex items-center justify-between text-xs">
            <span class="text-gray-400">Retracted By</span>
            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="cancelledByName || '—'"></span>
        </div>
        <div class="mt-1.5 text-xs">
            <span class="text-gray-400">Retraction Reason</span>
            <p class="mt-0.5 text-gray-600 dark:text-gray-300" x-text="cancellationReason || '—'"></p>
        </div>
        <template x-if="cancelledAt">
            <p class="mt-1.5 text-xs text-gray-400" x-text="'Retracted on ' + cancelledAt"></p>
        </template>
    </div>
@endif
