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

    <div x-data="{ ...emailWorkspace(@js(session('success')), @js(session('error') ?? ($errors->any() ? $errors->first() : null))), activeTemplateTab: 'system' }"
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
                        Default System ({{ $systemTemplates->count() }})
                    </button>
                    <button type="button" @click="activeTemplateTab = 'customizable'"
                        class="border-b-2 pb-2.5 text-sm font-medium transition-colors"
                        :class="activeTemplateTab === 'customizable'
                            ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                            : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'">
                        Customizable ({{ $customTemplates->count() }})
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
                <form id="templateForm" method="POST"
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
                                removeRecipientEmployee(id) {
                                    this.recipientEmployeeIds = this.recipientEmployeeIds.filter((x) => x !== id);
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
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Recipients / CC
                            </label>
                            <p class="mb-2 text-xs text-gray-400">
                                These employees and groups are CC'd whenever this template is used to send an email, in addition to its normal recipient(s).
                            </p>

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
                                <div x-show="recipientDropdownOpen && filteredEmployees().length" x-cloak
                                    class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
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
                                schedules: @js($template?->schedules->map(fn ($s) => ['id' => $s->id, 'timing' => $s->timing, 'days' => $s->days, 'channel' => $s->channel])->values()->all() ?? []),
                                addingSchedule: false,
                                editingId: null,
                                draftTiming: 'before',
                                draftDays: 7,
                                draftChannel: 'email',
                                resetDraft() {
                                    this.addingSchedule = false;
                                    this.editingId = null;
                                    this.draftTiming = 'before';
                                    this.draftDays = 7;
                                    this.draftChannel = 'email';
                                },
                                addSchedule() {
                                    this.schedules.push({ id: this.nextLocalId--, timing: this.draftTiming, days: this.draftDays, channel: this.draftChannel });
                                    this.resetDraft();
                                },
                                startEdit(s) {
                                    this.addingSchedule = false;
                                    this.editingId = s.id;
                                    this.draftTiming = s.timing;
                                    this.draftDays = s.days;
                                    this.draftChannel = s.channel;
                                },
                                saveEdit() {
                                    const s = this.schedules.find((s) => s.id === this.editingId);
                                    if (s) {
                                        s.timing = this.draftTiming;
                                        s.days = this.draftDays;
                                        s.channel = this.draftChannel;
                                    }
                                    this.resetDraft();
                                },
                                removeSchedule(id) {
                                    this.schedules = this.schedules.filter((s) => s.id !== id);
                                    if (this.editingId === id) this.resetDraft();
                                },
                                sortedSchedules() {
                                    return [...this.schedules].sort((a, b) => {
                                        const offset = (s) => (s.timing === 'before' ? -s.days : s.days);
                                        return offset(a) - offset(b);
                                    });
                                },
                                scheduleLabel(s) {
                                    if (s.days === 0 || s.days === '0') return 'On Last Working Day';
                                    const unit = Number(s.days) === 1 ? 'day' : 'days';
                                    return s.days + ' ' + unit + ' ' + s.timing + ' Last Working Day';
                                },
                                channelLabel(channel) {
                                    return { email: 'Email', system: 'System Notification', email_and_system: 'Email and System Notification' }[channel] ?? channel;
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
                                    <!-- Connecting line running behind every node's icon, spanning the
                                         full height of the timeline. -->
                                    <div class="absolute top-4 bottom-4 left-4 w-0.5 -translate-x-1/2 bg-gray-300 dark:bg-gray-700"></div>

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

                                        <template x-if="!sortedSchedules().length">
                                            <div class="relative flex gap-4">
                                                <span class="relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-300 dark:bg-gray-700">
                                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M5 10H15" stroke="white" stroke-width="1.5" stroke-linecap="round" />
                                                    </svg>
                                                </span>
                                                <p class="min-w-0 pt-1.5 text-sm text-gray-400">No custom schedules configured yet.</p>
                                            </div>
                                        </template>

                                        <template x-for="s in sortedSchedules()" :key="s.id">
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
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Timing</label>
                                                        <select x-model="draftTiming"
                                                            class="h-9 w-full rounded-lg border border-gray-300 bg-transparent px-2 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                            <option value="before">Before Last Working Day</option>
                                                            <option value="after">After Last Working Day</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Number of Days</label>
                                                        <input type="number" min="0" step="1" x-model.number="draftDays"
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
                                Available variables:
                                <code>@{{approver_name}}</code>, <code>@{{employee_name}}</code>, <code>@{{employee_number}}</code>, <code>@{{checklist_name}}</code>, <code>@{{due_date}}</code>, <code>@{{offboarding_link}}</code>.
                                For an overdue-checklist template, also available:
                                <code>@{{department}}</code>, <code>@{{position}}</code>, <code>@{{days_overdue}}</code>, <code>@{{checklist_status}}</code>, <code>@{{pending_items}}</code> (a table of each checklist item's status and due date).
                                For a "Notify Approver" follow-up on a checklist (e.g. once it's ready for approval):
                                <code>@{{checklist_summary}}</code> (a table of each item's Checked By/Date/Remarks) and <code>@{{approve_button}}</code> (a one-click Approve button, valid whenever the recipient is that checklist's Clearance Signatory).
                                For the "Checklist Due Date Extended" notification (sent automatically once an Extend Due request is saved):
                                <code>@{{clearance_signatory_name}}</code>, <code>@{{original_due_date}}</code>, <code>@{{extension_days}}</code>, and <code>@{{extended_due_date}}</code>.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-200 pt-5 dark:border-gray-800">
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
