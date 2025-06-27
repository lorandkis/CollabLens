<?php

$assignmentId = $_GET['assignment_id'] ?? '';

$queryParams = [
    'assignment_id' => $assignmentId
];

$query = http_build_query($queryParams);
$url = "http://api:4000/search?$query";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

$reports = $data['reports'] ?? [];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Group Reports</title>
    <link rel="icon" type="image/x-icon" href="/reasources/baj_logo.svg">
    <link rel="stylesheet" href="/reasources/css/uikit.min.css" />
    <script src="/reasources/js/uikit.min.js"></script>
    <script src="/reasources/js/uikit-icons.min.js"></script>
</head>
<body>

<!-- Nav Bar -->
<nav class="uk-navbar-container uk-light" style="background: rgba(0, 0, 0, 0.3);">
    <div class="uk-container">
        <div uk-navbar>
            <div class="uk-navbar-left">
                <a class="uk-navbar-item uk-logo" href="#">
                    <img src="reasources/baj_logo.svg" alt="BBJ Logo" style="height: 85px;">
                </a>
            </div>
            <div class="uk-navbar-center">
                <div class="uk-navbar-item"><h3>Group Reports</h3></div>
            </div>
        </div>
    </div>
</nav>

<div class="uk-grid-collapse uk-child-width-1-6@s uk-flex-nowrap" uk-grid>
    <!-- Sidebar -->
    <div class="uk-background-muted uk-padding">
        <h3>Dashboard</h3>
        <ul class="uk-nav uk-nav-default">
            <li class="uk-active"><a href="#">Reports</a></li>
            <li><a href="#">Applications</a></li>
            <li><a href="#">Settings</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="uk-width-expand uk-padding">
        <table class="uk-table uk-table-hover uk-table-striped">
            <thead>
                <tr>
                    <th>Group</th>
                    <th>Assignment</th>
                    <th>Dynamics Score</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $index => $report): ?>
                    <tr>
                        <td><?= htmlspecialchars($report['group_name']) ?></td>
                        <td><?= htmlspecialchars($report['assignment_title']) ?></td>
                        <td>
                            <span class="uk-label <?= 
                                $report['group_dynamics_score'] >= 85 ? 'uk-label-success' : 
                                ($report['group_dynamics_score'] >= 65 ? 'uk-label-warning' : 'uk-label-danger') ?>">
                                <?= (int)$report['group_dynamics_score'] ?> / 100
                            </span>
                        </td>
                        <td>
                            <button class="uk-button uk-button-default" uk-toggle="target: #modal-<?= $index ?>">View</button>
                        </td>
                    </tr>

                    <!-- Modal -->
                    <div id="modal-<?= $index ?>" uk-modal>
                        <div class="uk-modal-dialog uk-modal-body uk-width-1-1@s uk-height-large uk-overflow-auto">
                            <h3><?= htmlspecialchars($report['group_name']) ?> – <?= htmlspecialchars($report['assignment_title']) ?></h3>
                            <p><strong>Timeframe:</strong> <?= $report['timeframe']['start'] ?> → <?= $report['timeframe']['end'] ?></p>

                            <hr>
                            <h4>Members</h4>
                            <ul class="uk-list">
                                <?php foreach ($report['members'] as $member): ?>
                                    <li>
                                        <strong><?= htmlspecialchars($member['name']) ?></strong> (<?= htmlspecialchars($member['role']) ?>)
                                        <br>
                                        Messages: <?= $member['discord_messages'] ?> |
                                        Files: <?= $member['file_activities'] ?> |
                                        Participation: <?= htmlspecialchars($member['participation_level']) ?>
                                        <br>
                                        Notes: <?= nl2br(htmlspecialchars($member['notes'])) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <hr>
                            <h4>Collaboration Analysis</h4>
                            <p><strong>Dominant:</strong> <?= implode(', ', $report['collaboration_analysis']['dominant_participants']) ?></p>
                            <p><strong>Quiet:</strong> <?= implode(', ', $report['collaboration_analysis']['quiet_members']) ?></p>
                            <p><strong>Pattern:</strong> <?= htmlspecialchars($report['collaboration_analysis']['communication_pattern']) ?></p>
                            <p><strong>Quality:</strong> <?= htmlspecialchars($report['collaboration_analysis']['overall_quality']) ?></p>

                            <hr>
                            <h4>File Activity Analysis</h4>
                            <p><strong>Total Files:</strong> <?= $report['file_activity_analysis']['total_files_edited'] ?></p>
                            <p><strong>Rush Period:</strong> <?= htmlspecialchars($report['file_activity_analysis']['rush_period']) ?></p>
                            <p><strong>Consistent Contributors:</strong> <?= implode(', ', $report['file_activity_analysis']['consistent_contributors']) ?></p>
                            <p><strong>Notes:</strong> <?= nl2br(htmlspecialchars($report['file_activity_analysis']['notes'])) ?></p>

                            <hr>
                            <h4>AI Insights</h4>
                            <ul class="uk-list uk-list-bullet">
                                <?php foreach ($report['ai_insights'] as $insight): ?>
                                    <li><?= htmlspecialchars($insight) ?></li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="uk-margin-top">
                                <button class="uk-button uk-button-default uk-modal-close" type="button">Close</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
