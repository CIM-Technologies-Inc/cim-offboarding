@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="503"
        heading="Down for Maintenance"
        message="The application is temporarily unavailable, either for scheduled maintenance or because it's briefly under heavy load. Please check back shortly."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
