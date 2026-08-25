@php
    $employee = auth()->user()->employee;
@endphp

@if ($employee)
    <div>
        <div class="p-5 mb-6 border border-gray-200 rounded-2xl dark:border-gray-800 lg:p-6">
            <h4 class="text-lg font-semibold text-gray-800 dark:text-white/90 lg:mb-6">
                Employment Information
            </h4>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-7 2xl:gap-x-32">
                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Employee Number</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->employee_code }}</p>
                </div>

                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Full Name</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->name }}</p>
                </div>

                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Position</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->designation ?? 'Not set' }}</p>
                </div>

                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Department</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->department ?? 'Not set' }}</p>
                </div>

                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Email</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->email ?? 'Not set' }}</p>
                </div>

                <div>
                    <p class="mb-2 text-xs leading-normal text-gray-500 dark:text-gray-400">Date Hired</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $employee->date_of_joining?->format('M d, Y') ?? 'Not set' }}</p>
                </div>
            </div>

            <p class="mt-5 text-xs text-gray-400">
                Employment information is managed by HR and cannot be edited here.
            </p>
        </div>
    </div>
@endif
