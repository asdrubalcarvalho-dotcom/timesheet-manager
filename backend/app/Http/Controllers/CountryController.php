<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CountryController extends Controller
{
    public function __construct()
    {
        // Read routes are used by dropdowns throughout the app
        $this->middleware('throttle:read')->only(['index', 'show']);

        // Write routes reuse existing admin permission (no new access model)
        $this->middleware(['permission:manage-locations', 'throttle:create'])->only(['store']);
        $this->middleware(['permission:manage-locations', 'throttle:edit'])->only(['update']);
        $this->middleware(['permission:manage-locations', 'throttle:delete'])->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $countries = Country::query()
            ->select(['id', 'name', 'iso2'])
            ->orderBy('name')
            ->get();

        return response()->json($countries);
    }

    public function show(Country $country): JsonResponse
    {
        return response()->json($country->only(['id', 'name', 'iso2']));
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'iso2' => strtoupper((string) $request->input('iso2', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'iso2' => [
                'required',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
                Rule::unique('countries', 'iso2'),
            ],
        ], [
            'iso2.size' => 'ISO-2 must be exactly 2 letters.',
            'iso2.regex' => 'ISO-2 must be exactly 2 letters (ISO-3 is not allowed).',
        ]);

        $country = Country::create([
            'name' => $validated['name'],
            'iso2' => $validated['iso2'],
        ]);

        return response()->json($country->only(['id', 'name', 'iso2']), 201);
    }

    public function update(Request $request, Country $country): JsonResponse
    {
        $request->merge([
            'iso2' => strtoupper((string) $request->input('iso2', '')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'iso2' => [
                'required',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
                Rule::unique('countries', 'iso2')->ignore($country->id),
            ],
        ], [
            'iso2.size' => 'ISO-2 must be exactly 2 letters.',
            'iso2.regex' => 'ISO-2 must be exactly 2 letters (ISO-3 is not allowed).',
        ]);

        $country->update([
            'name' => $validated['name'],
            'iso2' => $validated['iso2'],
        ]);

        return response()->json($country->only(['id', 'name', 'iso2']));
    }

    public function destroy(Request $request, Country $country): JsonResponse
    {
        $locationsCount = $country->locations()->count();
        $force = $request->boolean('force');

        if ($locationsCount > 0 && ! $force) {
            return response()->json([
                'message' => 'Country is in use by locations. Confirm delete with force=true to proceed.',
                'locations_count' => $locationsCount,
            ], 409);
        }

        $country->delete();

        return response()->json(['success' => true]);
    }
}
