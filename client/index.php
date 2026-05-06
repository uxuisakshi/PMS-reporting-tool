<?php
/**
 * Client Interface Router
 * Routes client requests with authentication middleware
 * Implements role-based access control for all client endpoints
 */

// Security: only display errors in development — never in production
if (getenv('APP_ENV') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Include authentication system first (handles sessions, session paths, and constants)
require_once __DIR__ . '/../includes/auth.php';

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/controllers/ClientDashboardController.php';
require_once __DIR__ . '/../includes/controllers/ClientExportController.php';
require_once __DIR__ . '/../includes/controllers/ClientAuthenticationController.php';
require_once __DIR__ . '/../includes/models/SecurityValidator.php';
require_once __DIR__ . '/../includes/helpers.php';

// Initialize security validator
$securityValidator = new SecurityValidator();

// Get request path and method
$requestPath = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string from path
$path = parse_url($requestPath, PHP_URL_PATH);

// Remove base path if running in subdirectory
$baseDir = getBaseDir();
if ($baseDir !== '' && strpos($path, $baseDir) === 0) {
    $path = substr($path, strlen($baseDir));
}

$basePath = '/client';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Ensure path starts with /
if (empty($path) || $path[0] !== '/') {
    $path = '/' . $path;
}

// Define routes
$routes = [
    'GET' => [
        '/' => 'dashboard',
        '/dashboard' => 'dashboard',
        '/project/{id}' => 'projectView',
        '/logout' => 'logout',
        '/download' => 'downloadFile',
        '/exports' => 'listExports'
    ],
    'POST' => [
        '/export/request' => 'requestExport',
        '/ajax/widget' => 'ajaxWidget'
    ],
    'AJAX' => [
        '/export/status/{id}' => 'getExportStatus'
    ]
];

// Route matching function
function matchRoute($path, $routes) {
    foreach ($routes as $route => $handler) {
        // Convert route pattern to regex
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $route);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $path, $matches)) {
            array_shift($matches); // Remove full match
            return ['handler' => $handler, 'params' => $matches];
        }
    }
    return null;
}

// Security middleware
function checkSecurity($path, $method) {
    global $securityValidator;
    
    // Skip security checks for logout page
    if ($path === '/logout') {
        return true;
    }
    
    // Check rate limiting for all requests
    $clientId = $_SESSION['client_user_id'] ?? 'anonymous';
    if (!$securityValidator->checkRateLimit("client_request_$clientId", 100, 300)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests']);
        exit;
    }
    
    // Validate CSRF for POST requests
    if ($method === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        if (!$securityValidator->validateCSRFToken($csrfToken, $sessionToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token validation failed']);
            exit;
        }
    }
    
    return true;
}

// Authentication middleware
function requireAuth($path) {
    // Skip auth for logout page
    if ($path === '/logout') {
        return true;
    }
    
    // Check if user is authenticated (Check both standard and client-specific session keys)
    if (!isset($_SESSION['client_user_id'])) {
        if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'client') {
            // Bridge from main auth session to client session
            $_SESSION['client_user_id'] = $_SESSION['user_id'];
            $_SESSION['client_role'] = 'client';
            $_SESSION['is_client'] = true;
        } else {
            if (isAjaxRequest()) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                exit;
            } else {
                $baseDir = getBaseDir();
                header('Location: ' . $baseDir . '/modules/auth/login.php');
                exit;
            }
        }
    }
    
    // Check for force password reset (same as header.php check)
    if (isset($_SESSION['user_id']) && ($_SESSION['force_reset'] ?? false)) {
        $currentPage = $_SERVER['PHP_SELF'];
        if (strpos($currentPage, 'modules/auth/force_reset.php') === false && 
            strpos($currentPage, 'modules/auth/logout.php') === false) {
            $baseDir = getBaseDir();
            header('Location: ' . $baseDir . '/modules/auth/force_reset.php');
            exit;
        }
    }
    
    // Verify client role
    if ($_SESSION['client_role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    return true;
}

// Check if request is AJAX
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function applyClientResponseHeaders($path) {
    if ($path === '/download') {
        return;
    }

    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
}

// Handle 404
function handle404() {
    http_response_code(404);
    if (isAjaxRequest()) {
        echo json_encode(['error' => 'Endpoint not found']);
    } else {
        include __DIR__ . '/../includes/templates/client/404.php';
    }
    exit;
}

// Main routing logic
try {
    // Apply security middleware
    checkSecurity($path, $requestMethod);
    
    // Apply authentication middleware
    requireAuth($path);

    // Prevent authenticated client pages and JSON responses from being cached.
    applyClientResponseHeaders($path);
    
    // Find matching route
    $methodRoutes = $routes[$requestMethod] ?? [];
    $match = matchRoute($path, $methodRoutes);
    
    if (!$match) {
        handle404();
    }
    
    $handler = $match['handler'];
    $params = $match['params'];
    
    // Route to appropriate controller
    switch ($handler) {
        // Authentication routes
        case 'logout':
            $controller = new ClientAuthenticationController();
            $controller->logout();
            break;
            
        // Dashboard routes
        case 'dashboard':
            $controller = new ClientDashboardController();
            $controller->dashboard();
            break;
            
        case 'projectView':
            $controller = new ClientDashboardController();
            $controller->projectView($params[0] ?? null);
            break;
            
        case 'ajaxWidget':
            $controller = new ClientDashboardController();
            $controller->ajaxWidget();
            break;
            
        // Export routes
        case 'requestExport':
            $controller = new ClientExportController();
            $controller->requestExport();
            break;
            
        case 'downloadFile':
            $controller = new ClientExportController();
            $controller->downloadFile($_GET['id'] ?? null);
            break;
            
        case 'getExportStatus':
            $controller = new ClientExportController();
            $controller->getExportStatus($params[0] ?? null);
            break;
            
        case 'listExports':
            $controller = new ClientExportController();
            $controller->listExports();
            break;
            
        default:
            handle404();
    }
    
} catch (Throwable $e) {
    error_log("Client router error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    if (isAjaxRequest()) {
        // Security: never expose raw exception details to clients in production
        $debugMode = (getenv('APP_ENV') === 'development');
        echo json_encode(['error' => 'Internal server error', 'debug' => $debugMode ? $e->getMessage() : null]);
    } else {
        // Show detailed error in development
        if (ini_get('display_errors')) {
            echo "<h1>Client Router Error</h1>";
            echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
            echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        } else {
            include __DIR__ . '/../includes/templates/client/error.php';
        }
    }
}
?>