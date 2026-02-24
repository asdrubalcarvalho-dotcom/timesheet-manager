<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $clients = Client::query()
            ->select(['id', 'name', 'email', 'tax_id'])
            ->orderBy('name')
            ->get();

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::create($validated);

        return response()->json($client, 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
        ]);

        $client->update($validated);

        return response()->json($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
