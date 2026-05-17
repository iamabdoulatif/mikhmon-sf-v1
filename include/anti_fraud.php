<?php
/*
 * Anti-fraud module — détection des codes/tickets utilisés sur plusieurs MAC.
 * Persistance dans logs/fraud.json
 */

if (!function_exists('anti_fraud_log_path')) {
    function anti_fraud_log_path() {
        return __DIR__ . '/../logs/fraud.json';
    }

    function anti_fraud_load() {
        $path = anti_fraud_log_path();
        if (!file_exists($path)) return array();
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return array();
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    function anti_fraud_save($incidents) {
        $path = anti_fraud_log_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp  = $path . '.tmp';
        $json = json_encode($incidents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        return @rename($tmp, $path);
    }

    /**
     * Lit les logs hotspot récents et extrait les tentatives échouées
     * "USER (IP): login failed: invalid MAC address" en corrélant l'IP
     * avec le MAC le plus récent vu pour cette IP (logs hotspot précédents
     * ou table /ip/hotspot/host).
     *
     * Retourne array<string user, array<string mac => array{ip,time,source:'log_failed'}>>
     */
    function anti_fraud_scan_failed_attempts($API) {
        $out = array();
        if (!$API) return $out;

        // Snapshot IP → MAC depuis /ip/hotspot/host pour fallback
        $ipToMac = array();
        try {
            $hosts = $API->comm('/ip/hotspot/host/print');
            if (is_array($hosts)) {
                foreach ($hosts as $h) {
                    $ip  = isset($h['address']) ? trim($h['address']) : '';
                    $mac = isset($h['mac-address']) ? strtoupper(trim($h['mac-address'])) : '';
                    if ($ip !== '' && $mac !== '') $ipToMac[$ip] = $mac;
                }
            }
        } catch (Exception $e) {}

        // Lit les logs hotspot récents
        $logs = array();
        try {
            $logs = $API->comm('/log/print', array('?topics' => 'hotspot'));
        } catch (Exception $e) {}
        if (!is_array($logs)) return $out;

        // Premier passage : extrait les couples IP → MAC depuis les lignes
        // "<MAC> (<IP>): ..." (préfixe d'une tentative ou login échoué auth générique)
        foreach ($logs as $row) {
            $msg = isset($row['message']) ? $row['message'] : '';
            if (preg_match('/^([0-9A-F]{2}(?::[0-9A-F]{2}){5})\s*\((\d+\.\d+\.\d+\.\d+)\)/i', $msg, $m)) {
                $ipToMac[$m[2]] = strtoupper($m[1]);
            }
        }

        // Second passage : repère les "invalid MAC address" et associe à l'IP/MAC
        foreach ($logs as $row) {
            $msg  = isset($row['message']) ? $row['message'] : '';
            $time = isset($row['time']) ? $row['time'] : '';
            // Patterns possibles :
            //   "user (ip): login failed: invalid MAC address"
            //   "->: user (ip): login failed: invalid MAC address"
            if (preg_match('/(?:->: )?([\w\-\.]+)\s*\((\d+\.\d+\.\d+\.\d+)\):\s*login failed:\s*invalid MAC address/i', $msg, $m)) {
                $user = $m[1];
                $ip   = $m[2];
                $mac  = isset($ipToMac[$ip]) ? $ipToMac[$ip] : '';
                if ($mac === '') continue;
                if (!isset($out[$user])) $out[$user] = array();
                if (!isset($out[$user][$mac])) {
                    $out[$user][$mac] = array(
                        'ip'         => $ip,
                        'first_seen' => $time,
                        'source'     => 'log_failed',
                    );
                }
                $out[$user][$mac]['last_seen'] = $time;
            }
        }
        return $out;
    }

    /**
     * Scanne MikroTik, met à jour logs/fraud.json. Retourne le nombre d'incidents (re)détectés.
     */
    function anti_fraud_scan($API) {
        if (!$API) return 0;

        $cookies = $API->comm('/ip/hotspot/cookie/print');
        $active  = $API->comm('/ip/hotspot/active/print');
        if (!is_array($cookies)) $cookies = array();
        if (!is_array($active))  $active  = array();

        // Map user → unique MAC list (succès)
        $userMacs = array();
        foreach ($cookies as $c) {
            $u = isset($c['user']) ? trim($c['user']) : '';
            $m = isset($c['mac-address']) ? strtoupper(trim($c['mac-address'])) : '';
            if ($u === '' || $m === '') continue;
            if (!isset($userMacs[$u])) $userMacs[$u] = array();
            $userMacs[$u][$m] = isset($userMacs[$u][$m]) ? $userMacs[$u][$m] : array(
                'first_seen' => date('Y-m-d H:i:s'),
                'last_seen'  => date('Y-m-d H:i:s'),
                'source'     => 'cookie',
            );
        }
        foreach ($active as $a) {
            $u = isset($a['user']) ? trim($a['user']) : '';
            $m = isset($a['mac-address']) ? strtoupper(trim($a['mac-address'])) : '';
            if ($u === '' || $m === '') continue;
            if (!isset($userMacs[$u])) $userMacs[$u] = array();
            if (!isset($userMacs[$u][$m])) {
                $userMacs[$u][$m] = array(
                    'first_seen' => date('Y-m-d H:i:s'),
                    'source'     => 'active',
                );
            }
            $userMacs[$u][$m]['last_seen'] = date('Y-m-d H:i:s');
        }

        // Tentatives bloquées par MAC-lock (depuis logs)
        $attemptedMacs = anti_fraud_scan_failed_attempts($API);

        $existing = anti_fraud_load();
        $byKey = array();
        foreach ($existing as $i) {
            if (isset($i['user'])) $byKey[$i['user']] = $i;
        }

        $now = date('Y-m-d H:i:s');

        // Liste tous les utilisateurs concernés : ceux avec ≥2 MACs réussis OU ceux avec
        // au moins une tentative bloquée (MAC-lock rejeté).
        $allUsers = array();
        foreach ($userMacs as $u => $_) $allUsers[$u] = true;
        foreach ($attemptedMacs as $u => $_) $allUsers[$u] = true;

        foreach (array_keys($allUsers) as $user) {
            $macs       = isset($userMacs[$user])      ? $userMacs[$user]      : array();
            $attempted  = isset($attemptedMacs[$user]) ? $attemptedMacs[$user] : array();

            // Filtrer les attempted déjà présents en succès (pas vraiment de fraude)
            foreach (array_keys($attempted) as $am) {
                if (isset($macs[$am])) unset($attempted[$am]);
            }

            $hasSuccess = count($macs) >= 2;
            $hasAttempt = count($attempted) >= 1;
            if (!$hasSuccess && !$hasAttempt) continue;

            // Get user profile + comment + locked MAC for context
            $profile  = '';
            $comment  = '';
            $lockedMac = '';
            try {
                $userRow = $API->comm('/ip/hotspot/user/print', array('?name' => $user));
                if (is_array($userRow) && !empty($userRow[0])) {
                    $profile   = isset($userRow[0]['profile']) ? $userRow[0]['profile'] : '';
                    $comment   = isset($userRow[0]['comment']) ? $userRow[0]['comment'] : '';
                    $lockedMac = isset($userRow[0]['mac-address']) ? strtoupper(trim($userRow[0]['mac-address'])) : '';
                }
            } catch (Exception $e) {}

            $payload = array(
                'user'             => $user,
                'profile'          => $profile,
                'comment'          => $comment,
                'locked_mac'       => $lockedMac,
                'macs'             => array_keys($macs),
                'mac_meta'         => $macs,
                'count'            => count($macs),
                'attempted_macs'   => array_keys($attempted),
                'attempted_meta'   => $attempted,
                'attempted_count'  => count($attempted),
                'last_seen'        => $now,
            );

            if (isset($byKey[$user])) {
                // Update existing
                foreach ($payload as $k => $v) $byKey[$user][$k] = $v;
                if (!isset($byKey[$user]['status'])) $byKey[$user]['status'] = 'new';
                if (!isset($byKey[$user]['history'])) $byKey[$user]['history'] = array();
                // Si nouvelle tentative depuis dernière reconnaissance → repasser en 'new'
                if ($byKey[$user]['status'] !== 'new' && $hasAttempt) {
                    $byKey[$user]['status'] = 'new';
                    $byKey[$user]['history'][] = array('at' => $now, 'event' => 'new_attempt');
                }
            } else {
                $payload['first_detected'] = $now;
                $payload['status']         = 'new';
                $payload['history']        = array(array('at' => $now, 'event' => 'detected'));
                $byKey[$user]              = $payload;
            }
        }

        $list = array_values($byKey);
        // Sort: new first, then by last_seen desc
        usort($list, function ($a, $b) {
            $sa = $a['status'] === 'new' ? 0 : ($a['status'] === 'acknowledged' ? 1 : 2);
            $sb = $b['status'] === 'new' ? 0 : ($b['status'] === 'acknowledged' ? 1 : 2);
            if ($sa !== $sb) return $sa - $sb;
            return strcmp(isset($b['last_seen']) ? $b['last_seen'] : '', isset($a['last_seen']) ? $a['last_seen'] : '');
        });
        anti_fraud_save($list);
        return count(array_filter($list, function ($i) { return $i['status'] === 'new'; }));
    }

    function anti_fraud_count_unack() {
        $list = anti_fraud_load();
        $n = 0;
        foreach ($list as $i) {
            if (($i['status'] ?? 'new') === 'new') $n++;
        }
        return $n;
    }

    function anti_fraud_set_status($user, $status, $by) {
        $list = anti_fraud_load();
        $now  = date('Y-m-d H:i:s');
        foreach ($list as &$i) {
            if (($i['user'] ?? '') === $user) {
                $i['status'] = $status;
                if (!isset($i['history']) || !is_array($i['history'])) $i['history'] = array();
                $i['history'][] = array('at' => $now, 'event' => $status, 'by' => $by);
            }
        }
        unset($i);
        return anti_fraud_save($list);
    }
}
