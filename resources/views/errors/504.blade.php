@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="504"
        heading="This Is Taking Longer Than Expected"
        message="Your request took too long to process and timed out. It may not have completed — please check the record before trying again, to avoid submitting it twice."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
