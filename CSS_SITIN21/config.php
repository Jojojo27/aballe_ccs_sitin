<?php
// config.php - Database configuration
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ccs_sit_in');

if (PHP_SAPI !== 'cli' && !defined('CCS_THEME_BUFFER_STARTED')) {
    define('CCS_THEME_BUFFER_STARTED', true);

    ob_start(function ($buffer) {
        if (!is_string($buffer) || stripos($buffer, '<html') === false) {
            return $buffer;
        }

        $headInjection = "\n"
            . "<script>(function(){try{var t=localStorage.getItem('ccs_theme')||'light';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>\n"
            . "<link rel=\"stylesheet\" href=\"theme.css\">\n";

        if (stripos($buffer, 'href="theme.css"') === false && stripos($buffer, '</head>') !== false) {
            $buffer = preg_replace('/<\/head>/i', $headInjection . '</head>', $buffer, 1);
        }

        $bodyInjection = "\n"
            . "<button type=\"button\" class=\"theme-toggle-btn\" aria-label=\"Toggle theme\">"
            . "<span class=\"theme-toggle-icon\">🌙</span><span class=\"theme-toggle-text\">Dark</span></button>\n"
            . "<script src=\"theme.js\"></script>\n";

        if (stripos($buffer, 'class="theme-toggle-btn"') === false && stripos($buffer, '</body>') !== false) {
            $buffer = preg_replace('/<\/body>/i', $bodyInjection . '</body>', $buffer, 1);
        }

        return $buffer;
    });
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>