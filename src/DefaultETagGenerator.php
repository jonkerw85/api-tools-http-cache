<?php

declare(strict_types=1);

namespace Laminas\ApiTools\HttpCache;

use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;

use function md5;

class DefaultETagGenerator implements ETagGeneratorInterface
{
    /**
     * {@inheritDoc}
     */
    public function generate(HttpRequest $request, HttpResponse $response)
    {
        return md5($response->getContent());
    }
}
