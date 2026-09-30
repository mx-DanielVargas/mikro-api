<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- The yield directive below renders the title section defined by the extending view -->
    <title>@yield('title')</title>
</head>
<body>
    <!-- The include directive below pulls in views/partials/nav.php (dot notation) -->
    @include('partials.nav')

    <main>
        <!-- The yield directive below renders the content section defined by the extending view -->
        @yield('content')
    </main>
</body>
</html>
