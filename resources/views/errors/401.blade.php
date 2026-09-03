@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="401"
        heading="Please Sign In"
        message="Your session isn't active, or you're not authorized to view this page. Please sign in to continue."
        :primary-label="null"
        secondary-label="Go to Sign In"
        :secondary-url="route('login')"
    />
@endsection
