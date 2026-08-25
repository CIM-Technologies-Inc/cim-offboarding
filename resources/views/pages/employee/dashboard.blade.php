@extends('layouts.app')

@section('content')
    <div x-data="flashToast(@js(session('success')), @js(session('error')))"></div>

    <x-common.page-breadcrumb pageTitle="My Dashboard" />

    @if (!$offboardingRequest)
        <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No offboarding request is on file for your account yet.
            </p>
        </div>
    @else
        <div class="space-y-6">
            <x-employee.overview :overview="$overview" />
            <x-employee.progress-stepper :stages="$stages" />
            <x-employee.checklist-status :checklists="$checklists" />
            <x-employee.timeline :timeline="$timeline" />
        </div>
    @endif
@endsection
