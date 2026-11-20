<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || ! $this->isValid($request, $secret)) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        return $next($request);
    }

    private function isValid(Request $request, string $secret): bool
    {
        $parts = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        // Each delivery attempt is signed fresh, so an old timestamp means a replay.
        if (abs(time() - (int) $timestamp) > (int) config('services.pixelforge.webhook_tolerance')) {
            return false;
        }

        // Must be the raw body: re-encoded JSON would not match byte for byte.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
