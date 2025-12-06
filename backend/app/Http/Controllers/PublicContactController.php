<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PublicContactController extends Controller
{
    public function submit(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'company' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
            'agree'   => 'boolean',
        ]);

        Mail::raw(
            "New contact request from vendaslive.com:\n\n" .
            "Name: {$validated['name']}\n" .
            "Email: {$validated['email']}\n" .
            "Company: {$validated['company']}\n\n" .
            "Message:\n{$validated['message']}\n",
            function ($mail) use ($validated) {
                $mail->to('hello@upg2ai.com')
                     ->subject('New contact from vendaslive.com');
            }
        );

        return response()->json(['success' => true]);
    }
}
