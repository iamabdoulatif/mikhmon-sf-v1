<?php
/*
 * csrf.php — Protection CSRF pour tous les formulaires
 */

function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Retourne le champ HTML caché à insérer dans chaque formulaire */
function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Vérifie le token CSRF sur une requête POST */
function csrf_check() {
    $token = $_POST['_csrf'] ?? '';
    return !empty($token) && hash_equals($_SESSION['_csrf'] ?? '', $token);
}

/** Vérifie et rejette avec un message d'erreur si invalide */
function csrf_guard($error_msg = 'Invalid request.') {
    if (!csrf_check()) {
        http_response_code(403);
        die('<div style="padding:20px;font-family:sans-serif;color:#c0392b;"><b>403</b> — ' . htmlspecialchars($error_msg) . '</div>');
    }
}
