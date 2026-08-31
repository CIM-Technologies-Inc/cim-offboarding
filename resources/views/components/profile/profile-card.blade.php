@php
    $user = auth()->user();
@endphp
<div x-data="{ photoPreviewUrl: null, photoFileName: '' }" @open-profile-photo-modal.window="photoPreviewUrl = null; photoFileName = ''">
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-success-500 bg-success-50 px-4 py-3 text-sm text-success-600 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">
            {{ session('success') }}
        </div>
    @endif
    <div class="mb-6 rounded-2xl border border-gray-200 p-5 lg:p-6 dark:border-gray-800">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex w-full flex-col items-center gap-6 xl:flex-row">
                @can('user-profile.edit-photo')
                    <button type="button" title="Upload Profile Photo" @click="$dispatch('open-profile-photo-modal')"
                        class="group relative h-20 w-20 shrink-0 rounded-full">
                        <div class="h-20 w-20 overflow-hidden rounded-full border border-gray-200 dark:border-gray-800">
                            <img src="{{ $user->profilePhotoUrl() }}" alt="{{ $user->name }}" class="h-full w-full object-cover" />
                        </div>
                        <div class="absolute inset-0 flex items-center justify-center rounded-full bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
                            <svg width="20" height="20" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 3.75V14.25M3.75 9H14.25" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                    </button>
                @else
                    <div class="h-20 w-20 shrink-0 overflow-hidden rounded-full border border-gray-200 dark:border-gray-800">
                        <img src="{{ $user->profilePhotoUrl() }}" alt="{{ $user->name }}" class="h-full w-full object-cover" />
                    </div>
                @endcan
                <div class="order-3 xl:order-2">
                    <h4 class="mb-2 text-center text-lg font-semibold text-gray-800 xl:text-left dark:text-white/90">
                        {{ $user->name }}
                    </h4>
                    <div class="flex flex-col items-center gap-1 text-center xl:flex-row xl:gap-3 xl:text-left">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $user->position ?? $user->employee?->designation ?? 'Not set' }}
                        </p>
                        <div class="hidden h-3.5 w-px bg-gray-300 xl:block dark:bg-gray-700"></div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $user->department ?? $user->employee?->department ?? 'Not set' }}
                        </p>
                    </div>
                </div>
            </div>

            @canany(['user-profile.edit-personal-info', 'user-profile.edit-contact-info', 'user-profile.edit-employment-info'])
                <button @click="$dispatch('open-profile-info-modal')"
                    class="shadow-theme-xs flex w-full items-center justify-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-800 lg:inline-flex lg:w-auto dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-gray-200">
                    <svg class="fill-current" width="18" height="18" viewBox="0 0 18 18" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M15.0911 2.78206C14.2125 1.90338 12.7878 1.90338 11.9092 2.78206L4.57524 10.116C4.26682 10.4244 4.0547 10.8158 3.96468 11.2426L3.31231 14.3352C3.25997 14.5833 3.33653 14.841 3.51583 15.0203C3.69512 15.1996 3.95286 15.2761 4.20096 15.2238L7.29355 14.5714C7.72031 14.4814 8.11172 14.2693 8.42013 13.9609L15.7541 6.62695C16.6327 5.74827 16.6327 4.32365 15.7541 3.44497L15.0911 2.78206ZM12.9698 3.84272C13.2627 3.54982 13.7376 3.54982 14.0305 3.84272L14.6934 4.50563C14.9863 4.79852 14.9863 5.2734 14.6934 5.56629L14.044 6.21573L12.3204 4.49215L12.9698 3.84272ZM11.2597 5.55281L5.6359 11.1766C5.53309 11.2794 5.46238 11.4099 5.43238 11.5522L5.01758 13.5185L6.98394 13.1037C7.1262 13.0737 7.25666 13.003 7.35947 12.9002L12.9833 7.27639L11.2597 5.55281Z"
                            fill="" />
                    </svg>
                    Edit
                </button>
            @endcanany
        </div>
    </div>

    <!-- Profile Info Modal -->
    {{-- Gated the same as the trigger buttons above (this one and the
         matching one in personal-info-card.blade.php) — without this, the
         modal would still sit reachable in the DOM via a manually
         dispatched `open-profile-info-modal` browser event even with
         every trigger hidden. Each individual field inside is still
         separately disabled/enabled below regardless. --}}
    @canany(['user-profile.edit-personal-info', 'user-profile.edit-contact-info', 'user-profile.edit-employment-info'])
    <x-ui.modal x-data="{ open: false }" @open-profile-info-modal.window="open = true"
        :isOpen="$errors->hasAny(['name', 'email', 'mobile_number', 'position', 'department'])" class="max-w-[700px]">
        <div
            class="no-scrollbar relative w-full max-w-[700px] overflow-y-auto rounded-3xl bg-white p-4 dark:bg-gray-900 lg:p-11">
            <div class="px-2 pr-14">
                <h4 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90">
                    Edit Personal Information
                </h4>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400 lg:mb-7">
                    Update your details to keep your profile up-to-date.
                </p>
            </div>
            <form class="flex flex-col" method="POST" action="{{ route('profile.personal-info.update') }}">
                @csrf
                @method('PATCH')
                <div class="custom-scrollbar overflow-y-auto p-2">
                    <div>
                        <h5 class="mb-5 text-lg font-medium text-gray-800 dark:text-white/90 lg:mb-6">
                            Personal Information
                        </h5>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-5 lg:grid-cols-2">
                            @php
                                $disabledFieldClass = 'cursor-not-allowed bg-gray-100 dark:bg-gray-800';
                                $canEditPersonal = $user->can('user-profile.edit-personal-info');
                                $canEditContact = $user->can('user-profile.edit-contact-info');
                                $canEditEmployment = $user->can('user-profile.edit-employment-info');
                            @endphp
                            <div class="col-span-2 lg:col-span-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Full Name
                                </label>
                                <input type="text" name="name" value="{{ old('name', $user->name) }}" @disabled(! $canEditPersonal)
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 {{ $canEditPersonal ? '' : $disabledFieldClass }}" />
                                @error('name')
                                    <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-span-2 lg:col-span-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Email Address
                                </label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}" @disabled(! $canEditContact)
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 {{ $canEditContact ? '' : $disabledFieldClass }}" />
                                @error('email')
                                    <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-span-2 lg:col-span-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Mobile Number
                                </label>
                                <input type="text" name="mobile_number" value="{{ old('mobile_number', $user->mobile_number) }}" @disabled(! $canEditContact)
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 {{ $canEditContact ? '' : $disabledFieldClass }}" />
                                @error('mobile_number')
                                    <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-span-2 lg:col-span-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Current Position
                                </label>
                                <input type="text" name="position" value="{{ old('position', $user->position ?? $user->employee?->designation) }}" @disabled(! $canEditEmployment)
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 {{ $canEditEmployment ? '' : $disabledFieldClass }}" />
                                @error('position')
                                    <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="col-span-2 lg:col-span-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Current Department
                                </label>
                                <input type="text" name="department" value="{{ old('department', $user->department ?? $user->employee?->department) }}" @disabled(! $canEditEmployment)
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800 {{ $canEditEmployment ? '' : $disabledFieldClass }}" />
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 px-2 mt-6 lg:justify-end">
                    <button @click="open = false" type="button"
                        class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                        Close
                    </button>
                    <button type="submit"
                        class="flex w-full justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 sm:w-auto">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
    @endcanany

    <!-- Upload Profile Photo Modal -->
    {{-- Gated the same as the avatar's own click-to-upload trigger above —
         without this, the modal would still sit reachable via a manually
         dispatched `open-profile-photo-modal` browser event even with the
         avatar reverted to a plain, non-interactive image. --}}
    @can('user-profile.edit-photo')
    <x-ui.modal @open-profile-photo-modal.window="open = true" :isOpen="$errors->has('profile_photo')" class="max-w-[560px]">
        <div class="no-scrollbar relative w-full max-w-[560px] overflow-y-auto rounded-3xl bg-white p-4 dark:bg-gray-900 lg:p-11">
            <div class="px-2 pr-14">
                <h4 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90">
                    {{ $user->profile_photo_path ? 'Update Profile Photo' : 'Upload Profile Photo' }}
                </h4>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400 lg:mb-7">
                    Upload a PNG or JPG image (max 2MB).
                </p>
            </div>
            <form id="profilePhotoForm" class="flex flex-col" method="POST" action="{{ route('profile.photo.update') }}" enctype="multipart/form-data">
                @csrf
                <div class="px-2">
                    <label
                        class="flex h-40 w-full cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-2.5 text-center hover:bg-gray-100 dark:border-gray-700 dark:bg-white/[0.03] dark:hover:bg-white/5"
                        x-show="!photoPreviewUrl">
                        <svg class="mb-2 fill-current text-gray-400" width="28" height="28" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2.5a.75.75 0 01.75.75v8.19l2.72-2.72a.75.75 0 111.06 1.06l-4 4a.75.75 0 01-1.06 0l-4-4a.75.75 0 111.06-1.06l2.72 2.72V3.25A.75.75 0 0110 2.5z" fill="currentColor" />
                            <path d="M4.25 13.5a.75.75 0 01.75.75v1.5c0 .414.336.75.75.75h8.5a.75.75 0 00.75-.75v-1.5a.75.75 0 011.5 0v1.5A2.25 2.25 0 0114.25 18h-8.5A2.25 2.25 0 013.5 15.75v-1.5a.75.75 0 01.75-.75z" fill="currentColor" />
                        </svg>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Click to select an image</span>
                        <span class="mt-1 text-xs text-gray-400">PNG or JPG, up to 2MB</span>
                        <input type="file" name="profile_photo" accept="image/png,image/jpeg,image/jpg" required class="sr-only"
                            @change="
                                const file = $event.target.files[0];
                                if (file) {
                                    photoFileName = file.name;
                                    photoPreviewUrl = URL.createObjectURL(file);
                                } else {
                                    photoPreviewUrl = null;
                                    photoFileName = '';
                                }
                            " />
                    </label>

                    <div x-show="photoPreviewUrl" class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex h-28 items-center justify-center rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <img :src="photoPreviewUrl" alt="Profile photo preview" class="h-full w-auto rounded-full object-cover" />
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="photoFileName"></span>
                            <button type="button" @click="photoPreviewUrl = null; photoFileName = ''; $el.closest('form').querySelector('input[type=file]').value = ''"
                                class="text-xs font-medium text-error-500 hover:text-error-600">
                                Choose a different file
                            </button>
                        </div>
                    </div>

                    @error('profile_photo')
                        <p class="mt-2 text-xs text-error-500">{{ $message }}</p>
                    @enderror
                </div>
            </form>

            {{-- Deliberately OUTSIDE `#profilePhotoForm` — a `<form>` can't be
                 nested inside another. "Save Photo" is associated with that
                 form via the `form="profilePhotoForm"` attribute instead,
                 same net effect as if it were inside it. --}}
            <div class="flex items-center gap-3 px-2 mt-6 lg:justify-end">
                @if ($user->profile_photo_path)
                    <form method="POST" action="{{ route('profile.photo.destroy') }}" x-data="{ confirmed: false }"
                        @submit="if (!confirmed) {
                            $event.preventDefault();
                            Swal.fire({
                                title: 'Remove your profile photo?',
                                text: 'Your avatar will revert to the default image.',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonText: 'Remove',
                                cancelButtonText: 'Cancel',
                                confirmButtonColor: '#dc2626',
                                cancelButtonColor: '#6b7280',
                                reverseButtons: true
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    confirmed = true;
                                    $el.requestSubmit();
                                }
                            });
                        }" class="w-full sm:w-auto">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="shadow-theme-xs flex w-full items-center justify-center gap-2 rounded-full border border-error-300 bg-white px-4 py-2.5 text-sm font-medium text-error-600 hover:bg-error-50 sm:w-auto dark:border-error-500/30 dark:bg-gray-800 dark:text-error-400 dark:hover:bg-error-500/10">
                            Remove Photo
                        </button>
                    </form>
                @endif
                <button @click="open = false" type="button"
                    class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                    Close
                </button>
                <button type="submit" form="profilePhotoForm" x-show="photoPreviewUrl"
                    class="flex w-full justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 sm:w-auto">
                    Save Photo
                </button>
            </div>
        </div>
    </x-ui.modal>
    @endcan
</div>
