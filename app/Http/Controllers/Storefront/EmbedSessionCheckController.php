<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets the embedded storefront find out whether the browser kept its session
 * cookie. Some browsers block cookies for a site shown in an iframe on
 * another site; the cart and checkout can't work without one. The embed page
 * compares this token with the one it was rendered with: if the cookie was
 * dropped, this request started a fresh session with a different token.
 */
class EmbedSessionCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store');
    }
}
