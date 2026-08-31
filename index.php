<?php
require_once(__DIR__ . '/crest.php');

// 1. Parse Bitrix24 placement context and extract USER_ID
$placementOptions = [];
if (!empty($_REQUEST['PLACEMENT_OPTIONS'])) {
    if (is_array($_REQUEST['PLACEMENT_OPTIONS'])) {
        $placementOptions = $_REQUEST['PLACEMENT_OPTIONS'];
    } else {
        $placementOptions = json_decode($_REQUEST['PLACEMENT_OPTIONS'], true) ?: [];
    }
}

$userId = $_REQUEST['params']['USER_ID'] ?? ($placementOptions['USER_ID'] ?? null);

// Fallback to active viewer if opened directly or outside employee card placement
if (!$userId) {
    $currentUserResult = CRestCurrent::call('user.current');
    $userId = $currentUserResult['result']['ID'] ?? null;
}

// 2. Compute date range filters based on selection
$dateRange = $_REQUEST['date_range'] ?? 'today';
$customStart = $_REQUEST['start_date'] ?? '';
$customEnd = $_REQUEST['end_date'] ?? '';

// Determine timezone (Asia/Dubai is default for Capital Western Group)
$timezone = new DateTimeZone('Asia/Dubai');
$now = new DateTime('now', $timezone);

switch ($dateRange) {
    case 'yesterday':
        $start = clone $now;
        $start->modify('-1 day');
        $startDate = $start->format('Y-m-d');
        $endDate = $startDate;
        break;
    case 'week':
        $start = clone $now;
        $start->modify('-6 days');
        $startDate = $start->format('Y-m-d');
        $endDate = $now->format('Y-m-d');
        break;
    case 'month':
        $startDate = $now->format('Y-m-01');
        $endDate = $now->format('Y-m-t');
        break;
    case 'custom':
        $startDate = !empty($customStart) ? $customStart : $now->format('Y-m-d');
        $endDate = !empty($customEnd) ? $customEnd : $now->format('Y-m-d');
        break;
    case 'today':
    default:
        $startDate = $now->format('Y-m-d');
        $endDate = $startDate;
        break;
}

// 3. Fetch Bitrix24 user details
$b24User = null;
$b24UserError = null;
if ($userId) {
    $userResult = CRestCurrent::call('user.get', ['ID' => $userId]);
    if (!empty($userResult['result'][0])) {
        $b24User = $userResult['result'][0];
    } else {
        $b24UserError = $userResult['error_description'] ?? 'User not found';
    }
}

// 4. Fetch Hikvision attendance logs with caching (2 minutes cache duration)
$cacheFile = __DIR__ . '/hikvision_cache.json';
$cacheDuration = 120;
$hikLogs = [];
$cacheAge = 0;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheDuration)) {
    $hikLogs = json_decode(file_get_contents($cacheFile), true) ?: [];
    $cacheAge = time() - filemtime($cacheFile);
} else {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, HIKVISION_API_URL . "?token=" . HIKVISION_TOKEN);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $responseStr = curl_exec($ch);
    
    if (!curl_errno($ch)) {
        $responseJson = json_decode($responseStr, true);
        if (!empty($responseJson) && isset($responseJson['success']) && $responseJson['success']) {
            $hikLogs = $responseJson['data'] ?? [];
            file_put_contents($cacheFile, json_encode($hikLogs));
        }
    }
    curl_close($ch);
    
    // Fallback to expired cache if fetching failed
    if (empty($hikLogs) && file_exists($cacheFile)) {
        $hikLogs = json_decode(file_get_contents($cacheFile), true) ?: [];
    }
}

// 5. Compile unique list of Hikvision employees and match
$uniqueHikEmployees = [];
foreach ($hikLogs as $record) {
    $empId = $record['employee_id'] ?? null;
    if ($empId !== null && !isset($uniqueHikEmployees[$empId])) {
        $uniqueHikEmployees[$empId] = [
            'id' => $empId,
            'name' => $record['employee_name'] ?? ''
        ];
    }
}

