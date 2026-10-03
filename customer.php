<?php
declare(strict_types=1);
require __DIR__ . '/lib/customer.php';
require __DIR__ . '/lib/sms.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    customerSession();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !ownerCsrfValid($input['csrf'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Your session expired. Reload the page and try again.');
        }
        $action = $input['action'] ?? '';
        if ($action === 'logout') {
            $_SESSION = [];
        } elseif ($action === 'login' || $action === 'signup') {
            $user = customerAccount($action, $input, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $_SESSION = ['user' => $user, 'last_active' => time()];
            $_SESSION['password_version'] = customerPasswordVersion($user);
            customerWelcomeSms($user);
        } else {
            throw new InvalidArgumentException('Unknown account action.');
        }
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new InvalidArgumentException('Method not allowed.');
    }
    echo json_encode(['user' => $_SESSION['user'] ?? null, 'csrf' => $_SESSION['csrf'], 'needsFirstAccount' => empty(ownerRead('customers'))], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $error) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['error' => $error instanceof JsonException ? 'Invalid request.' : $error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Customer accounts are temporarily unavailable. Please try again.']);
}
