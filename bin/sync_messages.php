<?php

declare(strict_types=1);

/**
 * Sync conversations, mail, and optionally support cases.
 *
 * Usage:
 *   php bin/sync_messages.php [--tenant=ID] [--mail] [--conversations] [--cases] [--days=14]
 * Defaults: mail + conversations.
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Integrations\ConversationSync;
use App\Integrations\MessageSync;
use App\Integrations\SupportCaseSync;

$base = dirname(__DIR__);
$app = new App($base);

$tenantId = 0;
$days = 14;
$doMail = false;
$doConv = false;
$doCases = false;
$explicit = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, '--days=')) {
        $days = max(1, min(180, (int) substr($arg, 7)));
    } elseif ($arg === '--mail') {
        $doMail = true;
        $explicit = true;
    } elseif ($arg === '--conversations') {
        $doConv = true;
        $explicit = true;
    } elseif ($arg === '--cases') {
        $doCases = true;
        $explicit = true;
    }
}
if (!$explicit) {
    $doMail = true;
    $doConv = true;
}

$tenants = [];
if ($tenantId > 0) {
    $tenants = [['id' => $tenantId]];
} else {
    $tenants = $app->db->query(
        "SELECT id FROM #__store_tenants
         WHERE status IN ('active','open','trial') OR status='' OR status IS NULL
         ORDER BY id ASC"
    )->rows;
}

$exit = 0;
foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    $app->tenant->setActive($tid);
    echo "tenant {$tid}\n";

    if ($doMail) {
        try {
            $r = (new MessageSync($app))->syncAll($tid, min(30, $days));
            echo sprintf(
                "  mail: channels %d, imported %d, updated %d\n",
                $r['channels'] ?? 0,
                $r['imported'] ?? 0,
                $r['updated'] ?? 0
            );
            foreach (array_slice($r['errors'] ?? [], 0, 3) as $err) {
                fwrite(STDERR, "  mail error: {$err}\n");
                $exit = 1;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '  mail: ' . $e->getMessage() . "\n");
            $exit = 1;
        }
    }

    if ($doConv) {
        try {
            $r = (new ConversationSync($app))->syncAll($tid, 5, 10);
            echo sprintf(
                "  conversations: channels %d, created %d, updated %d, messages %d\n",
                $r['channels'] ?? 0,
                $r['created'] ?? 0,
                $r['updated'] ?? 0,
                $r['messages'] ?? 0
            );
            foreach (array_slice($r['errors'] ?? [], 0, 3) as $err) {
                fwrite(STDERR, "  conversation error: {$err}\n");
                $exit = 1;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '  conversations: ' . $e->getMessage() . "\n");
            $exit = 1;
        }
    }

    if ($doCases) {
        try {
            $r = (new SupportCaseSync($app))->syncAll($tid, max(30, $days));
            echo sprintf(
                "  cases: channels %d, created %d, updated %d, cases %d, inquiries %d\n",
                $r['channels'] ?? 0,
                $r['created'] ?? 0,
                $r['updated'] ?? 0,
                $r['cases'] ?? 0,
                $r['inquiries'] ?? 0
            );
            foreach (array_slice($r['errors'] ?? [], 0, 3) as $err) {
                fwrite(STDERR, "  case error: {$err}\n");
                $exit = 1;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '  cases: ' . $e->getMessage() . "\n");
            $exit = 1;
        }
    }
}

exit($exit);
