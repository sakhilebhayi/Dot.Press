<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { font-family: 'Public Sans', 'DejaVu Sans', sans-serif; }
    .slide { position: relative; overflow: hidden; page-break-inside: avoid; }
</style>
</head>
<body>
@foreach ($pages as $page)
    @include('press.export.slide', ['page' => $page, 'w' => $w, 'h' => $h, 'last' => $loop->last])
@endforeach
</body>
</html>
