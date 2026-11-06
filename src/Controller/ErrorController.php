<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;

final class ErrorController extends Controller
{
    public function notFoundPage(): Response
    {
        return $this->notFound();
    }

    public function forbiddenPage(): Response
    {
        return $this->forbidden();
    }
}
