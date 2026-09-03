@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="400"
        heading="Invalid Request"
        message="We couldn't understand that request. Please go back and try again."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
