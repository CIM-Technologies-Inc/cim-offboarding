@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="500"
        heading="Something Went Wrong"
        message="We're sorry, but we couldn't complete your request. The process may have taken too long or an unexpected error occurred. Please try again — if the problem continues, contact your administrator."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