// Dynamic Matching Engine
function cleanName($name) {
    if (empty($name)) return '';
    return trim(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)));
}

function calculateMatchScore($b24User, $hikEmpName) {
    $cleanHik = cleanName($hikEmpName);
    $cleanFirst = cleanName($b24User['NAME'] ?? '');
    $cleanLast = cleanName($b24User['LAST_NAME'] ?? '');
    $cleanFull = cleanName(($b24User['NAME'] ?? '') . ($b24User['LAST_NAME'] ?? ''));

    if (empty($cleanHik)) return 0;

    // Exact matches
    if ($cleanFirst === $cleanHik) return 100;
    if ($cleanFull === $cleanHik) return 95;
    if ($cleanLast === $cleanHik) return 90;

    // Substring matches
    if (strlen($cleanFirst) > 2 && (strpos($cleanHik, $cleanFirst) !== false || strpos($cleanFirst, $cleanHik) !== false)) {
        return 80;
    }
    if (strlen($cleanFull) > 2 && (strpos($cleanHik, $cleanFull) !== false || strpos($cleanFull, $cleanHik) !== false)) {
        return 75;
    }

    // Levenshtein fuzzy matching
    $distFirst = levenshtein($cleanFirst, $cleanHik);
    if ($distFirst <= 1 && max(strlen($cleanFirst), strlen($cleanHik)) > 3) {
        return 70;
    }
    $distFull = levenshtein($cleanFull, $cleanHik);
    if ($distFull <= 1 && max(strlen($cleanFull), strlen($cleanHik)) > 3) {
        return 65;
    }

    return 0;
}

$bestMatch = null;
$bestScore = 0;
if ($b24User) {
    foreach ($uniqueHikEmployees as $hikEmp) {
        $score = calculateMatchScore($b24User, $hikEmp['name']);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $hikEmp;
        }
    }
}

$matchedEmployee = ($bestScore >= 70) ? $bestMatch : null;

// 6. Filter employee's events by date range and determine attendance
$employeeEvents = [];
$hasAuthEvent = false;
$firstCheckIn = null;
$lastCheckIn = null;
$lastKnownEvent = null;

if ($matchedEmployee) {
    foreach ($hikLogs as $record) {
        if (($record['employee_id'] ?? null) == $matchedEmployee['id']) {
            $recordDate = !empty($record['event_date']) ? $record['event_date'] : date('Y-m-d', strtotime($record['event_time']));
            
            // Check check-in within selected date range
            if ($recordDate >= $startDate && $recordDate <= $endDate) {
                $employeeEvents[] = $record;
                $isAuth = (strcasecmp($record['event_type'] ?? '', 'Authenticated') === 0) || 
                          (strcasecmp($record['status_badge'] ?? '', 'authenticated') === 0);
                
                if ($isAuth) {
                    $hasAuthEvent = true;
                    $timeStr = date('H:i:s', strtotime($record['event_time']));
                    if ($firstCheckIn === null || $timeStr < $firstCheckIn) {
                        $firstCheckIn = $timeStr;
                    }
                    if ($lastCheckIn === null || $timeStr > $lastCheckIn) {
                        $lastCheckIn = $timeStr;
                    }
                }
            }
            
            // Find overall last known auth event for contextual absent fallback
            $isAuth = (strcasecmp($record['event_type'] ?? '', 'Authenticated') === 0) || 
                      (strcasecmp($record['status_badge'] ?? '', 'authenticated') === 0);
            if ($isAuth) {
                if ($lastKnownEvent === null || $record['event_time'] > $lastKnownEvent['event_time']) {
                    $lastKnownEvent = $record;
                }
            }
        }
    }
    // Sort events by time descending
    usort($employeeEvents, function($a, $b) {
        return strcmp($b['event_time'], $a['event_time']);
    });
}

