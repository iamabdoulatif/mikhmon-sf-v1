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
session_start();
// hide all error
error_reporting(0);
include_once(__DIR__ . '/../include/mikhmon_compat.php');

$clockRows = $API->comm("/system/clock/print");
$clock = isset($clockRows[0]) ? $clockRows[0] : array();
$nowDisplay = mikhmon_router_clock_display($clock, $_SESSION['timezone'] ?? 'UTC');
$nowDate = substr($nowDisplay, 0, 10);
$nowTime = substr($nowDisplay, 11, 8);

$allUsers = $API->comm("/ip/hotspot/user/print");
$getuser = array();
foreach ($allUsers as $candidate) {
  $limitUptime = isset($candidate['limit-uptime']) ? trim((string) $candidate['limit-uptime']) : '';
  $comment = isset($candidate['comment']) ? $candidate['comment'] : '';
  if ($limitUptime === '1s' || mikhmon_expiry_comment_is_expired($comment, $nowDate, $nowTime)) {
    $getuser[] = $candidate;
  }
}
$TotalReg = count($getuser);

$_SESSION['ubp'] = isset($getuser[0]['profile']) ? $getuser[0]['profile'] : "";
$_SESSION['ubc'] = "";

for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  $uid = $userdetails['.id'];

  $API->comm("/ip/hotspot/user/remove", array(
    ".id" => "$uid",
  ));
}
if ($_SESSION['ubp'] != "") {
  echo "<script>window.location='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'</script>";
} else {
  echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
}

?>
