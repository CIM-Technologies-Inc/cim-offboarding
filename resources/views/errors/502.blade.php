@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="502"
        heading="Service Temporarily Unavailable"
        message="We're having trouble reaching part of the system right now. This is usually temporary — please try again in a moment."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
