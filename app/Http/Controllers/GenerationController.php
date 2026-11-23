<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Services\Pixelforge\PricingRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The user's generations, newest first, with the total they have been charged.
     */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection(
                $generations->clone()->latest()->latest('id')->paginate(20),
            ),
            'totals' => fn () => [
                'charge_nanos' => (int) $generations->clone()->succeeded()->sum('charge_nanos'),
                'succeeded_count' => $generations->clone()->succeeded()->count(),
            ],
        ]);
    }

    /**
     * The generate form. Pricing goes to the browser as integers so the live
     * estimate needs no server round trip; ?from={id} prefills "Try again".
     */
    public function create(Request $request, PricingRepository $pricing): Response
    {
        $from = $request->filled('from')
            ? $request->user()->generations()->findOrFail($request->string('from')->toString())
            : null;

        return Inertia::render('Generations/Create', [
            'pricing' => $pricing->current()?->toArray(),
            'initial' => $from === null ? null : [
                'prompt' => $from->prompt,
                'size' => $from->size->value,
                'quality' => $from->quality->value,
                'reference_images' => $from->reference_images,
            ],
        ]);
    }

    /**
     * Save the generation and return at once; Pixelforge is called from a queued job.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle($request->user(), $request->generationInput());

        return to_route('generations.index');
    }
}
