<?php
require_once __DIR__ . '/../../includes/functions.php';
$projectId = $_GET['project_id'] ?? 0;
header("Location: ../projects/manage_assignments.php?project_id=$projectId&tab=team");
exit;
