@props(['permissionGroups' => []])

<div x-data="{
        selected: null,
        checked: {},
        setSelected(detail) {
            const checked = {};
            (detail.permissionIds || []).forEach((id) => { checked[id] = true; });
            this.checked = checked;
            this.selected = detail;
        },
    }" @open-permissions-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-permissions-modal.window="open = true" :isOpen="false" class="w-full sm:w-[70vw] sm:max-w-[70vw] h-[60vh]">
        <div class="relative flex h-full w-full flex-col rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="flex h-full flex-col">
                    <div class="shrink-0">
                        <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                            Permissions — <span x-text="selected.name"></span>
                        </h4>
                        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                            Select which permissions this role has, grouped by module. Unchecking a permission removes
                            it from this role.
                        </p>
                    </div>

                    <form method="POST" :action="'/roles-permissions/' + selected.id + '/permissions'"
                        x-data="{ processing: false }" @submit="processing = true"
                        class="flex min-h-0 flex-1 flex-col">
                        @csrf
                        @method('PUT')

                        <div class="custom-scrollbar min-h-0 flex-1 space-y-5 overflow-y-auto pr-1">
                            @foreach ($permissionGroups as $group)
                                <div>
                                    <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $group['label'] }}</p>
                                    <div class="grid grid-cols-1 gap-2 rounded-lg border border-gray-100 p-3 dark:border-gray-800 sm:grid-cols-2">
                                        @foreach ($group['permissions'] as $permission)
                                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                                    x-model="checked[{{ $permission->id }}]"
                                                    class="h-4 w-4 accent-brand-500" />
                                                {{ \Illuminate\Support\Str::of(\Illuminate\Support\Str::after($permission->name, '.'))->replace('-', ' ')->title() }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex shrink-0 items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button>
                            <button type="submit" :disabled="processing" data-turbo-submits-with="Saving..."
                                :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Save Permissions
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
