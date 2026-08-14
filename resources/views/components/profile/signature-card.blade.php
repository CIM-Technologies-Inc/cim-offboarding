@php
    $user = auth()->user();
@endphp
<div x-data="{ previewUrl: null, fileName: '' }" @open-signature-modal.window="previewUrl = null; fileName = ''">
    <div class="mt-6 rounded-2xl border border-gray-200 p-5 lg:p-6 dark:border-gray-800">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="w-full">
                <h4 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
                    Electronic Signature
                </h4>

                @if ($user->signature_path)
                    <div class="flex h-28 w-56 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/[0.03]">
                        <img src="{{ $user->signatureUrl() }}" alt="{{ $user->name }}'s signature" class="max-h-full max-w-full object-contain" />
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No signature uploaded yet.
                    </p>
                @endif
            </div>

            <div class="flex w-full shrink-0 flex-col gap-2 sm:flex-row lg:w-auto">
                <button type="button" @click="$dispatch('open-signature-modal')"
                    class="shadow-theme-xs flex w-full items-center justify-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-800 sm:w-auto dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-gray-200">
                    <svg class="fill-current" width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    {{ $user->signature_path ? 'Update Signature' : 'Upload E-Signature' }}
                </button>

                @if ($user->signature_path)
                    <form method="POST" action="{{ route('profile.signature.destroy') }}" x-data="{ confirmed: false }"
                        @submit="if (!confirmed) {
                            $event.preventDefault();
                            Swal.fire({
                                title: 'Remove your e-signature?',
                                text: 'You will need to upload a new one before it can be used again.',
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
                        }">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="shadow-theme-xs flex w-full items-center justify-center gap-2 rounded-full border border-error-300 bg-white px-4 py-3 text-sm font-medium text-error-600 hover:bg-error-50 sm:w-auto dark:border-error-500/30 dark:bg-gray-800 dark:text-error-400 dark:hover:bg-error-500/10">
                            Remove Signature
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <x-ui.modal @open-signature-modal.window="open = true" :isOpen="$errors->has('signature')" class="max-w-[560px]">
        <div class="no-scrollbar relative w-full max-w-[560px] overflow-y-auto rounded-3xl bg-white p-4 dark:bg-gray-900 lg:p-11">
            <div class="px-2 pr-14">
                <h4 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90">
                    {{ $user->signature_path ? 'Update Signature' : 'Upload E-Signature' }}
                </h4>
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400 lg:mb-7">
                    Upload a PNG or JPG image of your signature (max 2MB).
                </p>
            </div>
            <form class="flex flex-col" method="POST" action="{{ route('profile.signature.update') }}" enctype="multipart/form-data">
                @csrf
                <div class="px-2">
                    <label
                        class="flex h-40 w-full cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-2.5 text-center hover:bg-gray-100 dark:border-gray-700 dark:bg-white/[0.03] dark:hover:bg-white/5"
                        x-show="!previewUrl">
                        <svg class="mb-2 fill-current text-gray-400" width="28" height="28" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2.5a.75.75 0 01.75.75v8.19l2.72-2.72a.75.75 0 111.06 1.06l-4 4a.75.75 0 01-1.06 0l-4-4a.75.75 0 111.06-1.06l2.72 2.72V3.25A.75.75 0 0110 2.5z" fill="currentColor" />
                            <path d="M4.25 13.5a.75.75 0 01.75.75v1.5c0 .414.336.75.75.75h8.5a.75.75 0 00.75-.75v-1.5a.75.75 0 011.5 0v1.5A2.25 2.25 0 0114.25 18h-8.5A2.25 2.25 0 013.5 15.75v-1.5a.75.75 0 01.75-.75z" fill="currentColor" />
                        </svg>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Click to select an image</span>
                        <span class="mt-1 text-xs text-gray-400">PNG or JPG, up to 2MB</span>
                        <input type="file" name="signature" accept="image/png,image/jpeg,image/jpg" required class="sr-only"
                            @change="
                                const file = $event.target.files[0];
                                if (file) {
                                    fileName = file.name;
                                    previewUrl = URL.createObjectURL(file);
                                } else {
                                    previewUrl = null;
                                    fileName = '';
                                }
                            " />
                    </label>

                    <div x-show="previewUrl" class="rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex h-28 items-center justify-center rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <img :src="previewUrl" alt="Signature preview" class="max-h-full max-w-full object-contain" />
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <span class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="fileName"></span>
                            <button type="button" @click="previewUrl = null; fileName = ''; $el.closest('form').querySelector('input[type=file]').value = ''"
                                class="text-xs font-medium text-error-500 hover:text-error-600">
                                Choose a different file
                            </button>
                        </div>
                    </div>

                    @error('signature')
                        <p class="mt-2 text-xs text-error-500">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-center gap-3 px-2 mt-6 lg:justify-end">
                    <button @click="open = false" type="button"
                        class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                        Close
                    </button>
                    <button type="submit"
                        class="flex w-full justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 sm:w-auto">
                        Save Signature
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>
