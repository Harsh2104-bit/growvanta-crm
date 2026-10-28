<?php
declare(strict_types=1);

/**
 * Single include point for every entry script (api/index.php, webhooks/*.php,
 * cron/*.php). Loads config, every lib/ helper, and the domain services.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to the client; see api/index.php's handler

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/errors.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/permissions.php';
require_once __DIR__ . '/lib/scope.php';
require_once __DIR__ . '/lib/tenancy.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/apikey.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/rate_limit.php';
require_once __DIR__ . '/lib/csv.php';
require_once __DIR__ . '/lib/xlsx.php';
require_once __DIR__ . '/lib/validate.php';

require_once __DIR__ . '/services/Events.php';
require_once __DIR__ . '/services/Conditions.php';
require_once __DIR__ . '/services/Duplicates.php';
require_once __DIR__ . '/services/Assignment.php';
require_once __DIR__ . '/services/Scoring.php';
require_once __DIR__ . '/services/CustomFields.php';
require_once __DIR__ . '/services/SequenceCodes.php';
require_once __DIR__ . '/services/LeadService.php';
require_once __DIR__ . '/services/Notifications.php';
require_once __DIR__ . '/services/Automation.php';
require_once __DIR__ . '/services/Webhooks.php';
require_once __DIR__ . '/services/Filters.php';
require_once __DIR__ . '/services/Reports.php';
require_once __DIR__ . '/services/Projects.php';
require_once __DIR__ . '/services/Comms.php';
require_once __DIR__ . '/services/StaffNotify.php';
require_once __DIR__ . '/services/Provisioning.php';
require_once __DIR__ . '/services/MetaLeads.php';
require_once __DIR__ . '/services/MetaConnect.php';

// Register the listeners that turn "lead.created" etc. into real side effects.
register_automation_listeners();
register_webhook_listeners();
register_notification_listeners();
register_comms_listeners();
register_staff_notify_listeners();
