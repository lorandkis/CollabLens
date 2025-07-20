<?php
$groupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
if ($groupId <= 0) {
    die("Invalid or missing group_id");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Group Report – Group <?= htmlspecialchars($groupId) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.18.1/dist/css/uikit.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.18.1/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.18.1/dist/js/uikit-icons.min.js"></script>
    <style>
        .skeleton {
            background: linear-gradient(-90deg, #f0f0f0 0%, #e0e0e0 50%, #f0f0f0 100%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite linear;
        }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body class="uk-padding">
<div class="uk-container uk-container-large">

<!-- Header -->
    <nav class="uk-navbar-container uk-navbar-transparent" uk-navbar>
      <div class="uk-navbar-left">
        <a class="uk-navbar-item uk-logo" href="#">
          <span class="uk-margin-small-left">CollabLens Demo</span>
        </a>
      </div>
      <div class="uk-navbar-right">
        <ul class="uk-navbar-nav">
          <li><a href="#" onclick="window.location.href='./'" class="nav-link">Main Dashboard</a></li>
        </ul>
      </div>
    </nav>

    <h1 class="uk-heading-line"><span>🧾 Student Group Assignment Report</span></h1>

    <div id="loading" class="uk-flex uk-flex-center uk-margin-large-top">
        <div>
            <div uk-spinner="ratio: 2"></div>
            <p class="uk-text-center uk-margin-small-top">Generating report... Please wait.</p>
        </div>
    </div>

    <div id="report" class="uk-hidden">
        <div id="report-content"></div>
    </div>

</div>

<script>
    const groupId = <?= $groupId ?>;
    const reportDiv = document.getElementById('report');
    const contentDiv = document.getElementById('report-content');
    const loadingDiv = document.getElementById('loading');

    async function loadReport() {
        try {
            const response = await fetch(`http://localhost:4000/group-report?group_id=${groupId}`);
            const data = await response.json();

            if (!data.group_name) {
                throw new Error('Invalid report format');
            }

            // Hide loading
            loadingDiv.classList.add('uk-hidden');
            reportDiv.classList.remove('uk-hidden');

            // Inject report HTML
            contentDiv.innerHTML = `
            <br>
            <div class="uk-container uk-container-xsmall">
                <h3>1. Group Overview</h3>
                <p><strong>Group Name:</strong> ${data.group_name}</p>
                <p><strong>Assignment:</strong> ${data.assignment_title}</p>
                <p><strong>Timeframe:</strong> ${data.timeframe.start} to ${data.timeframe.end}</p>
                <ul>
                    ${data.members.map(m => `
                        <li><strong>${m.name}</strong> – ${m.role} 
                        <br>Messages: ${m.discord_messages}, Files: ${m.file_activities}, Participation: ${m.participation_level}
                        <br>Notes: ${m.notes}</li>
                    `).join('')}
                </ul>
            </div>

            <br><br>
            <div class="uk-grid-match uk-child-width-1-3@m uk-grid-small" uk-grid>
                <div>
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3 class="uk-card-title">2. Collaboration Analysis</h3>
                        <ul>
                            <li><strong>Dominant Participants:</strong> ${data.collaboration_analysis.dominant_participants}</li>
                            <li><strong>Quiet Members:</strong> ${data.collaboration_analysis.quiet_members}</li>
                            <li><strong>Pattern:</strong> ${data.collaboration_analysis.communication_pattern}</li>
                            <li><strong>Overall Quality:</strong> ${data.collaboration_analysis.overall_quality}</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3 class="uk-card-title">3. File Activity Analysis</h3>
                        <ul>
                            <li><strong>Total Files Edited:</strong> ${data.file_activity_analysis.total_files_edited}</li>
                            <li><strong>Rush Period:</strong> ${data.file_activity_analysis.rush_period}</li>
                            <li><strong>Consistent Contributors:</strong> ${data.file_activity_analysis.consistent_contributors}</li>
                            <li><strong>Notes:</strong> ${data.file_activity_analysis.notes}</li>
                        </ul>
                    </div>
                </div>

                <div>
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3 class="uk-card-title">4. AI Insights</h3>
                        <ul>
                            ${data.ai_insights.map(i => `<li>${i}</li>`).join('')}
                        </ul>
                    </div>
                </div>
            </div>

            <h3 class="uk-margin-large-top">5. Group Dynamics Score</h3>
            <p><strong>Score:</strong> ${data.group_dynamics_score} / 100</p>
        `;

        } catch (error) {
            loadingDiv.innerHTML = `
                <div class="uk-alert-danger" uk-alert>
                    <p>⚠️ Failed to load report: ${error.message}</p>
                </div>
            `;
            console.error(error);
        }
    }

    loadReport();
</script>
</body>
</html>
