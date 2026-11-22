<x-mail::message>
# {{ $details['subject'] }}

{{ $details['message'] }}

<x-mail::button :url="$details['url']">
Open os-edventure-ro
</x-mail::button>

</x-mail::message>
