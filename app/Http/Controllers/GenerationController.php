<?php

namespace App\Http\Controllers;

use App\Generations\CreateGeneration;
use App\Generations\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
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
                'charge_nanos' => (int) $generations->clone()->where('status', GenerationStatus::Succeeded)->sum('charge_nanos'),
                'succeeded_count' => $generations->clone()->where('status', GenerationStatus::Succeeded)->count(),
            ],
        ]);
    }

    /**
     * Save the generation and return at once; Pixelforge is called from a queued job.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        $createGeneration->handle($request->user(), $request->validated());

        return to_route('generations.index');
    }
}
