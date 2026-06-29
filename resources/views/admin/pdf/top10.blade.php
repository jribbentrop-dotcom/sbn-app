@extends('admin.pdf.partials._layout')

@section('pages')

@include('admin.pdf.partials._cover')

@include('admin.pdf.partials._theory')

@foreach($chords as $i => $c)
    @include('admin.pdf.partials._chord-item', ['c' => $c, 'i' => $i, 'total' => count($chords)])
@endforeach

@foreach($songs as $s)
    @include('admin.pdf.partials._song-example', ['s' => $s])
@endforeach

@endsection
