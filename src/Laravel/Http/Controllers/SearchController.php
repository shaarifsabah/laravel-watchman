<?php

declare(strict_types=1);

namespace Watchman\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Laravel\WatchmanManager;
use Watchman\Search\QueryParser;
use Watchman\Sources\Senzing\SenzingWriter;

/**
 * GET /v2/search and GET /v2/listinfo (internal/search/api_search.go).
 */
final class SearchController extends Controller
{
    public function __construct(private WatchmanManager $watchman)
    {
    }

    public function search(Request $request): JsonResponse|Response
    {
        $parser = QueryParser::fromQueryString((string) $request->server('QUERY_STRING', ''));
        try {
            $client = $this->watchman->driver();
            [$format, $subformat] = self::chooseFormat((string) $request->header('Accept', ''), $parser->get('format'));
            if (!$client instanceof NativeClient) {
                // http driver: forward the parameters (minus the output format, applied below) to the Go server
                $params = $parser->all();
                unset($params['format']);
                $res = $client->search($params);
            } else {
                $query = $parser->entity();
                $opts = $parser->options(
                    (int) config('watchman.search.default_limit', 10),
                    (int) config('watchman.search.max_limit', 100),
                );
                if (($extra = $parser->unused()) !== []) {
                    throw new InvalidArgumentException('extra/unused query parameters in request: ' . implode(',', $extra));
                }
                $res = $client->searchEntity($query, $opts);
            }
        } catch (Throwable $e) {
            return self::failure($e);
        }

        if ($format === 'senzing') {
            try {
                $body = SenzingWriter::write($res->entities, $subformat);
            } catch (Throwable $e) {
                return self::failure($e);
            }

            return new Response($body, 200, ['Content-Type' => 'application/x-ndjson']);
        }

        return self::json($res->toArray());
    }

    public function listInfo(): JsonResponse
    {
        return self::json($this->watchman->listInfo());
    }

    /** @return array{0:string,1:string} */
    public static function chooseFormat(string $accept, string $param): array
    {
        foreach ([$accept, $param] as $input) {
            foreach (explode(',', $input) as $part) {
                $media = trim(explode(';', $part)[0]);
                if (str_starts_with($media, 'senzing')) {
                    return ['senzing', ltrim(substr($media, 7), '/')];
                }
            }
        }

        return ['watchman', 'json'];
    }

    public static function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function error(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    /**
     * A bad request (400 with its message) or a server-side failure (500, details only in the log),
     * so callers never mistake "could not screen" for "bad query".
     */
    public static function failure(Throwable $e): JsonResponse
    {
        if ($e instanceof InvalidArgumentException) {
            return self::error($e->getMessage());
        }
        report($e);

        return self::error('internal error while processing the request', 500);
    }
}
