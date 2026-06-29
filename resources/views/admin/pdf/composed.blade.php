@extends('admin.pdf.partials._layout')

@section('pages')

@foreach($pages as $pageType)
    @switch($pageType)

        @case('cover')
            @include('admin.pdf.partials._cover')
            @break

        @case('theory')
            @include('admin.pdf.partials._theory')
            @break

        @case('chords')
            @foreach($chords ?? [] as $i => $c)
                @include('admin.pdf.partials._chord-item', ['c' => $c, 'i' => $i, 'total' => count($chords ?? [])])
            @endforeach
            @break

        @case('songs')
            @foreach($songs ?? [] as $s)
                @include('admin.pdf.partials._song-example', ['s' => $s])
            @endforeach
            @break

    @endswitch
@endforeach

@endsection
