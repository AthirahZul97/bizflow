<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckController extends Controller
{
    /**
     * Show the application's health status, including the database connection.
     */
    public function __invoke(): Response
    {
        $database = $this->checkDatabase();

        return response()->view('health', [
            'healthy' => $database['ok'],
            'database' => $database,
        ], $database['ok'] ? 200 : 503);
    }

    /**
     * Attempt to open a connection on the default database connection.
     *
     * @return array{ok: bool, driver: string, name: string, message: string}
     */
    private function checkDatabase(): array
    {
        $connection = DB::connection();

        try {
            $connection->getPdo();

            return [
                'ok' => true,
                'driver' => $connection->getDriverName(),
                'name' => $connection->getDatabaseName(),
                'message' => 'Connected',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'driver' => $connection->getDriverName(),
                'name' => $connection->getDatabaseName(),
                'message' => config('app.debug') ? $e->getMessage() : 'Unable to connect to the database.',
            ];
        }
    }
}
