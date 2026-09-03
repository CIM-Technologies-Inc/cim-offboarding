@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="419"
        heading="Session Expired"
        message="Your session has expired, most likely because this page was left open for a while. Please refresh the page and try your action again."
        primary-label="Refresh Page"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
