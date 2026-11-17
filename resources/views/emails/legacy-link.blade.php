@component('mail::layout')
    {{-- Header --}}
    @slot('header')
        @component('mail::header', ['url' => ''])
            {{ $companyName }}
        @endcomponent
    @endslot

{{-- Body: Markdown, so these lines must not be indented --}}
Hello,

You asked for a new link to {{ $type }} **{{ $number }}** from {{ $companyName }}. The link in the earlier email no longer works, because links in emails sent before a security update were replaced.

@component('mail::button', ['url' => $url])
View {{ $type }}
@endcomponent

@if($expiresOn)
This link works until {{ $expiresOn }}.
@endif

If you did not ask for this, you can ignore this email.

    {{-- Footer --}}
    @slot('footer')
        @component('mail::footer')
            Powered by <a class="footer-link" href="https://invoiceshelf.com" target="_blank">InvoiceShelf</a>
        @endcomponent
    @endslot
@endcomponent
