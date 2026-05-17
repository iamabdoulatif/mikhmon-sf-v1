<?php
/*
 * auth.php — Helpers centralisés pour la vérification des rôles
 * Hiérarchie : Admin → Gérant → Vendeur
 */

/**
 * Vérifie qu'un admin est connecté, redirige sinon.
 */
function mikhmon_require_admin() {
    if (empty($_SESSION['mikhmon'])) {
        ob_end_clean();
        header('Location: ./admin.php?id=login');
        exit;
    }
}

/**
 * Vérifie qu'un gérant est connecté, redirige sinon.
 * @return array ['username', 'name', 'session']
 */
function mikhmon_require_manager() {
    global $managers_data;
    $u = $_SESSION['manager_username'] ?? '';
    if (empty($u) || empty($managers_data[$u])) {
        ob_end_clean();
        header('Location: ./admin.php?id=login');
        exit;
    }
    return [
        'username' => $u,
        'name'     => $_SESSION['manager_name']    ?? '',
        'session'  => $_SESSION['manager_session'] ?? '',
    ];
}

/**
 * Vérifie qu'un vendeur est connecté, redirige sinon.
 * @return array ['username', 'name', 'session']
 */
function mikhmon_require_seller() {
    global $sellers_data;
    $u = $_SESSION['seller_username'] ?? '';
    if (empty($u) || empty($sellers_data[$u])) {
        ob_end_clean();
        header('Location: ./admin.php?id=login');
        exit;
    }
    return [
        'username' => $u,
        'name'     => $_SESSION['seller_name']    ?? '',
        'session'  => $_SESSION['seller_session'] ?? '',
    ];
}

/**
 * Retourne le rôle de l'utilisateur courant ou null.
 */
function mikhmon_current_role() {
    if (!empty($_SESSION['mikhmon']))         return 'admin';
    if (!empty($_SESSION['manager_username'])) return 'manager';
    if (!empty($_SESSION['seller_username']))  return 'seller';
    return null;
}

/**
 * Retourne le nom d'affichage de l'utilisateur courant.
 */
function mikhmon_current_user() {
    if (!empty($_SESSION['mikhmon']))          return $_SESSION['mikhmon'];
    if (!empty($_SESSION['manager_username'])) return $_SESSION['manager_username'];
    if (!empty($_SESSION['seller_username']))  return $_SESSION['seller_username'];
    return 'unknown';
}
