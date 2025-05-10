@component('mail::message')
# Meeting Reminder: {{ $meeting->title }}

Hi {{ $recipientName }},

This is a reminder that you have a meeting scheduled soon:

**Title:** {{ $meeting->title }}
**Date & Time:** {{ \Carbon\Carbon::parse($meeting->date)->format('F j, Y, g:i A') }} ({{ \Carbon\Carbon::parse($meeting->date)->diffForHumans() }})
**Location:** {{ $meeting->location }}

@if(!empty($meeting->description))
**Description:**
{{ $meeting->description }}
@endif

Thanks,
{{ config('app.name') }}
@endcomponent