@extends('layouts.app')

@php
    use App\Http\Controllers\EmailTemplateController;

    $isSystemTemplate = $template?->isSystemDefault() ?? false;
@endphp

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Email and Notification</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Create and manage email templates used throughout the offboarding process. Default System templates are
            used automatically by the application and can't be renamed or deleted; their subject and body can still
            be customized. Customizable templates are freely managed.
        </p>
    </div>

    {{--
        The active tab starts on whichever one actually holds the template
        being edited, instead of always defaulting to Default System — a
        click on "Edit" is a full page navigation (see the plain `<a href>`
        in `partials/template-card.blade.php`), so `$template` is already
        known server-side by the time this renders, and a failed validation
        on Update redirects back to this same edit URL with `$template`
        still set, so this same derivation covers that case too with no
        extra state to thread through. Only a genuinely blank page (no
        template selected — the initial "New Template" state, or right
        after a successful Store/Update, which redirects to the create
        route) falls back to 'system', matching today's original default.
    --}}
    <div x-data="{ ...emailWorkspace(@js(session('success')), @js(session('error') ?? ($errors->any() ? $errors->first() : null)), @js($errors->any())), activeTemplateTab: '{{ $template ? ($isSystemTemplate ? 'system' : 'customizable') : 'system' }}' }"
        @open-view-template-modal.window="viewing = $event.detail">
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,1fr)]">
            <!-- LEFT (25%) -->
            <div class="h-fit rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="mb-3 flex items-center gap-6 border-b border-gray-200 dark:border-gray-800">
                    <button type="button" @click="activeTemplateTab = 'system'"
                        class="border-b-2 pb-2.5 text-sm font-medium transition-colors"
                        :class="activeTemplateTab === 'system'
                            ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'">
                        System Default ({{ $systemTemplates->count() }})
                    </button>
                    <button type="button" @click="activeTemplateTab = 'customizable'"
                        class="border-b-2 pb-2.5 text-sm font-medium transition-colors"
                        :class="activeTemplateTab === 'customizable'
                            ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'">
                        Customized ({{ $customTemplates->count() }})
                    </button>
                </div>

                <div x-show="activeTemplateTab === 'system'" x-cloak class="max-h-[70vh] space-y-2 overflow-y-auto custom-scrollbar">
                    @forelse ($systemTemplates as $item)
                        @include('pages.email-templates.partials.template-card', ['item' => $item, 'isSystem' => true])
                    @empty
                        <p class="px-1 py-6 text-center text-sm text-gray-400">No system templates yet.</p>
                    @endforelse
                </div>

                <div x-show="activeTemplateTab === 'customizable'" x-cloak class="max-h-[70vh] space-y-2 overflow-y-auto custom-scrollbar">
                    @forelse ($customTemplates as $item)
                        @include('pages.email-templates.partials.template-card', ['item' => $item, 'isSystem' => false])
                    @empty
                        <p class="px-1 py-6 text-center text-sm text-gray-400">No customizable templates yet.</p>
                    @endforelse
                </div>
            </div>

            <!-- CENTER (50%) -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <form id="templateForm" method="POST" autocomplete="off"
                    action="{{ $template ? route('email-templates.update', $template) : route('email-templates.store') }}">
                    @csrf
                    @if ($template)
                        @method('PUT')
                    @endif

                    <div class="grid grid-cols-1 gap-5">
                        @if ($isSystemTemplate)
                            <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" class="shrink-0">
                                    <path d="M10 6.66667V10.8333M10 13.3333H10.0083M18.3333 10C18.3333 14.6024 14.6024 18.3333 10 18.3333C5.39763 18.3333 1.66667 14.6024 1.66667 10C1.66667 5.39763 5.39763 1.66667 10 1.66667C14.6024 1.66667 18.3333 5.39763 18.3333 10Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                This is a Default System template — the application sends it automatically, so its name can't be changed. The subject and body below can still be customized.
                            </div>
                        @endif
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Template Name <span class="text-error-500">*</span>
                            </label>
                            <input type="text" name="template_name" required
                                value="{{ old('template_name', $template->template_name ?? '') }}"
                                @readonly($isSystemTemplate)
                                placeholder="e.g. Exit Interview Invitation"
                                class="h-11 w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30 {{ $isSystemTemplate ? 'cursor-not-allowed bg-gray-50 text-gray-500 dark:bg-white/[0.03] dark:text-gray-400' : 'bg-transparent text-gray-800 dark:bg-gray-900' }}" />
                        </div>

                        <div x-data="{
                                recipientEmployeeIds: @js(old('recipient_employee_ids', $template?->recipientEmployees->pluck('id')->all() ?? [])).map(String),
                                recipientGroupIds: @js(old('recipient_group_ids', $template?->recipientGroups->pluck('id')->all() ?? [])).map(String),
                                recipientQuery: '',
                                recipientDropdownOpen: false,
                                groupsExpanded: false,
                                allEmployees: @js($employees->map(fn ($e) => ['id' => (string) $e->id, 'name' => $e->name, 'code' => $e->employee_code, 'department' => $e->department])),
                                allGroups: @js($employeeGroups->map(fn ($g) => ['id' => (string) $g['id'], 'name' => $g['name'], 'employeeIds' => collect($g['employeeIds'])->map(fn ($id) => (string) $id)->all()])),
                                groupMemberIds() {
                                    return this.recipientGroupIds.flatMap((gid) => {
                                        const g = this.allGroups.find((g) => g.id === gid);
                                        return g ? g.employeeIds : [];
                                    });
                                },
                                filteredEmployees() {
                                    const q = this.recipientQuery.trim().toLowerCase();
                                    const covered = new Set([...this.recipientEmployeeIds, ...this.groupMemberIds()]);
                                    return this.allEmployees.filter((e) => !covered.has(e.id) &&
                                        (!q || e.name.toLowerCase().includes(q) || (e.code || '').toLowerCase().includes(q) || (e.department || '').toLowerCase().includes(q)));
                                },
                                addRecipientEmployee(id) {
                                    if (!this.recipientEmployeeIds.includes(id)) this.recipientEmployeeIds.push(id);
                                    this.recipientQuery = '';
                                    this.recipientDropdownOpen = false;
                                },
                                selectAllEmployees() {
                                    this.recipientEmployeeIds = this.allEmployees.map((e) => e.id);
                                    // Every group is now fully redundant since its members are
                                    // already covered individually, same don't-double-represent
                                    // rule toggleRecipientGroup() already applies the other way.
                                    this.recipientGroupIds = [];
                                    this.recipientQuery = '';
                                    this.recipientDropdownOpen = false;
                                },
                                removeRecipientEmployee(id) {
                                    this.recipientEmployeeIds = this.recipientEmployeeIds.filter((x) => x !== id);
                                },
                                deselectAllRecipients() {
                                    this.recipientEmployeeIds = [];
                                    this.recipientGroupIds = [];
                                },
                                toggleRecipientGroup(id) {
                                    if (this.recipientGroupIds.includes(id)) {
                                        this.recipientGroupIds = this.recipientGroupIds.filter((x) => x !== id);
                                        return;
                                    }
                                    this.recipientGroupIds.push(id);
                                    // Already-selected individuals who are members of this group are
                                    // redundant now that the whole group covers them.
                                    const g = this.allGroups.find((g) => g.id === id);
                                    if (g) this.recipientEmployeeIds = this.recipientEmployeeIds.filter((eid) => !g.employeeIds.includes(eid));
                                },
                                employeeLabel(id) {
                                    const e = this.allEmployees.find((e) => e.id === id);
                                    return e ? (e.name + (e.code ? ' (' + e.code + ')' : '')) : id;
                                },
                                groupLabel(id) {
                                    const g = this.allGroups.find((g) => g.id === id);
                                    return g ? g.name : id;
                                },
                            }">
                            <div class="mb-1.5 flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Recipients / CC
                                </label>
                                <button type="button" @click="deselectAllRecipients()"
                                    x-show="recipientEmployeeIds.length || recipientGroupIds.length" x-cloak
                                    class="text-xs font-medium text-gray-500 hover:text-error-600 dark:text-gray-400 dark:hover:text-error-400">
                                    Deselect All
                                </button>
                            </div>
                            <!-- <p class="mb-2 text-xs text-gray-400">
                                These employees and groups are CC'd whenever this template is used to send an email, in addition to its normal recipient(s).
                            </p> -->

                            <div class="mb-2 flex flex-wrap gap-1.5" x-show="recipientEmployeeIds.length || recipientGroupIds.length">
                                <template x-for="id in recipientEmployeeIds" :key="'emp-' + id">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                                        <span x-text="employeeLabel(id)"></span>
                                        <button type="button" @click="removeRecipientEmployee(id)" class="text-[#145a3a]/60 hover:text-[#145a3a] dark:text-[#3aa876]/60 dark:hover:text-[#3aa876]">&times;</button>
                                    </span>
                                </template>
                                <template x-for="id in recipientGroupIds" :key="'grp-' + id">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-400">
                                        <svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.3333 17.5V15.8333C13.3333 14.9493 12.9821 14.1014 12.357 13.4763C11.7319 12.8512 10.884 12.5 10 12.5H4.16667C3.28261 12.5 2.43477 12.8512 1.80964 13.4763C1.18452 14.1014 0.833333 14.9493 0.833333 15.8333V17.5M19.1667 17.5V15.8333C19.1661 15.0948 18.9204 14.3773 18.4679 13.7936C18.0154 13.2099 17.3819 12.793 16.6667 12.6M13.3333 2.60001C14.0504 2.79193 14.6859 3.20893 15.1396 3.79359C15.5932 4.37825 15.8393 5.09726 15.8393 5.83701C15.8393 6.57675 15.5932 7.29576 15.1396 7.88042C14.6859 8.46508 14.0504 8.88208 13.3333 9.07401M10 5.83334C10 7.67427 8.50762 9.16667 6.66667 9.16667C4.82572 9.16667 3.33334 7.67427 3.33334 5.83334C3.33334 3.99239 4.82572 2.50001 6.66667 2.50001C8.50762 2.50001 10 3.99239 10 5.83334Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                        <span x-text="groupLabel(id)"></span>
                                        <button type="button" @click="toggleRecipientGroup(id)" class="text-blue-700/60 hover:text-blue-700 dark:text-blue-400/60 dark:hover:text-blue-400">&times;</button>
                                    </span>
                                </template>
                            </div>

                            <div class="relative" @click.outside="recipientDropdownOpen = false">
                                <input type="text" x-model="recipientQuery" @focus="recipientDropdownOpen = true"
                                    placeholder="Search employees by name, number, or department..."
                                    class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                                <div x-show="recipientDropdownOpen && (filteredEmployees().length || recipientEmployeeIds.length < allEmployees.length)" x-cloak
                                    class="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                                    <template x-if="recipientEmployeeIds.length < allEmployees.length">
                                        <button type="button" @click="selectAllEmployees()"
                                            class="flex w-full items-center gap-2 border-b border-gray-100 px-4 py-2 text-left text-sm font-medium text-[#145a3a] hover:bg-gray-50 dark:border-gray-800 dark:text-[#3aa876] dark:hover:bg-white/5">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" class="shrink-0">
                                                <path d="M16.6667 5L7.50001 14.1667L3.33334 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <span>All <span class="font-normal text-gray-400">— select every employee</span></span>
                                        </button>
                                    </template>
                                    <template x-for="employee in filteredEmployees()" :key="employee.id">
                                        <button type="button" @click="addRecipientEmployee(employee.id)"
                                            class="flex w-full flex-col items-start px-4 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                            <span class="text-gray-800 dark:text-white/90" x-text="employee.name + (employee.code ? ' (' + employee.code + ')' : '')"></span>
                                            <span class="text-xs text-gray-400" x-text="employee.department"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <div class="mt-3">
                                <button type="button" @click="groupsExpanded = !groupsExpanded"
                                    class="flex items-center gap-1.5 text-sm font-medium text-gray-600 dark:text-gray-400">
                                    <span>Employee Master Groups</span>
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"
                                        class="shrink-0 transition-transform duration-200" :class="groupsExpanded ? 'rotate-180' : ''">
                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                                <div x-show="groupsExpanded" x-cloak
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-1"
                                    class="mt-1.5 max-h-40 space-y-1 overflow-y-auto rounded-xl border border-gray-200 p-2 dark:border-gray-800">
                                    <template x-for="group in allGroups" :key="group.id">
                                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                            <input type="checkbox" :checked="recipientGroupIds.includes(group.id)" @change="toggleRecipientGroup(group.id)"
                                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                            <span x-text="group.name"></span>
                                        </label>
                                    </template>
                                    <template x-if="!allGroups.length">
                                        <p class="px-2 py-1.5 text-xs text-gray-400">No active groups in Employee Master.</p>
                                    </template>
                                </div>
                            </div>

                            <template x-for="id in recipientEmployeeIds" :key="'emp-input-' + id">
                                <input type="hidden" name="recipient_employee_ids[]" :value="id" />
                            </template>
                            <template x-for="id in recipientGroupIds" :key="'grp-input-' + id">
                                <input type="hidden" name="recipient_group_ids[]" :value="id" />
                            </template>
                        </div>

                        <div x-data="{
                                timelineExpanded: false,
                                nextLocalId: -1,
                                schedules: @js($template?->schedules->map(fn ($s) => [
                                    'id' => $s->id, 'event' => $s->event, 'timing' => $s->timing, 'days' => $s->days,
                                    'clearanceSigningDeadlineDays' => $s->clearance_signing_deadline_days,
                                    'channel' => $s->channel,
                                    'checklistTemplateIds' => $s->checklistTemplates->pluck('id')->all(),
                                    'generalSignatoryIds' => $s->generalSignatories->pluck('id')->all(),
                                ])->values()->all() ?? []),
                                allChecklistTemplates: @js($activeChecklistTemplates->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])),
                                allGeneralSignatories: @js($activeGeneralSignatories->map(fn ($g) => [
                                    'id' => $g->id,
                                    'label' => ($g->clearanceSignatory?->name ?? 'Unassigned') . ($g->clearanceSignatory?->employee_code ? ' (' . $g->clearanceSignatory->employee_code . ')' : ''),
                                ])),
                                addingSchedule: false,
                                editingId: null,
                                draftEvent: 'last_working_day',
                                draftTiming: 'before',
                                draftDays: 7,
                                draftClearanceSigningDeadlineDays: 19,
                                draftChannel: 'email',
                                draftChecklistTemplateIds: [],
                                draftGeneralSignatoryIds: [],
                                draftError: '',
                                // Saved schedules (positive id) removed from the timeline —
                                // still submitted, flagged _destroy, so
                                // EmailTemplateController::syncSchedules() actually deletes
                                // them. Dropping them from the form instead would leave the
                                // saved row untouched and still sending.
                                removedSchedules: [],
                                resetDraft() {
                                    this.draftError = '';
                                    this.addingSchedule = false;
                                    this.editingId = null;
                                    this.draftEvent = 'last_working_day';
                                    this.draftTiming = 'before';
                                    this.draftDays = 7;
                                    this.draftClearanceSigningDeadlineDays = 19;
                                    this.draftChannel = 'email';
                                    this.draftChecklistTemplateIds = [];
                                    this.draftGeneralSignatoryIds = [];
                                },
                                // Mirrors the server-side rules on schedules.*: whole,
                                // non-negative days (and Clearance deadline), no
                                // N days Before Creation (ValidEmailTemplateScheduleTiming),
                                // and no exact duplicate of another schedule on this
                                // template (DistinctEmailTemplateSchedule::signature()).
                                validateDraft() {
                                    const isWhole = (v) => v !== '' && v !== null && Number.isInteger(Number(v)) && Number(v) >= 0;
                                    if (!isWhole(this.draftDays)) {
                                        return 'Number of Days must be a whole number of 0 or more.';
                                    }
                                    if (this.draftEvent === 'clearance' && !isWhole(this.draftClearanceSigningDeadlineDays)) {
                                        return 'Clearance Signing Deadline must be a whole number of 0 or more.';
                                    }
                                    if (this.draftEvent === 'creation' && this.draftTiming === 'before' && Number(this.draftDays) > 0) {
                                        return 'A Creation schedule can only be sent on or after the request is created — choose After.';
                                    }
                                    const draft = {
                                        event: this.draftEvent, timing: this.draftTiming, days: this.draftDays,
                                        clearanceSigningDeadlineDays: this.draftClearanceSigningDeadlineDays, channel: this.draftChannel,
                                        checklistTemplateIds: this.draftChecklistTemplateIds, generalSignatoryIds: this.draftGeneralSignatoryIds,
                                    };
                                    if (this.schedules.some((s) => s.id !== this.editingId && this.scheduleSignature(s) === this.scheduleSignature(draft))) {
                                        return 'This schedule duplicates another schedule on this template (same event, timing, days, channel and recipients).';
                                    }
                                    return '';
                                },
                                scheduleSignature(s) {
                                    const days = Number(s.days);
                                    const ids = (list) => [...new Set((list || []).map(Number))].sort((a, b) => a - b);
                                    return JSON.stringify([
                                        s.event, days === 0 ? 'on' : s.timing, days,
                                        s.event === 'clearance' ? Number(s.clearanceSigningDeadlineDays ?? 19) : null,
                                        s.channel, ids(s.checklistTemplateIds), ids(s.generalSignatoryIds),
                                    ]);
                                },
                                addSchedule() {
                                    this.draftError = this.validateDraft();
                                    if (this.draftError) return;
                                    this.schedules.push({
                                        id: this.nextLocalId--, event: this.draftEvent, timing: this.draftTiming, days: this.draftDays,
                                        clearanceSigningDeadlineDays: this.draftEvent === 'clearance' ? this.draftClearanceSigningDeadlineDays : null,
                                        channel: this.draftChannel,
                                        checklistTemplateIds: [...this.draftChecklistTemplateIds],
                                        generalSignatoryIds: [...this.draftGeneralSignatoryIds],
                                    });
                                    this.resetDraft();
                                },
                                startEdit(s) {
                                    this.addingSchedule = false;
                                    this.editingId = s.id;
                                    this.draftEvent = s.event;
                                    this.draftTiming = s.timing;
                                    this.draftDays = s.days;
                                    // Always loads whatever was actually saved — never
                                    // silently reset to 19, per spec: do not overwrite a
                                    // previously saved custom deadline when editing.
                                    // Falls back to 19 only when this schedule somehow has
                                    // none yet (e.g. a pre-this-feature row).
                                    this.draftClearanceSigningDeadlineDays = s.clearanceSigningDeadlineDays ?? 19;
                                    this.draftChannel = s.channel;
                                    this.draftChecklistTemplateIds = [...s.checklistTemplateIds];
                                    this.draftGeneralSignatoryIds = [...s.generalSignatoryIds];
                                },
                                saveEdit() {
                                    this.draftError = this.validateDraft();
                                    if (this.draftError) return;
                                    const s = this.schedules.find((s) => s.id === this.editingId);
                                    if (s) {
                                        s.event = this.draftEvent;
                                        s.timing = this.draftTiming;
                                        s.days = this.draftDays;
                                        s.clearanceSigningDeadlineDays = this.draftEvent === 'clearance' ? this.draftClearanceSigningDeadlineDays : null;
                                        s.channel = this.draftChannel;
                                        s.checklistTemplateIds = [...this.draftChecklistTemplateIds];
                                        s.generalSignatoryIds = [...this.draftGeneralSignatoryIds];
                                    }
                                    this.resetDraft();
                                },
                                removeSchedule(id) {
                                    const removed = this.schedules.find((s) => s.id === id);
                                    if (removed && removed.id > 0) this.removedSchedules.push(removed);
                                    this.schedules = this.schedules.filter((s) => s.id !== id);
                                    if (this.editingId === id) this.resetDraft();
                                },
                                toggleDraftChecklist(id) {
                                    this.draftChecklistTemplateIds = this.draftChecklistTemplateIds.includes(id)
                                        ? this.draftChecklistTemplateIds.filter((x) => x !== id)
                                        : [...this.draftChecklistTemplateIds, id];
                                },
                                toggleDraftGeneralSignatory(id) {
                                    this.draftGeneralSignatoryIds = this.draftGeneralSignatoryIds.includes(id)
                                        ? this.draftGeneralSignatoryIds.filter((x) => x !== id)
                                        : [...this.draftGeneralSignatoryIds, id];
                                },
                                // Master checkbox behavior: checked (every item already
                                // selected) -> unchecking it clears the list; otherwise
                                // (none or only some selected) -> checking it selects
                                // every item. Matches the Select All Pending My Approval
                                // checkbox pattern already used elsewhere on this
                                // checklist modal, just scoped to this one draft list.
                                allDraftChecklistsSelected() {
                                    return this.allChecklistTemplates.length > 0 && this.draftChecklistTemplateIds.length === this.allChecklistTemplates.length;
                                },
                                toggleAllDraftChecklists() {
                                    this.draftChecklistTemplateIds = this.allDraftChecklistsSelected() ? [] : this.allChecklistTemplates.map((c) => c.id);
                                },
                                allDraftGeneralSignatoriesSelected() {
                                    return this.allGeneralSignatories.length > 0 && this.draftGeneralSignatoryIds.length === this.allGeneralSignatories.length;
                                },
                                toggleAllDraftGeneralSignatories() {
                                    this.draftGeneralSignatoryIds = this.allDraftGeneralSignatoriesSelected() ? [] : this.allGeneralSignatories.map((g) => g.id);
                                },
                                // Mirrors EmailTemplateSchedule::EVENT_LABELS/label() exactly.
                                eventLabels: { creation: 'Creation', last_working_day: 'Last Working Day', clearance: 'Clearance', final_pay: 'Final Pay' },
                                scheduleLabel(s) {
                                    const eventLabel = this.eventLabels[s.event] ?? s.event;
                                    let base;
                                    if (Number(s.days) === 0) {
                                        base = 'On ' + eventLabel;
                                    } else {
                                        const unit = Number(s.days) === 1 ? 'day' : 'days';
                                        const timingLabel = s.timing === 'before' ? 'Before' : 'After';
                                        base = s.days + ' ' + unit + ' ' + timingLabel + ' ' + eventLabel;
                                    }
                                    if (s.event === 'clearance' && s.clearanceSigningDeadlineDays !== null && s.clearanceSigningDeadlineDays !== undefined) {
                                        base += ' (' + s.clearanceSigningDeadlineDays + ' days after Last Working Day)';
                                    }
                                    return base;
                                },
                                // Switching INTO Clearance seeds the deadline field with
                                // 19 only if it hasn't already been set to something this
                                // editing session (so re-selecting Clearance after
                                // switching away keeps whatever was there, never resets a
                                // value the admin already configured). Switching AWAY
                                // doesn't clear draftClearanceSigningDeadlineDays itself —
                                // it's simply not persisted for a non-Clearance event (see
                                // addSchedule()/saveEdit() above and
                                // EmailTemplateController::syncSchedules() server-side) —
                                // so switching back to Clearance later still shows it.
                                onDraftEventChange() {
                                    if (this.draftEvent === 'creation') {
                                        this.draftTiming = 'after';
                                    }
                                    if (this.draftEvent === 'clearance' && !this.draftClearanceSigningDeadlineDays && this.draftClearanceSigningDeadlineDays !== 0) {
                                        this.draftClearanceSigningDeadlineDays = 19;
                                    }
                                },
                                channelLabel(channel) {
                                    return { email: 'Email', system: 'System Notification', email_and_system: 'Email and System Notification' }[channel] ?? channel;
                                },
                                // Shows All applicable when neither is selected — matches
                                // resolveRecipients()'s own no-selection-means-everyone
                                // attached to the request default server-side.
                                scheduleRecipientSummary(s) {
                                    if (!s.checklistTemplateIds.length && !s.generalSignatoryIds.length) {
                                        return 'All applicable Clearance Signatories and Department Signatories';
                                    }
                                    const parts = [];
                                    if (s.checklistTemplateIds.length) {
                                        const names = s.checklistTemplateIds.map((id) => this.allChecklistTemplates.find((c) => c.id === id)?.title).filter(Boolean);
                                        parts.push('Checklists: ' + names.join(', '));
                                    }
                                    if (s.generalSignatoryIds.length) {
                                        parts.push('General Signatories: ' + s.generalSignatoryIds.length);
                                    }
                                    return parts.join(' · ');
                                },
                            }">
                            <button type="button" @click="timelineExpanded = !timelineExpanded"
                                class="flex items-center gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-400">
                                <span>Email Notification Schedule Timeline</span>
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"
                                    class="shrink-0 transition-transform duration-200" :class="timelineExpanded ? 'rotate-180' : ''">
                                    <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                            <div x-show="timelineExpanded" x-cloak
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 -translate-y-1"
                                class="mt-2 rounded-xl border border-gray-200 p-4 dark:border-gray-800">

                                <div class="relative">
                                    <div class="space-y-6">
                                        @if ($isSystemTemplate)
                                            @php
                                                $meta = EmailTemplateController::templateMetadataFor($template);
                                            @endphp
                                            <div class="relative flex gap-4">
                                                <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#145a3a] dark:bg-[#3aa876]">
                                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M11 2L4.5 11.5H9.5L8.5 18L15.5 8H10L11 2Z" fill="white" stroke="white" stroke-width="1" stroke-linejoin="round" />
                                                    </svg>
                                                </span>
                                                <div class="min-w-0 pt-1">
                                                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">Event-driven (read-only)</p>
                                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $meta['trigger'] }}</p>
                                                    <p class="mt-1 text-sm text-gray-400">Recipients: {{ $meta['recipients'] }}</p>
                                                </div>
                                            </div>
                                        @endif

                                        <div class="relative flex gap-4">
                                            <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#145a3a] dark:bg-[#3aa876]">
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <rect x="3" y="4.5" width="14" height="12" rx="1.5" stroke="white" stroke-width="1.3" />
                                                    <path d="M3 8H17" stroke="white" stroke-width="1.3" />
                                                    <path d="M6.5 2.5V5.5" stroke="white" stroke-width="1.3" stroke-linecap="round" />
                                                    <path d="M13.5 2.5V5.5" stroke="white" stroke-width="1.3" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0 pt-1">
                                                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Custom Schedules</p>
                                                <p class="mt-1 text-sm text-gray-400">
                                                    Applies automatically to every current and future eligible offboarding request, based on its Last Working Day.
                                                </p>
                                            </div>
                                        </div>

                                        <template x-if="!schedules.length">
                                            <div class="relative flex gap-4">
                                                <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-300 dark:bg-gray-700">
                                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M5 10H15" stroke="white" stroke-width="1.5" stroke-linecap="round" />
                                                    </svg>
                                                </span>
                                                <p class="min-w-0 pt-1.5 text-sm text-gray-400">No custom schedules configured yet.</p>
                                            </div>
                                        </template>

                                        <template x-for="s in schedules" :key="s.id">
                                            <div class="relative flex gap-4" x-show="editingId !== s.id">
                                                <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#145a3a] dark:bg-[#3aa876]">
                                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <circle cx="10" cy="10" r="7" stroke="white" stroke-width="1.3" />
                                                        <path d="M10 6V10L12.5 11.5" stroke="white" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>
                                                </span>
                                                <div class="flex min-w-0 flex-1 items-start justify-between gap-2 pt-1">
                                                    <div class="min-w-0">
                                                        <p class="text-sm text-gray-800 dark:text-white/90" x-text="scheduleLabel(s)"></p>
                                                        <p class="mt-1 text-sm text-gray-400" x-text="channelLabel(s.channel)"></p>
                                                        <p class="mt-1 text-xs text-gray-400" x-text="scheduleRecipientSummary(s)"></p>
                                                    </div>
                                                    <div class="flex shrink-0 gap-3 text-sm">
                                                        <button type="button" @click="startEdit(s)"
                                                            class="font-medium text-gray-600 hover:text-gray-800 hover:underline dark:text-gray-400 dark:hover:text-gray-200">
                                                            Edit
                                                        </button>
                                                        <button type="button" @click="removeSchedule(s.id)"
                                                            class="font-medium text-error-600 hover:text-error-700 hover:underline dark:text-error-400 dark:hover:text-error-300">
                                                            Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        <template x-for="s in schedules" :key="'sched-input-' + s.id">
                                            <span>
                                                <template x-if="s.id > 0">
                                                    <input type="hidden" :name="'schedules[' + s.id + '][id]'" :value="s.id" />
                                                </template>
                                                <input type="hidden" :name="'schedules[' + s.id + '][event]'" :value="s.event" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][timing]'" :value="s.timing" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][days]'" :value="s.days" />
                                                <template x-if="s.event === 'clearance'">
                                                    <input type="hidden" :name="'schedules[' + s.id + '][clearance_signing_deadline_days]'" :value="s.clearanceSigningDeadlineDays" />
                                                </template>
                                                <input type="hidden" :name="'schedules[' + s.id + '][channel]'" :value="s.channel" />
                                                <template x-for="cid in s.checklistTemplateIds" :key="'sched-' + s.id + '-checklist-' + cid">
                                                    <input type="hidden" :name="'schedules[' + s.id + '][checklist_template_ids][]'" :value="cid" />
                                                </template>
                                                <template x-for="gid in s.generalSignatoryIds" :key="'sched-' + s.id + '-gs-' + gid">
                                                    <input type="hidden" :name="'schedules[' + s.id + '][general_signatory_ids][]'" :value="gid" />
                                                </template>
                                            </span>
                                        </template>
                                        <template x-for="s in removedSchedules" :key="'sched-removed-' + s.id">
                                            <span>
                                                <input type="hidden" :name="'schedules[' + s.id + '][id]'" :value="s.id" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][_destroy]'" value="1" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][event]'" :value="s.event" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][timing]'" :value="s.timing" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][days]'" :value="s.days" />
                                                <input type="hidden" :name="'schedules[' + s.id + '][channel]'" :value="s.channel" />
                                            </span>
                                        </template>

                                        <div class="relative flex gap-4">
                                            <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#145a3a] dark:bg-[#3aa876]">
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M10 4V16M4 10H16" stroke="white" stroke-width="1.5" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                            <div class="min-w-0 flex-1 pt-1">
                                                <button type="button" x-show="!addingSchedule && editingId === null" @click="addingSchedule = true"
                                                    class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                    + Add Schedule
                                                </button>

                                                <div x-show="addingSchedule || editingId !== null" x-cloak class="grid grid-cols-3 gap-2 rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Number of Days</label>
                                                        <input type="number" min="0" step="1" x-model.number="draftDays"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Event</label>
                                                        <select x-model="draftEvent" @change="onDraftEventChange()"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                            <option value="creation">Creation</option>
                                                            <option value="last_working_day">Last Working Day</option>
                                                            <option value="clearance">Clearance</option>
                                                            <option value="final_pay">Final Pay</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Timing</label>
                                                        <select x-model="draftTiming"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                            <option value="before" :disabled="draftEvent === 'creation'">Before</option>
                                                            <option value="after">After</option>
                                                        </select>
                                                    </div>
                                                    <div x-show="draftEvent === 'clearance'" x-cloak class="col-span-3 sm:col-span-1">
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                                                            Clearance Signing Deadline (Days After LWD)
                                                        </label>
                                                        <input type="number" min="0" step="1" x-model.number="draftClearanceSigningDeadlineDays"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Channel</label>
                                                        <select x-model="draftChannel"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                            <option value="email">Email</option>
                                                            <option value="system">System Notification</option>
                                                            <option value="email_and_system">Email and System Notification</option>
                                                        </select>
                                                    </div>
                                                    <p x-show="draftError" x-cloak class="col-span-3 text-xs text-error-500" x-text="draftError"></p>
                                                    <p class="col-span-3 text-xs text-gray-400" x-text="scheduleLabel({ event: draftEvent, timing: draftTiming, days: draftDays, clearanceSigningDeadlineDays: draftEvent === 'clearance' ? draftClearanceSigningDeadlineDays : null })"></p>
                                                    <div class="col-span-3">
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                                                            Active Checklist Selection
                                                            <span class="font-normal text-gray-400">— leave empty to include every checklist attached to each request</span>
                                                        </label>
                                                        <p x-show="draftEvent === 'creation'" x-cloak class="mb-1 text-xs text-warning-600 dark:text-warning-400">
                                                            Note: a Secondary or Final Pay checklist isn't attached to a request yet at creation time — selecting one here means this schedule will find no recipient for it when it fires.
                                                        </p>
                                                        <div class="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-800">
                                                            <label class="flex cursor-pointer items-center gap-2 rounded-md border-b border-gray-100 px-1.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-800 dark:text-gray-300"
                                                                x-show="allChecklistTemplates.length">
                                                                <input type="checkbox" :checked="allDraftChecklistsSelected()" @change="toggleAllDraftChecklists()"
                                                                    x-effect="$el.indeterminate = draftChecklistTemplateIds.length > 0 && !allDraftChecklistsSelected()"
                                                                    class="h-3.5 w-3.5 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                                                <span>Select All</span>
                                                            </label>
                                                            <template x-for="c in allChecklistTemplates" :key="'draft-checklist-' + c.id">
                                                                <label class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                                                    <input type="checkbox" :checked="draftChecklistTemplateIds.includes(c.id)" @change="toggleDraftChecklist(c.id)"
                                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                                                    <span x-text="c.title"></span>
                                                                </label>
                                                            </template>
                                                            <template x-if="!allChecklistTemplates.length">
                                                                <p class="px-1.5 py-1 text-xs text-gray-400">No active checklists.</p>
                                                            </template>
                                                        </div>
                                                    </div>
                                                    <div class="col-span-3">
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                                                            Active Department Signatory Selection
                                                            <span class="font-normal text-gray-400">— leave empty to include every Department Signatory attached to each request</span>
                                                        </label>
                                                        <div class="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 dark:border-gray-800">
                                                            <label class="flex cursor-pointer items-center gap-2 rounded-md border-b border-gray-100 px-1.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-800 dark:text-gray-300"
                                                                x-show="allGeneralSignatories.length">
                                                                <input type="checkbox" :checked="allDraftGeneralSignatoriesSelected()" @change="toggleAllDraftGeneralSignatories()"
                                                                    x-effect="$el.indeterminate = draftGeneralSignatoryIds.length > 0 && !allDraftGeneralSignatoriesSelected()"
                                                                    class="h-3.5 w-3.5 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                                                <span>Select All</span>
                                                            </label>
                                                            <template x-for="g in allGeneralSignatories" :key="'draft-gs-' + g.id">
                                                                <label class="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                                                                    <input type="checkbox" :checked="draftGeneralSignatoryIds.includes(g.id)" @change="toggleDraftGeneralSignatory(g.id)"
                                                                        class="h-3.5 w-3.5 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                                                    <span x-text="g.label"></span>
                                                                </label>
                                                            </template>
                                                            <template x-if="!allGeneralSignatories.length">
                                                                <p class="px-1.5 py-1 text-xs text-gray-400">No active General Signatories.</p>
                                                            </template>
                                                        </div>
                                                    </div>
                                                    <div class="col-span-3 flex justify-end gap-2">
                                                        <button type="button" @click="resetDraft()"
                                                            class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                                                            Cancel
                                                        </button>
                                                        <button type="button" @click="editingId !== null ? saveEdit() : addSchedule()"
                                                            class="rounded-md bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#0f4630]">
                                                            Save Schedule
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Email Subject <span class="text-error-500">*</span>
                            </label>
                            <input type="text" name="subject" id="templateSubject" required
                                value="{{ old('subject', $template->subject ?? '') }}"
                                placeholder="e.g. Your Exit Interview is Scheduled"
                                class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                        {{-- "Default Template (Offboarding Announcement)" / "Default Template
                             (Account Reactivation)" checkboxes are hidden from this form per
                             request, but their saved values are preserved exactly as-is on every
                             save (never reset to unchecked) via these hidden inputs, so the live
                             Account Reactivation default keeps working unchanged. --}}
                        <input type="hidden" name="is_default_announcement" value="{{ old('is_default_announcement', $template?->is_default_announcement ? '1' : '0') }}" />
                        <input type="hidden" name="is_default_reactivation" value="{{ old('is_default_reactivation', $template?->is_default_reactivation ? '1' : '0') }}" />

                        <div>
                            <div class="mb-1.5 flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Email Body
                                </label>
                                <button type="button" @click="undoDraggedNames()"
                                    class="flex items-center gap-1 rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M7.5 4.16667L3.33333 8.33333L7.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M3.33333 8.33333H12.5C14.8012 8.33333 16.6667 10.1989 16.6667 12.5C16.6667 14.8012 14.8012 16.6667 12.5 16.6667H8.33333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    Undo
                                </button>
                            </div>
                            @php
                                $defaultBody = old('html_content', $template->html_content ?? '');
                                if ($defaultBody === '') {
                                    $defaultBody = '<p>Good day Mrs/Mr. approver,</p>'
                                        . '<p>Please be informed that you have pending for approval for offboardee.</p>'
                                        . '<p>CIM Offboarding link.</p>'
                                        . '<p>Thank you.</p>'
                                        . '<p>Best regards,</p>'
                                        . '<p>HR</p>';
                                    }
                            @endphp
                            <!-- Wrapper (not the textarea itself) carries the drop handler: Summernote
                                 hides the raw textarea and injects its own editable area next to it, so a
                                 handler bound to the textarea would never see the drop. -->
                            <div @dragover.prevent @drop.prevent="handleEmployeeDrop($event)">
                                <textarea name="html_content" id="templateBody" class="summernote" data-height="350">{{ $defaultBody }}</textarea>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-400">
                                Tip: drag an employee's name from the list on the right (or click Insert) to replace the nearest "approver", "offboardee", or "employee" placeholder. Click Undo to remove all dragged names and restore the placeholders.
                            </p>

                            <div x-data="{ variablesExpanded: false }" class="mt-2">
                                <button type="button" @click="variablesExpanded = !variablesExpanded"
                                    class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"
                                        class="shrink-0 transition-transform duration-200" :class="variablesExpanded ? 'rotate-180' : ''">
                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <span>Available Variables</span>
                                </button>

                                <div x-show="variablesExpanded" x-cloak
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 translate-y-0"
                                    x-transition:leave-end="opacity-0 -translate-y-1"
                                    class="mt-2 space-y-4 rounded-xl border border-gray-200 p-3 dark:border-gray-800">

                                    @php
                                        $brace = fn (string $name) => '{' . '{' . $name . '}' . '}';
                                        $variableGroups = [
                                            [
                                                'label' => 'General — available in every template',
                                                'items' => [
                                                    [$brace('approver_name'), "The name of whoever is receiving this email (the Clearance Signatory, Department Head, or other approver)."],
                                                    [$brace('employee_name'), "The name of the offboardee this email is about."],
                                                    [$brace('employee_number'), "The offboardee's employee ID/code."],
                                                    [$brace('checklist_name'), "The title of the checklist this email concerns."],
                                                    [$brace('due_date'), "The checklist's current due date."],
                                                    [$brace('offboarding_link'), "A clickable \"CIM Offboarding\" link to the sign-in page."],
                                                ],
                                            ],
                                            [
                                                'label' => 'Overdue-checklist template only',
                                                'items' => [
                                                    [$brace('department'), "The offboardee's department."],
                                                    [$brace('position'), "The offboardee's job position/designation."],
                                                    [$brace('days_overdue'), "How many days past the due date the checklist currently is."],
                                                    [$brace('checklist_status'), "The checklist's current status (e.g. Pending, Viewed)."],
                                                    [$brace('pending_items'), "An auto-generated table listing each checklist item's status and due date."],
                                                ],
                                            ],
                                            [
                                                'label' => '"Notify Approver" follow-up on a checklist (e.g. once it\'s ready for approval)',
                                                'items' => [
                                                    [$brace('checklist_summary'), "An auto-generated table of each checklist item's Checked By, Date, and Remarks."],
                                                    [$brace('approve_button'), "A one-click Approve button — only works when the recipient is that checklist's Clearance Signatory."],
                                                ],
                                            ],
                                            [
                                                'label' => '"Checklist Due Date Extended" notification (sent automatically once an Extend Due request is saved)',
                                                'items' => [
                                                    [$brace('clearance_signatory_name'), "The name of the Clearance Signatory whose checklist's due date was extended."],
                                                    [$brace('original_due_date'), "The due date before the extension."],
                                                    [$brace('extension_days'), "How many days the due date was extended by."],
                                                    [$brace('extended_due_date'), "The new due date after the extension."],
                                                ],
                                            ],
                                        ];
                                    @endphp

                                    @foreach ($variableGroups as $group)
                                        <div>
                                            <p class="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $group['label'] }}</p>
                                            <div class="space-y-1">
                                                @foreach ($group['items'] as [$variable, $description])
                                                    <div class="flex flex-col gap-0.5 rounded-lg px-2 py-1.5 sm:flex-row sm:items-baseline sm:gap-3 hover:bg-gray-50 dark:hover:bg-white/5">
                                                        <code class="shrink-0 rounded-md bg-[#145a3a]/10 px-1.5 py-0.5 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">{{ $variable }}</code>
                                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-200 pt-5 dark:border-gray-800">
                        @if ($template)
                            <button type="button" @click="cancelEdit()"
                                class="flex h-10 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                        @endif
                        <button type="button" @click="showPreview()"
                            class="flex h-10 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            Preview
                        </button>
                        <button type="submit" data-turbo-submits-with="Saving..."
                            class="flex h-10 items-center justify-center rounded-xl bg-[#145a3a] px-5 text-sm font-medium text-white shadow-sm hover:bg-[#0f4630]">
                            {{ $template ? 'Update Template' : 'Save Template' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT (25%) -->
            <div class="h-fit rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Employees</h4>
                <p class="mb-3 text-xs text-gray-400">Drag a name onto the email body, or click Insert.</p>

                <div class="max-h-[75vh] space-y-1 overflow-y-auto custom-scrollbar">
                    @forelse ($employees as $employee)
                        <div draggable="true"
                            @dragstart="$event.dataTransfer.setData('text/plain', @js($employee->name))"
                            class="flex cursor-grab items-center justify-between gap-2 rounded-lg px-3 py-2 hover:bg-gray-50 active:cursor-grabbing dark:hover:bg-white/5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">{{ $employee->name }}</p>
                                @if ($employee->department)
                                    <p class="truncate text-xs text-gray-400">{{ $employee->department }}</p>
                                @endif
                            </div>
                            <button type="button" @click="insertEmployeeName(@js($employee->name))"
                                class="shrink-0 rounded-md bg-[#145a3a]/10 px-2 py-1 text-[11px] font-medium text-[#145a3a] hover:bg-[#145a3a]/20 dark:text-[#3aa876]">
                                Insert
                            </button>
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-sm text-gray-400">No employees found.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- VIEW TEMPLATE MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-view-template-modal.window="open = true" :isOpen="false" class="max-w-[640px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[640px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="viewing" x-cloak>
                <template x-if="viewing">
                    <div>
                        <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="viewing.name"></h4>
                        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                            Subject: <span x-text="viewing.subject"></span>
                        </p>

                        <div class="rounded-xl border border-gray-200 bg-[#F8F9FA] p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="email-preview-body text-sm text-gray-700 dark:text-gray-300" x-html="viewing.body"></div>
                        </div>

                        <div class="mt-6 flex justify-end">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>

        <!-- PREVIEW MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-preview-modal.window="open = true" :isOpen="false" class="max-w-[640px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[640px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="open" x-cloak>
                <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">Email Preview</h4>
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">This is how the current, unsaved content will look.</p>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="bg-[#F8F9FA] px-5 py-4 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-400">Subject</p>
                        <p class="mt-0.5 text-base font-semibold text-gray-800 dark:text-white/90" x-text="previewSubject || '(no subject)'"></p>
                    </div>
                    <div class="email-preview-body min-h-[150px] p-6 text-sm text-gray-700 dark:text-gray-300" x-html="previewHtml"></div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button @click="open = false" type="button"
                        class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        Close
                    </button>
                </div>
            </div>
        </x-ui.modal>
    </div>
@endsection
