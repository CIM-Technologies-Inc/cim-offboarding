@extends('layouts.app')

@section('content')
  @if (session('success'))
    <div class="mb-4 rounded-lg border border-success-500 bg-success-50 px-4 py-3 text-sm text-success-600 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">
        {{ session('success') }}
    </div>
  @endif

  <div class="mb-4 flex items-center justify-end md:mb-6">
    <x-offboarding.new-request-modal :employees="$employeesNotOffboarded" />
  </div>

  <div class="grid grid-cols-12 gap-4 md:gap-6">
    <div class="col-span-12 space-y-6 xl:col-span-7">
      <x-offboarding.metrics
        :total-employees="$totalEmployees"
        :pending-count="$pendingCount"
        :in-progress-count="$inProgressCount"
        :completed-count="$completedCount"
      />
      <x-offboarding.monthly-trend :month-labels="$monthLabels" :initiated-series="$initiatedSeries" />
    </div>
    <div class="col-span-12 xl:col-span-5">
        <x-offboarding.completion-rate
          :completion-rate="$completionRate"
          :completed-this-month="$completedThisMonth"
          :due-this-month="$dueThisMonth"
          :pending-count="$pendingCount"
        />
    </div>

    <div class="col-span-12">
      <x-offboarding.status-trend :month-labels="$monthLabels" :initiated-series="$initiatedSeries" :completed-series="$completedSeries" />
    </div>

    <div class="col-span-12 xl:col-span-5">
      <x-offboarding.department-breakdown :departments="$departments" />
    </div>

    <div class="col-span-12 xl:col-span-7">
      <x-offboarding.recent-requests :requests="$recentRequests" />
    </div>
  </div>
@endsection
