<?php
declare(strict_types=1);

/**
 * api/v1/callbacks/reservation_account_confirmed.php
 *
 * The URL every institution is given for this callback, in each country's
 * endpoints.yaml, is:
 *
 *   https://vouchmorphn-production.up.railway.app/api/v1/callbacks/reservation_account_confirmed.php
 *
 * Nothing was served there. The handler sits at public/api/callback/ --
 * singular, no v1 -- so every push a bank made to the address we published
 * hit a 404. Not a rewrite, either: index.php is a one-line redirect rather
 * than a front controller, and production runs PHP's built-in server with no
 * router script, so a missing file is simply missing. It went unnoticed
 * because cron polling asks the banks rather than waiting to be told, and
 * covered for the dead push path.
 *
 * This forwards rather than moving the handler, for two reasons: any
 * institution already calling the old path keeps working, and __DIR__ inside
 * the handler stays correct (it resolves against its own file, and it climbs
 * three levels to reach the repo root -- from this directory that would need
 * four). One implementation, reachable at both addresses.
 */

require __DIR__ . '/../../callback/reservation_account_confirmed.php';
