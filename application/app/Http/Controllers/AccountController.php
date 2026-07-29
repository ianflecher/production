<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    public function updatePhoto(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'profile_photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        $user = $request->user();

        // Remove the previous profile picture.
        if (
            $user->profile_photo_path
            && Storage::disk('public')->exists($user->profile_photo_path)
        ) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $validated['profile_photo']->store(
            'profile-photos',
            'public'
        );

        $user->profile_photo_path = $path;
        $user->save();

        return back()->with(
            'success',
            'Your profile picture has been updated.'
        );
    }

    public function deletePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (
            $user->profile_photo_path
            && Storage::disk('public')->exists($user->profile_photo_path)
        ) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $user->profile_photo_path = null;
        $user->save();

        return back()->with(
            'success',
            'Your profile picture has been removed.'
        );
    }
}