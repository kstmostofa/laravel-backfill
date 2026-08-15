@extends('backfill::shell')

@section('title', 'Run a task')

@section('body')
    {{-- ?task=order-refunds opens straight onto a task's form, so a link to
         "the thing you need to run" can be sent to whoever needs to run it. --}}
    @livewire('backfill-operator', ['task' => request('task')])
@endsection
