@extends('layouts.fullscreen-layout')

@section('content')
    {{-- $primaryAction is optionally passed in by whoever rendered this view
         directly (see `bootstrap/app.php`'s execution-timeout handling,
         which points this back at the page the timed-out form was
         submitted from) — a plain `abort(504)` elsewhere in the app never
         sets it, so this still defaults to a same-page reload exactly as
         before. --}}
    <x-errors.page
        code="504"
        heading="This Is Taking Longer Than Expected"
        message="Your request took too long to process and timed out. It may not have completed — please check the record before trying again, to avoid submitting it twice."
        primary-label="Try Again"
        :primary-action="$primaryAction ?? 'reload'"
        secondary-label="Back to Dashboard"
    />
@endsection
