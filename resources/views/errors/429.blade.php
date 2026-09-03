@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="429"
        heading="Too Many Requests"
        message="You've made too many requests in a short period of time. Please wait a moment before trying again."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
