@extends('layouts.fullscreen-layout')

@section('content')
    <x-errors.page
        code="403"
        heading="Access Denied"
        message="You don't have permission to access this page or perform this action. If you believe this is a mistake, please contact your administrator."
        :primary-label="null"
        secondary-label="Back to Dashboard"
    />
@endsection
