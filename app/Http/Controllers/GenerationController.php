<?php

namespace App\Http\Controllers;

use App\Billing\InsufficientCredits;
use App\Generations\StartGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\GenerationOptions;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Studio', [
            'generations' => GenerationResource::collection(
                $user->generations()->latest('id')->paginate(12),
            ),
            'hasActive' => $user->generations()->active()->exists(),
            'options' => [
                'sizes' => GenerationOptions::SIZES,
                'qualities' => GenerationOptions::QUALITIES,
                'max_prompt_length' => GenerationOptions::MAX_PROMPT_LENGTH,
                'max_reference_images' => GenerationOptions::MAX_REFERENCE_IMAGES,
            ],
        ]);
    }

    public function store(GenerationRequest $request, StartGeneration $start): RedirectResponse
    {
        try {
            $start($request->user(), $request->generationInput());
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        } catch (PixelforgeException) {
            // Only the pricing lookup talks to Pixelforge synchronously.
            throw ValidationException::withMessages([
                'prompt' => 'We could not load current prices from Pixelforge. Nothing was submitted; please try again.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation queued.']);

        return to_route('dashboard');
    }
}
