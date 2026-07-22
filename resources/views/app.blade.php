<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="antialiased">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'JobScope') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/main.tsx'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
