<div id="s{{ $page['anchor'] }}" class="slide" style="width: {{ $w }}px; height: {{ $h - 1 }}px;{{ $last ? '' : ' page-break-after: always;' }}">
    {!! $page['bg'] !!}
    {!! $page['body'] !!}
</div>
