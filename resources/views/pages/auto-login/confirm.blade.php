@extends('layouts.fullscreen-layout')

@section('content')
    <div class="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
        <div class="relative flex h-screen w-full flex-col justify-center sm:p-0 lg:flex-row dark:bg-gray-900">
            <!-- Form -->
            <div class="flex w-full flex-1 flex-col lg:w-1/2">
                <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center">
                    <div>
                        @if ($state === 'confirm')
                            <div class="mb-5 sm:mb-8">
                                <h1 class="text-title-sm sm:text-title-md mb-2 font-semibold text-gray-800 dark:text-white/90">
                                    Sign In to CIM Offboarding
                                </h1>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $message }}
                                </p>
                            </div>

                            <form method="POST" action="{{ $confirmUrl }}">
                                @csrf
                                <button type="submit" data-turbo-submits-with="Signing in..."
                                    class="flex w-full items-center justify-center rounded-lg bg-[#145a3a] px-4 py-3 text-sm font-medium text-white shadow-theme-xs transition hover:bg-[#0f4630]">
                                    Continue to CIM Offboarding
                                </button>
                            </form>
                        @else
                            <div class="mb-5 sm:mb-8">
                                <h1 class="text-title-sm sm:text-title-md mb-2 font-semibold text-gray-800 dark:text-white/90">
                                    @if ($state === 'already_different_session')
                                        Already Signed In
                                    @elseif ($state === 'blocked')
                                        Account Blocked
                                    @else
                                        Link Expired or Invalid
                                    @endif
                                </h1>
                            </div>

                            <div class="mb-6 rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                                {{ $message }}
                            </div>
                        @endif

                        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            <a href="{{ route('login') }}" class="font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400">
                                Go to Sign-In Page
                            </a>
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-brand-950 relative hidden h-full w-full items-center lg:grid lg:w-1/2 dark:bg-white/5">
                <div class="z-1 flex items-center justify-center">
                    <x-common.common-grid-shape/>
                    <div class="flex max-w-xs flex-col items-center">
                        <a href="/" class="block">
                            <img src="/images/logo/signin-logo.svg" alt="Logo" />
                        </a>
                        <p class="text-center text-gray-400 dark:text-white/60">
                            Welcome To Employee Offboarding Platform
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
