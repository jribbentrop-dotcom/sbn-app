<x-mail::message>
# New contact form submission

**From:** {{ $data['name'] }} ({{ $data['email'] }})
**Subject:** {{ $data['subject'] }}

---

{{ $data['message'] }}

<x-mail::button :url="'mailto:' . $data['email']">
Reply to {{ $data['name'] }}
</x-mail::button>

Sent from the Soul Bossa Nova contact form.
</x-mail::message>
