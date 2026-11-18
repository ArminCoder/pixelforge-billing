<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use App\Services\Pixelforge\PixelforgeException;
use App\Services\Pricing;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GenerationController extends Controller
{
    /** History page. The browser re-asks for this while images are unfinished. */
    public function index(Request $request, Credits $credits): Response
    {
        $user = $request->user();

        $generations = Generation::where('user_id', $user->id)->latest('id')->limit(50)->get();

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations)->resolve(),
            'balance' => $credits->balance($user),
            'reserved' => $credits->reserved($user),
            'creditPack' => config('pixelforge.credit_pack_nanos'),
            'isAdmin' => in_array($user->email, config('pixelforge.admin_emails'), true),
        ]);
    }

    /** Generate page. */
    public function create(Request $request, Credits $credits): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => GenerationRequest::SIZES,
            'qualities' => GenerationRequest::QUALITIES,
            'available' => $credits->available($request->user()),
        ]);
    }

    /** Price range for the form as it is right now. Called while the user types. */
    public function estimate(GenerationRequest $request, Pricing $pricing): JsonResponse
    {
        try {
            return response()->json($this->estimateFor($request, $pricing));
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Prices are not available right now.'], 503);
        }
    }

    /** Save the request, hold the credit, and hand it to the queue. */
    public function store(GenerationRequest $request, Pricing $pricing, Credits $credits): RedirectResponse
    {
        try {
            $estimate = $this->estimateFor($request, $pricing);
        } catch (PixelforgeException) {
            throw ValidationException::withMessages([
                'prompt' => 'Prices are not available right now, so nothing was sent. Please try again in a minute.',
            ]);
        }

        $generation = DB::transaction(function () use ($request, $estimate, $credits) {
            // Lock the user so two fast clicks cannot both spend the same credit.
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($credits->available($user) < $estimate['max']) {
                throw ValidationException::withMessages([
                    'credits' => 'Not enough credit. This image can cost up to $'
                        .Money::toDecimal($estimate['max']).'. Buy credits and try again.',
                ]);
            }

            return Generation::create([
                'user_id' => $user->id,
                'prompt' => $request->validated('prompt'),
                'size' => $request->validated('size'),
                'quality' => $request->validated('quality'),
                'reference_images' => $request->referenceImages(),
                'status' => GenerationStatus::Queued,
                'estimated_price_min_nanos' => $estimate['min'],
                'estimated_price_max_nanos' => $estimate['max'],
            ]);
        });

        // Our row exists before Pixelforge hears about it, so even a very
        // fast webhook finds something to update.
        SubmitGeneration::dispatch($generation);

        return to_route('generations.index');
    }

    /** The image itself: our stored copy, or Pixelforge's link until the copy is ready. */
    public function image(Request $request, Generation $generation): HttpResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        if ($generation->image_path !== null) {
            return Storage::response($generation->image_path);
        }

        abort_if($generation->image_url === null, 404);

        return redirect()->away($generation->image_url);
    }

    /** @return array{min: int, max: int} */
    private function estimateFor(GenerationRequest $request, Pricing $pricing): array
    {
        return $pricing->estimatePrice(
            $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            count($request->referenceImages()),
        );
    }
}
