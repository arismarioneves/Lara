<?php
declare(strict_types=1);

namespace Lara;

/** Helpers dos endpoints JSON em /api. */
final class Api
{
    /** Exige sessão e (para métodos que alteram estado) token CSRF. */
    public static function guard(string ...$methods): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $methods, true)) {
            self::fail(405, 'Método não permitido');
        }
        if (!Config::installed()) {
            self::fail(503, 'A Lara ainda não foi configurada');
        }
        if (!Auth::check()) {
            self::fail(401, 'Sessão expirada');
        }
        if ($method !== 'GET' && !Auth::verifyCsrf()) {
            self::fail(403, 'Token CSRF inválido');
        }
        session_write_close(); // libera o lock da sessão: requisições paralelas não se bloqueiam
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $data = json_decode((string)file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    public static function ok(array $data = []): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $status, string $message, array $extra = []): never
    {
        http_response_code($status);
        self::ok(['error' => $message] + $extra);
    }

    /** Executa o endpoint convertendo exceções em JSON sem vazar detalhes internos. */
    public static function run(callable $handler): never
    {
        try {
            $handler();
            self::ok();
        } catch (\InvalidArgumentException $e) {
            self::fail(422, $e->getMessage());
        } catch (ProviderException $e) {
            Log::error('provider', $e->getMessage());
            self::fail(502, Config::get('app.debug') ? $e->getMessage() : 'Falha no provedor de IA');
        } catch (\Throwable $e) {
            Log::error('api', $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::fail(500, Config::get('app.debug') ? $e->getMessage() : 'Erro interno');
        }
    }
}
