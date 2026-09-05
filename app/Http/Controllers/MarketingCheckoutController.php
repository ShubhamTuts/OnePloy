<?php

namespace App\Http\Controllers;

use App\Services\OnePloy\WordPressMarketingHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MarketingCheckoutController extends Controller
{
    public function __invoke(Request $request, WordPressMarketingHandoff $handoff): RedirectResponse
    {
        try {
            $payload = $handoff->validate($request->query());
        } catch (InvalidArgumentException) {
            $request->session()->forget(WordPressMarketingHandoff::SESSION_KEY);
            abort(403, 'This WordPress checkout link is invalid or expired. Return to the marketing site and try again.');
        }

        $request->session()->put(WordPressMarketingHandoff::SESSION_KEY, $payload);
        if ($request->user()) {
            return redirect()->route('oneploy.marketing-checkout.confirm');
        }

        $request->session()->put('url.intended', route('oneploy.marketing-checkout.confirm'));

        return redirect()->route('login');
    }
}
