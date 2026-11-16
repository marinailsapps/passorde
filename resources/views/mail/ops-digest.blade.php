{{-- Plain-text mail: nothing here is HTML, so nothing is escaped. --}}
{!! __('Good morning,') !!}

{!! __('The daily check of :brand found the following points:', ['brand' => $brand]) !!}

@foreach ($sections as $section)
{!! $section['title'] !!}
@foreach ($section['lines'] as $line)
- {!! $line !!}
@endforeach

@endforeach
{!! __('Details: :url (and docs/RUNBOOK.md, "Daily digest").', ['url' => $appUrl]) !!}
