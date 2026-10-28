<?php

declare(strict_types=1);

namespace Watchman\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;
use Watchman\Laravel\WatchmanManager;
use Watchman\Search\Entity;
use Watchman\Sources\Senzing\SenzingWriter;

/**
 * POST /v2/ingest/{fileType} (internal/ingest/api_ingest.go).
 */
final class IngestController extends Controller
{
    public function __construct(private WatchmanManager $watchman)
    {
    }

    public function ingest(Request $request, string $fileType): JsonResponse|Response
    {
        $max = (int) config('watchman.ingest.max_body_bytes', 32 * 1024 * 1024);
        $body = $request->getContent();
        if (strlen($body) > $max) {
            return SearchController::error('request body too large', 413);
        }
        try {
            $res = $this->watchman->ingest($fileType, $body);
        } catch (Throwable $e) {
            return SearchController::failure($e);
        }
        [$format, $sub] = SearchController::chooseFormat((string) $request->header('Accept', ''), (string) $request->query('format', ''));
        if ($format === 'senzing') {
            $entities = array_map(static fn (array $e) => Entity::fromArray($e), $res['entities']);

            return new Response(SenzingWriter::write($entities, $sub), 200, ['Content-Type' => 'application/x-ndjson']);
        }

        return SearchController::json($res);
    }
}