// 7. Count CRM entities assigned to user in date range
function getLeadsCount($userId, $startDate, $endDate) {
    if (!$userId) return 0;
    $res = CRestCurrent::call('crm.item.list', [
        'entityTypeId' => 1,
        'filter' => [
            'assignedById' => $userId,
            '>=createdTime' => $startDate . 'T00:00:00+03:00',
            '<=createdTime' => $endDate . 'T23:59:59+03:00'
        ],
        'select' => ['id']
    ]);
    if (isset($res['result']['items'])) {
        return $res['total'] ?? count($res['result']['items']);
    }
    // Fallback to deprecated crm.lead.list
    $resOld = CRestCurrent::call('crm.lead.list', [
        'filter' => [
            'ASSIGNED_BY_ID' => $userId,
            '>=DATE_CREATE' => $startDate . 'T00:00:00+03:00',
            '<=DATE_CREATE' => $endDate . 'T23:59:59+03:00'
        ],
        'select' => ['ID']
    ]);
    return $resOld['total'] ?? (isset($resOld['result']) ? count($resOld['result']) : 0);
}

function getDealsCount($userId, $startDate, $endDate) {
    if (!$userId) return 0;
    $res = CRestCurrent::call('crm.item.list', [
        'entityTypeId' => 2,
        'filter' => [
            'assignedById' => $userId,
            '>=createdTime' => $startDate . 'T00:00:00+03:00',
            '<=createdTime' => $endDate . 'T23:59:59+03:00'
        ],
        'select' => ['id']
    ]);
    if (isset($res['result']['items'])) {
        return $res['total'] ?? count($res['result']['items']);
    }
    // Fallback to deprecated crm.deal.list
    $resOld = CRestCurrent::call('crm.deal.list', [
        'filter' => [
            'ASSIGNED_BY_ID' => $userId,
            '>=DATE_CREATE' => $startDate . 'T00:00:00+03:00',
            '<=DATE_CREATE' => $endDate . 'T23:59:59+03:00'
        ],
        'select' => ['ID']
    ]);
    return $resOld['total'] ?? (isset($resOld['result']) ? count($resOld['result']) : 0);
}

function getSpa1088Count($userId, $startDate, $endDate) {
    if (!$userId) return 0;
    $res = CRestCurrent::call('crm.item.list', [
        'entityTypeId' => 1088,
        'filter' => [
            'assignedById' => $userId,
            '>=createdTime' => $startDate . 'T00:00:00+03:00',
            '<=createdTime' => $endDate . 'T23:59:59+03:00'
        ],
        'select' => ['id']
    ]);
    if (isset($res['result']['items'])) {
        return $res['total'] ?? count($res['result']['items']);
    }
    return 0;
}

