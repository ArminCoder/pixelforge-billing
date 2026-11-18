<?php

namespace App\Http\Controllers;

use App\Services\Credits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    /** The fake "Buy credits" button. No real payment happens. */
    public function store(Request $request, Credits $credits): RedirectResponse
    {
        $credits->purchase($request->user(), config('pixelforge.credit_pack_nanos'));

        return back();
    }
}
