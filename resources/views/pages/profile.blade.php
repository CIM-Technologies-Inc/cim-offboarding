@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="User Profile" />

    {{-- Fires a toast for a flashed error — most relevantly, the redirect
         `ApprovalController`/`GeneralSignatoryApprovalController` send here
         when an approval was blocked for having no e-signature uploaded
         yet, so the reason is obvious the moment this page loads. --}}
    <div x-data="flashToast(null, @js(session('error')))"></div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
        <h3 class="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90 lg:mb-7">Profile</h3>

        @if (session('error'))
            <div class="mb-6 rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                {{ session('error') }}
            </div>
        @endif

        <x-profile.profile-card />

        {{-- The avatar/name header above always shows (basic identity) —
             everything below it is the actual profile DATA, gated behind
             "user-profile.view" as a whole. Each card's own individual
             edit controls are separately gated by their own more specific
             "user-profile.edit-*" permission regardless of this. --}}
        @can('user-profile.view')
            <x-profile.employment-info-card />
            <x-profile.personal-info-card />
            <x-profile.address-card />
            <x-profile.signature-card />
        @else
            <div class="mt-6 rounded-2xl border border-gray-200 p-5 text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400 lg:p-6">
                You don't have permission to view this section of your profile.
            </div>
        @endcan
    </div>
@endsection
