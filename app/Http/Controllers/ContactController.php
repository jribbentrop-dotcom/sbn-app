<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function show()
    {
        return Inertia::render('Contact/Index');
    }

    public function submit(Request $request)
    {
        // Honeypot: real users leave this hidden field empty. Bots fill it.
        // Pretend success so the bot gets no signal that it was caught.
        if (filled($request->input('website'))) {
            return back()->with('success', "Thanks for reaching out — we'll get back to you soon.");
        }

        $data = $request->validate([
            'name'    => 'required|string|max:120',
            'email'   => 'required|email|max:160',
            'subject' => 'required|string|max:160',
            'message' => 'required|string|max:4000',
        ]);

        Mail::to(config('mail.contact_to', 'hello@soulbossanova.com'))
            ->send(new ContactFormMail($data));

        return back()->with('success', "Thanks for reaching out — we'll get back to you soon.");
    }
}
