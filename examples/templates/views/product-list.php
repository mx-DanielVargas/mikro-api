<!-- The extends directive below wraps this view's sections inside views/layout.php -->
@extends('layout')

@section('title')
Products - Templates Example
@endsection

@section('content')
    <h1>Product List</h1>

    <ul>
        <!-- The foreach directive below iterates the products array passed to Response::render() -->
        @foreach($products as $product)
            <li>
                {{ $product['name'] }} &mdash;
                <!-- The if/else directives below conditionally render based on stock level -->
                @if($product['stock'] > 0)
                    In Stock ({{ $product['stock'] }})
                @else
                    Out of Stock
                @endif
            </li>
        @endforeach
    </ul>
@endsection
