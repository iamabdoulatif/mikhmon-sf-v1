<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// hide all error
error_reporting(0);
$requestUri = isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : '';
if ($requestUri !== '' && substr($requestUri, -11) == "readcfg.php") {
    header("Location:./");
    exit;
}

$mikhmon_router_session_valid = false;
$iphost = '';
$userhost = '';
$passwdhost = '';
$hotspotname = '';
$dnsname = '';
$currency = '';
$areload = '';
$iface = '';
$infolp = '';
$idleto = '';
$sesname = '';
$useradm = '';
$passadm = '';
$livereport = '';

if (empty($session) || !isset($data[$session]) || !is_array($data[$session])) {
    return;
}

$mikhmon_router_session_valid = true;

// read config
$iphost = explode('!', $data[$session][1])[1] ?? '';
$userhost = explode('@|@', $data[$session][2])[1] ?? '';
$passwdhost = explode('#|#', $data[$session][3])[1] ?? '';
$hotspotname = explode('%', $data[$session][4])[1] ?? '';
$dnsname = explode('^', $data[$session][5])[1] ?? '';
$currency = explode('&', $data[$session][6])[1] ?? '';
$areload = explode('*', $data[$session][7])[1] ?? '';
$iface = explode('(', $data[$session][8])[1] ?? '';
$infolp = explode(')', $data[$session][9])[1] ?? '';
$idleto = explode('=', $data[$session][10])[1] ?? '';
$sesname = explode('+', $data[$session][10])[1] ?? '';
$useradm = explode('<|<', $data['mikhmon'][1])[1] ?? '';
$passadm = explode('>|>', $data['mikhmon'][2])[1] ?? '';
$livereport = explode('@!@', $data[$session][11])[1] ?? '';

$cekindo['indo'] = array(
    'RP', 'Rp', 'rp', 'IDR', 'idr', 'RP.', 'Rp.', 'rp.', 'IDR.', 'idr.',
);

