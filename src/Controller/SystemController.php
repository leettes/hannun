<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Monitoring\AppRegistry;
use App\Monitoring\MonitoringRepository;
use App\Monitoring\TimeRange;
use App\View;

final class SystemController extends Controller
{
    public function __construct(
        View $view,
        MonitoringRepository $data,
        private readonly AppRegistry $apps,
    ) {
        parent::__construct($view, $data);
    }

    public function show(Request $request, string $code): Response
    {
        $system = $this->data->system($code);
        if ($system === null) {
            return $this->notFound('등록되지 않은 서비스입니다.');
        }

        $range = TimeRange::fromQuery($request->query('range'), $request->query('from'), $request->query('to'));

        return $this->page('system', [
            'title' => $system['name'],
            'active' => 'system:' . $code,
            'refresh' => self::REFRESH_SECONDS,
            'system' => $system,
            'incidents' => $this->data->incidents(systemCode: $code),
            'range' => $range,
            'responseTimes' => $this->data->responseTimes($code, $range),
            'apps' => $this->apps->appsForSystem($code),
        ]);
    }
}
