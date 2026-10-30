
@php
    use App\Helpers\MenuHelper;
    $menuGroups = MenuHelper::getMenuGroups();

    // Get current path
    $currentPath = request()->path();

    // Each expandable module's position => its name. The sidebar remembers
    // open/closed state by NAME, since positions differ between roles'
    // menus (a hidden module shifts every later index).
    $submenuKeys = [];
    foreach ($menuGroups as $groupIndex => $menuGroup) {
        foreach ($menuGroup['items'] as $itemIndex => $item) {
            if (isset($item['subItems'])) {
                $submenuKeys["{$groupIndex}-{$itemIndex}"] = $item['name'];
            }
        }
    }
@endphp

{{-- Sidebar hover effect (dark-green theme). Kept in this scoped stylesheet
     because the app's Tailwind bundle is prebuilt. Applies to every module
     (category toggles, single links) and every submenu item: a soft green
     tint, green text/icon, a short slide to the right, and a green accent
     bar growing in on the left edge. Active/open items keep their own
     highlight and only deepen slightly. --}}
<style>
    #sidebar .menu-item,
    #sidebar .menu-dropdown-item {
        transition: background-color .2s ease, color .2s ease, transform .2s cubic-bezier(.2,.8,.2,1), box-shadow .2s ease;
    }
    #sidebar .menu-item::before,
    #sidebar .menu-dropdown-item::before {
        content: "";
        position: absolute;
        left: 0;
        top: 50%;
        width: 3px;
        height: 0;
        border-radius: 0 3px 3px 0;
        background: #145a3a;
        transform: translateY(-50%);
        transition: height .2s cubic-bezier(.2,.8,.2,1);
    }
    #sidebar .menu-item svg,
    #sidebar .menu-dropdown-item svg {
        transition: transform .2s cubic-bezier(.2,.8,.2,1), color .2s ease;
    }

    /* Inactive modules + submenu items */
    #sidebar .menu-item.menu-item-inactive:hover,
    #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover {
        background-color: rgba(20, 90, 58, .07);
        color: #145a3a;
        transform: translateX(3px);
        box-shadow: 0 2px 6px -3px rgba(20, 90, 58, .35);
    }
    #sidebar .menu-item.menu-item-inactive:hover::before,
    #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover::before { height: 60%; }
    #sidebar .menu-item.menu-item-inactive:hover .menu-item-icon-inactive,
    #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover .menu-item-icon-inactive,
    #sidebar .menu-item.menu-item-inactive:hover > svg { color: #145a3a; }
    #sidebar .menu-item.menu-item-inactive:hover .menu-item-icon-inactive svg,
    #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover .menu-item-icon-inactive svg { transform: scale(1.1); }

    /* Active page / open module: keep the highlight, deepen it a touch */
    #sidebar .menu-item.menu-item-active:hover,
    #sidebar .menu-dropdown-item.menu-dropdown-item-active:hover {
        background-color: rgba(20, 90, 58, .15);
        box-shadow: 0 2px 6px -3px rgba(20, 90, 58, .35);
    }

    .dark #sidebar .menu-item::before,
    .dark #sidebar .menu-dropdown-item::before { background: #3aa876; }
    .dark #sidebar .menu-item.menu-item-inactive:hover,
    .dark #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover {
        background-color: rgba(58, 168, 118, .12);
        color: #3aa876;
        box-shadow: 0 2px 8px -3px rgba(0, 0, 0, .6);
    }
    .dark #sidebar .menu-item.menu-item-inactive:hover .menu-item-icon-inactive,
    .dark #sidebar .menu-dropdown-item.menu-dropdown-item-inactive:hover .menu-item-icon-inactive,
    .dark #sidebar .menu-item.menu-item-inactive:hover > svg { color: #3aa876; }
    .dark #sidebar .menu-item.menu-item-active:hover,
    .dark #sidebar .menu-dropdown-item.menu-dropdown-item-active:hover { background-color: rgba(58, 168, 118, .22); }

    @media (prefers-reduced-motion: reduce) {
        #sidebar .menu-item, #sidebar .menu-dropdown-item, #sidebar .menu-item svg, #sidebar .menu-dropdown-item svg { transition: none; }
        #sidebar .menu-item:hover, #sidebar .menu-dropdown-item:hover, #sidebar .menu-item:hover svg, #sidebar .menu-dropdown-item:hover svg { transform: none !important; }
    }
</style>

