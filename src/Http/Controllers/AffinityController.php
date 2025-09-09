<?php

namespace CapsuleCmdr\Affinity\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;


class AffinityController extends Controller
{

    public function about()
    {
        $user = Auth::user();

        return view('affinity::about', compact('user'));
    }
}