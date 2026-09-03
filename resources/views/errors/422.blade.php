@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="422"
        heading="Invalid Information"
        message="Some of the information submitted couldn't be processed. Please go back, check the form for errors, and try again."
        primary-label="Go Back"
        primary-action="back"
        secondary-label="Back to Dashboard"
    />
@endsection
