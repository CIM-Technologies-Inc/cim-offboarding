@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="404"
        heading="Page Not Found"
        message="The page or resource you're looking for doesn't exist, may have been moved, or the link you followed may be out of date."
        primary-label="Try Again"
        primary-action="reload"
        secondary-label="Back to Dashboard"
    />
@endsection
