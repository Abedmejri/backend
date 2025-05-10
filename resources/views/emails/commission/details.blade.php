<x-mail::message>
{{-- Optional: Header with Logo --}}
{{-- Replace 'YOUR_LOGO_URL' with the actual URL to your logo --}}
{{-- Ensure the logo is publicly accessible --}}
<div style="text-align: center; margin-bottom: 25px;">
    <img src="{{-- YOUR_LOGO_URL --}}" alt="{{ config('app.name') }} Logo" height="50" style="max-height: 50px; border: 0;">
</div>

{{-- Greeting --}}
# Hello {{ $recipientName }},

{{-- Main Introductory Body Content --}}
{{-- This panel helps the main message stand out --}}
<x-mail::panel>
{{ $bodyContent }}
</x-mail::panel>

{{-- Commission Details Section --}}
<x-mail::panel>
## Commission Details: {{ $commissionData->name }}

@if($commissionData->description)
**Description:**<br>
{{ $commissionData->description }}
@endif
{{-- Add other relevant commission fields here if needed --}}
{{-- e.g., **Status:** {{ $commissionData->status }} --}}
</x-mail::panel>

{{-- Associated Meetings Section --}}
@if($meetings && $meetings->count() > 0)
<x-mail::panel>
## Associated Meetings

@foreach($meetings as $meeting)
### {{ $meeting->title }}

*   **Date & Time:** {{ $meeting->date ? \Carbon\Carbon::parse($meeting->date)->format('F j, Y, g:i A T') : 'Not Scheduled' }} {{-- Example: January 1, 2024, 2:00 PM EST --}}
*   **Location:** {{ $meeting->location ?? 'To be determined' }}

{{-- Optional: Add a subtle divider between meetings if needed --}}
@if (!$loop->last)
<hr style="border: none; border-top: 1px solid #e8e5ef; margin: 20px 0;">
@endif

@endforeach
</x-mail::panel>
@else
{{-- Message if no meetings exist --}}
<x-mail::panel>
No meetings are currently scheduled or associated with this commission.
</x-mail::panel>
@endif

{{-- Call to Action Button --}}
{{-- 'primary' color usually maps to blue in the default theme --}}
{{-- Ensure your frontend URL is set in config/app.php or .env --}}
<x-mail::button :url="config('app.frontend_url', '#')" color="primary">
Visit Dashboard
</x-mail::button>

{{-- Closing --}}
<p style="margin-top: 20px;">
Regards,<br>
The {{ config('app.name') }} Team
</p>

{{-- Optional Subcopy / Footer --}}
{{-- Use this for less important info like unsubscribe links, addresses etc. --}}
<x-slot:subcopy>
If you're having trouble clicking the "Visit Dashboard" button, copy and paste the URL below into your web browser: [{{ config('app.frontend_url', '#') }}]({{ config('app.frontend_url', '#') }})
</x-slot:subcopy>

</x-mail::message>