<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class TheoryController extends Controller
{
    public function index()
    {
        return Inertia::render('Library/Theory/Index');
    }
}
