<?php

declare(strict_types=1);

namespace RequestTracer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RequestTracer\Journal\JournalStorage;

/** The request journal: the list of recent requests (traced or not), one entry in full, and clearing it. */
final class JournalController extends Controller
{
    public function __construct(private readonly JournalStorage $journal) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->journal->list(
            [
                'status' => (string) $request->query('status', ''),
                'search' => (string) $request->query('search', ''),
            ],
            $request->query('cursor') ? (string) $request->query('cursor') : null,
            (int) $request->query('limit', 50),
        );

        return response()->json(['data' => $page['data'], 'next_cursor' => $page['next_cursor']]);
    }

    /** One entry with its headers and body: what the replay form is filled in from. */
    public function show(string $entry): JsonResponse
    {
        $row = $this->journal->find($entry);
        abort_if($row === null, 404);

        return response()->json(['data' => $row]);
    }

    public function clear(): JsonResponse
    {
        return response()->json(['deleted' => $this->journal->clear()]);
    }
}