<aside id="sidebar" data-turbo-permanent
    class="fixed flex flex-col mt-0 top-0 px-5 left-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-99999 border-r border-gray-200"
    x-data="{
        openSubmenus: {},
        navTick: 0,
        storageKey: 'cim.sidebar.openSubmenus',
        menuKeys: @js($submenuKeys),
        init() {
            // Restore every module the user left expanded/collapsed (survives
            // navigation and a full browser refresh), then make sure the
            // current page's module is visible.
            this.openSubmenus = this.loadSavedSubmenus();
            this.initializeActiveMenus();
        },
        loadSavedSubmenus() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey) || '{}');
                return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
            } catch (e) {
                return {};
            }
        },
        saveSubmenus() {
            try {
                localStorage.setItem(this.storageKey, JSON.stringify(this.openSubmenus));
            } catch (e) {
                // Storage unavailable (private mode etc.) — state still holds for this page.
            }
        },
        submenuKey(groupIndex, itemIndex) {
            const position = groupIndex + '-' + itemIndex;
            return this.menuKeys[position] || position;
        },
        initializeActiveMenus() {
            const currentPath = window.location.pathname.replace(/^\//, '');

            // The current page's module auto-expands ONLY if the user has
            // never chosen a state for it — an explicit collapse is
            // respected — and this never collapses any other module, so
            // every module the user left open stays open while navigating.
            @foreach ($menuGroups as $groupIndex => $menuGroup)
                @foreach ($menuGroup['items'] as $itemIndex => $item)
                    @if (isset($item['subItems']))
                        // Check if any submenu item matches current path
                        @foreach ($item['subItems'] as $subItem)
                            if (currentPath === '{{ ltrim($subItem['path'], '/') }}' ||
                                window.location.pathname === '{{ $subItem['path'] }}') {
                                const key = this.submenuKey({{ $groupIndex }}, {{ $itemIndex }});
                                if (!(key in this.openSubmenus)) this.openSubmenus[key] = true;
                            } @endforeach
            @endif
            @endforeach
            @endforeach
        },
        toggleSubmenu(groupIndex, itemIndex) {
            const key = this.submenuKey(groupIndex, itemIndex);

            // Independent multi-expand: toggle only this category's own
            // key, never touch any other open category's state. Only a
            // click here is ever saved as the user's preference.
            this.openSubmenus[key] = !this.openSubmenus[key];
            this.saveSubmenus();
        },
        isSubmenuOpen(groupIndex, itemIndex) {
            return this.openSubmenus[this.submenuKey(groupIndex, itemIndex)] || false;
        },
        isActive(path) {
            this.navTick;
            return window.location.pathname === path || window.location.pathname.replace(/^\//, '') === path.replace(/^\//, '');
        }
    }"
    @turbo:load.window="navTick++; initializeActiveMenus()"
    :class="{
        'w-[310px]': $store.sidebar.isExpanded || $store.sidebar.isMobileOpen || $store.sidebar.isHovered,
        'w-[90px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full xl:translate-x-0': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">
    <!-- Logo Section -->
    <div class="pt-8 pb-7 flex"
        :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ?
        'xl:justify-center' :
        'justify-center'">
        <a href="{{ MenuHelper::homePath() }}">
            <img x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                class="dark:hidden" src="{{ asset('images/logo/logo.svg') }}" alt="CIM OffBoarding" width="150" height="40" />
            <img x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                class="hidden dark:block" src="{{ asset('images/logo/logo-dark.svg') }}" alt="CIM OffBoarding" width="150"
                height="40" />
            <img x-show="!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen"
                src="{{ asset('images/logo/logo-icon.svg') }}" alt="CIM OffBoarding" width="40" height="40" />

        </a>
    </div>

    <!-- Navigation Menu -->
    <div class="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar">
        <nav class="mb-6">
            <div class="flex flex-col gap-4">
                @foreach ($menuGroups as $groupIndex => $menuGroup)
                    <div>
                        <!-- Menu Group Title -->
                        <h2 class="mb-4 text-xs uppercase flex leading-[20px] text-gray-400"
                            :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ?
                            'lg:justify-center' : 'justify-start'">
                            <template
                                x-if="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                                <span>{{ $menuGroup['title'] }}</span>
                            </template>
                            <template x-if="!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                  <path fill-rule="evenodd" clip-rule="evenodd" d="M5.99915 10.2451C6.96564 10.2451 7.74915 11.0286 7.74915 11.9951V12.0051C7.74915 12.9716 6.96564 13.7551 5.99915 13.7551C5.03265 13.7551 4.24915 12.9716 4.24915 12.0051V11.9951C4.24915 11.0286 5.03265 10.2451 5.99915 10.2451ZM17.9991 10.2451C18.9656 10.2451 19.7491 11.0286 19.7491 11.9951V12.0051C19.7491 12.9716 18.9656 13.7551 17.9991 13.7551C17.0326 13.7551 16.2491 12.9716 16.2491 12.0051V11.9951C16.2491 11.0286 17.0326 10.2451 17.9991 10.2451ZM13.7491 11.9951C13.7491 11.0286 12.9656 10.2451 11.9991 10.2451C11.0326 10.2451 10.2491 11.0286 10.2491 11.9951V12.0051C10.2491 12.9716 11.0326 13.7551 11.9991 13.7551C12.9656 13.7551 13.7491 12.9716 13.7491 12.0051V11.9951Z" fill="currentColor"/>
                                </svg>
                            </template>
                        </h2>

                        <!-- Menu Items -->
                        <ul class="flex flex-col gap-1">
                            @foreach ($menuGroup['items'] as $itemIndex => $item)
                                <li>
                                    @if (isset($item['subItems']))
                                        <!-- Menu Item with Submenu -->
                                        <button @click="toggleSubmenu({{ $groupIndex }}, {{ $itemIndex }})"
                                            class="menu-item group w-full"
                                            :class="[
                                                isSubmenuOpen({{ $groupIndex }}, {{ $itemIndex }}) ?
                                                'menu-item-active' : 'menu-item-inactive',
                                                !$store.sidebar.isExpanded && !$store.sidebar.isHovered ?
                                                'xl:justify-center' : 'xl:justify-start'
                                            ]">

                                            <!-- Icon -->
                                            <span class="shrink-0" :class="isSubmenuOpen({{ $groupIndex }}, {{ $itemIndex }}) ?
                                                    'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                                {!! MenuHelper::getIconSvg($item['icon']) !!}
                                            </span>

                                            <!-- Text -->
                                            <span
                                                x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                                                class="menu-item-text flex min-w-0 flex-1 items-center gap-2 overflow-hidden text-ellipsis whitespace-nowrap text-left">
                                                {{ $item['name'] }}
                                                @if (!empty($item['new']))
                                                    <span class="absolute right-10"
                                                        :class="isActive('{{ $item['path'] ?? '' }}') ?
                                                            'menu-dropdown-badge menu-dropdown-badge-active' :
                                                            'menu-dropdown-badge menu-dropdown-badge-inactive'">
                                                        new
                                                    </span>
                                                @endif
                                            </span>

                                            <!-- Chevron Down Icon -->
                                            <svg x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                                                class="ml-auto w-5 h-5 shrink-0 transition-transform duration-200"
                                                :class="{
                                                    'rotate-180 text-[#145a3a] dark:text-[#3aa876]': isSubmenuOpen({{ $groupIndex }},
                                                        {{ $itemIndex }})
                                                }"
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </button>

                                        <!-- Submenu -->
                                        <div x-show="isSubmenuOpen({{ $groupIndex }}, {{ $itemIndex }}) && ($store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen)"
                                            x-transition:enter="transition ease-out duration-200"
                                            x-transition:enter-start="opacity-0 -translate-y-1"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            x-transition:leave="transition ease-in duration-150"
                                            x-transition:leave-start="opacity-100 translate-y-0"
                                            x-transition:leave-end="opacity-0 -translate-y-1">
                                            <ul class="mt-2 space-y-1 ml-9">
                                                @foreach ($item['subItems'] as $subItem)
                                                    <li>
                                                        <a href="{{ $subItem['path'] }}" class="menu-dropdown-item"
                                                            @click="$store.sidebar.setMobileOpen(false)"
                                                            :class="isActive('{{ $subItem['path'] }}') ?
                                                                'menu-dropdown-item-active' :
                                                                'menu-dropdown-item-inactive'">
                                                            <span class="shrink-0"
                                                                :class="isActive('{{ $subItem['path'] }}') ?
                                                                    'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                                                {!! MenuHelper::getIconSvg($subItem['icon']) !!}
                                                            </span>
                                                            <span class="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap">{{ $subItem['name'] }}</span>
                                                            <span class="flex items-center gap-1 ml-auto">
                                                                @if (!empty($subItem['new']))
                                                                    <span
                                                                        :class="isActive('{{ $subItem['path'] }}') ?
                                                                            'menu-dropdown-badge menu-dropdown-badge-active' :
                                                                            'menu-dropdown-badge menu-dropdown-badge-inactive'">
                                                                        new
                                                                    </span>
                                                                @endif
                                                                @if (!empty($subItem['pro']))
                                                                    <span
                                                                        :class="isActive('{{ $subItem['path'] }}') ?
                                                                            'menu-dropdown-badge-pro menu-dropdown-badge-pro-active' :
                                                                            'menu-dropdown-badge-pro menu-dropdown-badge-pro-inactive'">
                                                                        pro
                                                                    </span>
                                                                @endif
                                                            </span>
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @else
                                        <!-- Simple Menu Item -->
                                        <a href="{{ $item['path'] }}" class="menu-item group"
                                            @click="$store.sidebar.setMobileOpen(false)"
                                            :class="[
                                                isActive('{{ $item['path'] }}') ? 'menu-item-active' :
                                                'menu-item-inactive',
                                                (!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ?
                                                'xl:justify-center' :
                                                'justify-start'
                                            ]">

                                            <!-- Icon -->
                                            <span
                                                :class="isActive('{{ $item['path'] }}') ? 'menu-item-icon-active' :
                                                    'menu-item-icon-inactive'">
                                                {!! MenuHelper::getIconSvg($item['icon']) !!}
                                            </span>

                                            <!-- Text -->
                                            <span
                                                x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen"
                                                class="menu-item-text flex items-center gap-2">
                                                {{ $item['name'] }}
                                                @if (!empty($item['new']))
                                                    <span
                                                        class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-brand-500 text-white">
                                                        new
                                                    </span>
                                                @endif
                                            </span>
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>

        <!-- Sidebar Widget -->
        <!-- <div x-data x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" x-transition class="mt-auto">
            @include('layouts.sidebar-widget')
        </div> -->

    </div>
</aside>
