<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Shared entry point for every page's inline ApexCharts script
         (dashboard widgets, Reports). Those scripts sit in the body and run
         as soon as they're parsed, but `window.ApexCharts` is only set by
         the Vite bundle — a module script, which by spec runs AFTER the
         whole document is parsed. On any full page load (sign-in, refresh,
         an identity change) they used to find ApexCharts undefined and
         silently draw nothing; only Turbo navigations, where the bundle is
         already loaded, worked. A plain (non-module) inline script here runs
         before the body, so the helper always exists: it renders right away
         when ApexCharts is ready, otherwise on the first `turbo:load`
         (fired by the bundle after it has set ApexCharts). Each chart's
         element is cleared — and any instance this helper previously
         rendered there destroyed — first, so a script re-run (e.g. Turbo
         re-activating scripts when it restores a cached page) never stacks
         a second chart on top of the first. --}}
    <script>
        window.renderApexChart = function (selector, options) {
            var render = function () {
                var el = document.querySelector(selector);
                if (!el || typeof window.ApexCharts === 'undefined') return;
                if (el._apexChart) { try { el._apexChart.destroy(); } catch (e) {} }
                el.innerHTML = '';
                el._apexChart = new window.ApexCharts(el, options);
                el._apexChart.render();
            };
            if (typeof window.ApexCharts !== 'undefined') {
                render();
            } else {
                document.addEventListener('turbo:load', render, { once: true });
            }
        };
    </script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Who this page was rendered for: the signed-in user plus, during a
         "Login As User" session, the impersonating admin. The header and
         sidebar are `data-turbo-permanent` (kept as-is across Turbo
         navigations), so after an in-page identity change — e.g. the
         banner's "Return to My Account", a Turbo form submission — Turbo
         would otherwise swap only the page body and leave the previous
         account's name and menu in place, sending every sidebar click back
         into that account. `data-turbo-track="reload"` makes Turbo do a
         full page load whenever this value differs from the current page's,
         so identity-specific chrome is always rebuilt for the new account.
         Hashed so no raw user ids are exposed in the markup. --}}
    <meta name="app-identity" data-turbo-track="reload"
        content="{{ substr(hash_hmac('sha256', auth()->id().'|'.session('impersonator_id'), config('app.key')), 0, 16) }}">

    <title>{{ $title ?? 'Dashboard' }} | CIM Offboarding</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo/logo-icon.svg') }}">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Alpine.js -->
    {{-- <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script> --}}

    <!-- Theme Store -->
    @include('partials.theme-init')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('sidebar', {
                // Initialize based on screen size
                isExpanded: window.innerWidth >= 1280, // true for desktop, false for mobile
                isMobileOpen: false,
                isHovered: false,

                toggleExpanded() {
                    this.isExpanded = !this.isExpanded;
                    // When toggling desktop sidebar, ensure mobile menu is closed
                    this.isMobileOpen = false;
                },

                toggleMobileOpen() {
                    this.isMobileOpen = !this.isMobileOpen;
                    // Don't modify isExpanded when toggling mobile menu
                },

                setMobileOpen(val) {
                    this.isMobileOpen = val;
                },

                setHovered(val) {
                    // Only allow hover effects on desktop when sidebar is collapsed
                    if (window.innerWidth >= 1280 && !this.isExpanded) {
                        this.isHovered = val;
                    }
                }
            });
        });
    </script>
</head>

<body
    class="dark:bg-gray-900"
    data-inactivity-monitor="1"
    data-session-marker="{{ session()->getId() }}"
    x-data="{}"
    x-init="$store.sidebar.isExpanded = window.innerWidth >= 1280;
    const checkMobile = () => {
        if (window.innerWidth < 1280) {
            $store.sidebar.setMobileOpen(false);
            $store.sidebar.isExpanded = false;
        } else {
            $store.sidebar.isMobileOpen = false;
            $store.sidebar.isExpanded = true;
        }
    };
    window.addEventListener('resize', checkMobile);">

    {{-- preloader --}}
    <x-common.preloader/>
    {{-- preloader end --}}

    @if (session('impersonator_id'))
        <x-common.impersonation-banner />
    @endif

    <div class="min-h-screen xl:flex">
        @include('layouts.backdrop')
        @include('layouts.sidebar')

        <div class="flex-1 transition-all duration-300 ease-in-out"
            :class="{
                'xl:ml-[310px]': $store.sidebar.isExpanded || $store.sidebar.isHovered,
                'xl:ml-[90px]': !$store.sidebar.isExpanded && !$store.sidebar.isHovered,
                'ml-0': $store.sidebar.isMobileOpen
            }">
            <!-- app header start -->
            @include('layouts.app-header')
            <!-- app header end -->
            <div class="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"
                x-data="{ navigating: false }"
                @turbo:visit.window="navigating = true"
                @turbo:render.window="navigating = false">
                <div x-show="navigating" x-cloak>
                    <x-common.content-skeleton />
                </div>
                <div x-show="!navigating">
                    @yield('content')
                </div>
            </div>
        </div>

    </div>

</body>

@stack('scripts')

</html>
