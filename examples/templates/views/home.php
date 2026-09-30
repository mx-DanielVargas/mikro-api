<!-- The extends directive below wraps this view's sections inside views/layout.php -->
@extends('layout')

@section('title')
Home - Templates Example
@endsection

@section('content')
    <h1>Escaped vs. Raw Output</h1>

    <!--
        The double-curly-brace output below is ESCAPED via htmlspecialchars().
        The "name" variable is intentionally set (in index.php) to a literal
        script tag string, so you can view-source this page and confirm it
        renders as literal text instead of executing as HTML/JS.
    -->
    <p>Hello, {{ $name }}!</p>

    <!--
        WARNING: the bang-bang output below prints its value WITHOUT escaping.
        NEVER pass unsanitized user input through that syntax — doing so is a
        direct XSS vulnerability. Only use it for trusted, server-generated
        HTML that never contains request input, query strings, or
        user-supplied data (e.g. markdown-to-HTML you rendered yourself).
    -->
    <p>{!! $rawHtml !!}</p>
@endsection
