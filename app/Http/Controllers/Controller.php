<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

abstract class Controller
{
    /** Convert a decimal amount from a form (e.g. "1250.50") to cents. */
    protected function toCents(float|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Run a Stripe call and turn API errors into a flash message instead of a 500.
     *
     * @param  callable(): mixed  $action  may return a RedirectResponse to override "back"
     */
    protected function stripeAction(callable $action, string $success): RedirectResponse
    {
        try {
            $response = $action();
        } catch (ApiErrorException|RuntimeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage())->withInput();
        }

        $redirect = $response instanceof RedirectResponse ? $response : back();

        return $redirect->with('success', $success);
    }
}
