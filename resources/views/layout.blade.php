@extends('backfill::shell')

@section('title', 'Backfills')

@section('body')
    @livewire('backfill-dashboard', ['selected' => request('selected')])
@endsection