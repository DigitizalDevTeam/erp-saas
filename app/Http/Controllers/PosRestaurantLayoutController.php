<?php

namespace App\Http\Controllers;

class PosRestaurantLayoutController extends Controller
{
    /**
     * Interactive restaurant floor designer (UI only).
     *
     * The page is a self-contained canvas: tables, walls, counters and
     * decor are dragged, rotated and resized in the browser, and the
     * layout is saved in the browser's localStorage. Nothing is stored
     * on the server, so this method only returns the view.
     */
    public function show()
    {
        return view('pos.restaurant-layout');
    }
}
