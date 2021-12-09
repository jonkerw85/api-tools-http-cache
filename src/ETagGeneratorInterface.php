<?php

declare(strict_types=1);

namespace Laminas\ApiTools\HttpCache;

use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;

interface ETagGeneratorInterface
{
    /**
     * Returns an ETag for the given response.
     *
     * @return string ETag
     */
    public function generate(HttpRequest $request, HttpResponse $response);
}