$leadsCount = getLeadsCount($userId, $startDate, $endDate);
$dealsCount = getDealsCount($userId, $startDate, $endDate);
$spaCount = getSpa1088Count($userId, $startDate, $endDate);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Performance & Attendance Profile</title>
    <link rel="stylesheet" href="style.css">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="glow-background"></div>

    <div class="container">
        <!-- Dashboard Header -->
        <div class="header">
            <div class="title-area">
                <h1>Employee Card Extension</h1>
                <p>Hikvision Check-ins & CRM Statistics Portal</p>
            </div>
            
            <!-- Date Filter Selector -->
            <form class="date-selector-form" method="POST" id="dateFilterForm">
                <!-- Keep Bitrix context params so form POSTs do not lose iframe session context -->
                <input type="hidden" name="PLACEMENT" value="<?php echo htmlspecialchars($_REQUEST['PLACEMENT'] ?? ''); ?>">
                <input type="hidden" name="PLACEMENT_OPTIONS" value="<?php echo htmlspecialchars(is_array($_REQUEST['PLACEMENT_OPTIONS'] ?? '') ? json_encode($_REQUEST['PLACEMENT_OPTIONS']) : ($_REQUEST['PLACEMENT_OPTIONS'] ?? '')); ?>">
                <input type="hidden" name="AUTH_ID" value="<?php echo htmlspecialchars($_REQUEST['AUTH_ID'] ?? ''); ?>">
                <input type="hidden" name="DOMAIN" value="<?php echo htmlspecialchars($_REQUEST['DOMAIN'] ?? ''); ?>">
                <input type="hidden" name="REFRESH_ID" value="<?php echo htmlspecialchars($_REQUEST['REFRESH_ID'] ?? ''); ?>">
                <input type="hidden" name="APP_SID" value="<?php echo htmlspecialchars($_REQUEST['APP_SID'] ?? ''); ?>">

                <select name="date_range" onchange="toggleCustomDates(this.value)">
                    <option value="today" <?php echo $dateRange === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="yesterday" <?php echo $dateRange === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                    <option value="week" <?php echo $dateRange === 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="month" <?php echo $dateRange === 'month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="custom" <?php echo $dateRange === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                
                <div id="custom-dates" style="display: <?php echo $dateRange === 'custom' ? 'flex' : 'none'; ?>; gap: 8px; align-items: center;">
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                    <span style="font-size: 12px; color: var(--text-secondary);">to</span>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                
                <button type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            </form>
        </div>

        <?php if ($b24UserError): ?>
            <div class="glass-card" style="border-color: var(--status-absent);">
                <h3 style="color: var(--status-absent); margin-top:0;"><i class="fa-solid fa-triangle-exclamation"></i> Error loading profile</h3>
                <p><?php echo htmlspecialchars($b24UserError); ?></p>
            </div>
        <?php elseif ($b24User): ?>
            <!-- Main Grid: Attendance Card + Profile Mapping Card -->
            <div class="main-grid">
                <!-- Left: Attendance Card -->
                <div class="glass-card attendance-status">
                    <?php if (!$matchedEmployee): ?>
                        <div class="badge badge-absent">
                            <span class="status-dot"></span> Unmapped User
                        </div>
                        <div class="time-info" style="font-size: 20px; color: var(--text-secondary); margin: 20px 0;">
                            No Hikvision profile matched
                        </div>
                        <p style="margin: 0; font-size: 13px;">Please ensure the user's name matches their credentials on the Hikvision terminal.</p>
                    <?php elseif ($hasAuthEvent): ?>
                        <div class="badge badge-attended">
                            <span class="status-dot"></span> Attended
                        </div>
                        <div class="time-info">
                            <?php echo date('h:i A', strtotime($firstCheckIn)); ?>
                        </div>
                        <div class="date-info">
                            First Check-In (Dubai Time)
                        </div>
                        <p style="margin: 0; font-size: 12px; color: var(--status-attended);">
                            <i class="fa-solid fa-circle-check"></i> Present in the selected range (Latest: <?php echo date('h:i A', strtotime($lastCheckIn)); ?>).
                        </p>
                    <?php else: ?>
                        <div class="badge badge-absent">
                            <span class="status-dot"></span> Absent
                        </div>
                        <div class="time-info" style="color: #fb7185;">
                            No Check-In
                        </div>
                        <div class="date-info">
                            Absent in selected range
                        </div>
                        <?php if ($lastKnownEvent): ?>
                            <p style="margin: 0; font-size: 12px; color: var(--text-secondary);">
                                <i class="fa-solid fa-clock-rotate-left"></i> Last seen: 
                                <strong><?php echo date('Y-m-d h:i A', strtotime($lastKnownEvent['event_time'])); ?></strong>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Right: Profile Details & Mapping Details -->
                <div class="glass-card">
                    <h3 style="margin-top: 0; font-size: 15px; font-weight: 600; color: var(--accent-blue); display: flex; justify-content: space-between; align-items: center;">
                        <span><i class="fa-solid fa-user-gear"></i> Profile Mapping</span>
                        <span class="match-badge">Bitrix24 User</span>
                    </h3>
                    <div class="profile-mapping">
                        <div class="profile-field">
                            <span class="field-label">Employee ID</span>
                            <span class="field-value">#<?php echo htmlspecialchars($b24User['ID']); ?></span>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Name</span>
                            <span class="field-value"><?php echo htmlspecialchars(($b24User['NAME'] ?? '') . ' ' . ($b24User['LAST_NAME'] ?? '')); ?></span>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Email</span>
                            <span class="field-value" style="font-size: 12px; color: var(--text-secondary);"><?php echo htmlspecialchars($b24User['EMAIL'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="profile-field">
                            <span class="field-label">Hikvision Match</span>
                            <span class="field-value">
                                <?php if ($matchedEmployee): ?>
                                    <span style="color: #34d399; font-weight: 600;"><?php echo htmlspecialchars($matchedEmployee['name']); ?></span> 
                                    <span style="font-size: 11px; color: var(--text-muted);"> (ID: <?php echo htmlspecialchars($matchedEmployee['id']); ?>)</span>
                                <?php else: ?>
                                    <span style="color: #fb7185; font-weight: 600;">None</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($matchedEmployee): ?>
                            <div class="profile-field">
                                <span class="field-label">Match Score</span>
                                <span class="field-value" style="color: var(--accent-blue); font-weight: 600;"><?php echo $bestScore; ?>% Match</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- CRM Metrics Card Section -->
            <div class="metrics-row">
                <!-- Leads Card -->
                <div class="metric-card">
                    <div class="metric-title"><i class="fa-solid fa-filter-list"></i> Leads</div>
                    <div class="metric-value"><?php echo $leadsCount; ?></div>
                </div>
                <!-- Deals Card -->
                <div class="metric-card">
                    <div class="metric-title"><i class="fa-solid fa-handshake"></i> Deals</div>
                    <div class="metric-value"><?php echo $dealsCount; ?></div>
                </div>
                <!-- SPA 1088 Card -->
                <div class="metric-card">
                    <div class="metric-title"><i class="fa-solid fa-briefcase"></i> SPA 1088</div>
                    <div class="metric-value"><?php echo $spaCount; ?></div>
                </div>
            </div>

            <!-- Recent Hikvision Event Logs (List) -->
            <div class="glass-card logs-section" style="margin-bottom: 0;">
                <h3><i class="fa-solid fa-timeline"></i> Recent Access Logs (Selected Range)</h3>
                <?php if (empty($employeeEvents)): ?>
                    <p style="color: var(--text-muted); font-size: 13px; text-align: center; margin: 20px 0;">
                        No logs registered in this date range.
                    </p>
                <?php else: ?>
                    <?php 
                    // Show maximum 5 recent events in list
                    $displayCount = 0;
                    foreach ($employeeEvents as $event): 
                        if ($displayCount >= 5) break;
                        $displayCount++;
                        $isAuth = (strcasecmp($event['event_type'] ?? '', 'Authenticated') === 0) || 
                                  (strcasecmp($event['status_badge'] ?? '', 'authenticated') === 0);
                    ?>
                        <div class="log-item" style="<?php echo $isAuth ? 'border-left: 3px solid var(--status-attended);' : ''; ?>">
                            <div class="log-meta">
                                <span class="log-title">
                                    <?php echo htmlspecialchars($event['event_type'] ?? 'Access Event'); ?> 
                                    <?php if ($isAuth): ?>
                                        <span style="color: #34d399; font-size: 11px; margin-left: 6px;"><i class="fa-solid fa-circle-check"></i> Authenticated</span>
                                    <?php endif; ?>
                                </span>
                                <span class="log-reader"><i class="fa-solid fa-fingerprint"></i> Reader ID: <?php echo htmlspecialchars($event['card_reader_id'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="log-time">
                                <div><?php echo date('Y-m-d', strtotime($event['event_time'])); ?></div>
                                <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;"><?php echo date('h:i:s A', strtotime($event['event_time'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleCustomDates(value) {
            const customDiv = document.getElementById('custom-dates');
            if (value === 'custom') {
                customDiv.style.display = 'flex';
            } else {
                customDiv.style.display = 'none';
            }
        }
    </script>
</body>
</html>
