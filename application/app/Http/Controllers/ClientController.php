<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client book: everybody the shop has ever written down.
 *
 * Until now a client existed only inside the picker on the inquiry and order
 * forms — saved, but with nowhere to look them up again. This is that page.
 * It is a reading page: the names are created by the officer taking the
 * inquiry, and this list only shows what they wrote.
 */
class ClientController extends Controller
{
    /** Leaders, supervisors and the admin oversee the whole book — no scoping. */
    public function index(Request $request): View
    {
        return view('clients.index', [
            'clients' => Client::bySurname()
                ->withCount(['orders', 'inquiries'])
                ->with('creator')
                ->get(),
        ]);
    }
}
