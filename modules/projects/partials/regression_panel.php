<?php
/**
 * Regression Testing Panel — shared partial
 *
 * Expects the following PHP variables to be in scope:
 *   $projectId  (int)
 *   $userRole   (string)
 *   $baseDir    (string)
 *   $cspNonce   (string, may be empty)
 *
 * The panel loads stats and rounds via AJAX using regression-panel.js.
 */
$regressionPanelNonClientRoles = ['admin', 'project_lead', 'qa', 'at_tester', 'ft_tester'];
$isRegressionNonClient = in_array($userRole ?? '', $regressionPanelNonClientRoles);
?>

<!-- ===== Regression Testing Panel ===== -->
<div class="card mb-3" id="regressionPanel">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">
                <i class="fas fa-sync-alt text-primary me-2"></i>Regression Testing
            </h5>
            <div class="small text-muted mt-1" id="regressionActiveRoundBadge"></div>
        </div>
        <?php if ($isRegressionNonClient): ?>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (in_array($userRole ?? '', ['admin', 'project_lead', 'qa'])): ?>
            <button type="button" class="btn btn-sm btn-primary" id="btnNewRegressionRound">
                <i class="fas fa-plus me-1"></i>New Round
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    id="btnToggleRegressionRounds"
                    data-bs-toggle="collapse"
                    data-bs-target="#regressionRoundsCollapse"
                    aria-expanded="false"
                    aria-controls="regressionRoundsCollapse">
                <i class="fas fa-history me-1"></i>Rounds
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body py-3">
        <div id="regressionStatsContainer">
            <div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading regression stats…</div>
        </div>
    </div>

    <?php if ($isRegressionNonClient): ?>
    <div class="collapse" id="regressionRoundsCollapse">
        <div class="border-top p-3 bg-light">
            <h6 class="mb-3 text-muted fw-semibold">
                <i class="fas fa-history me-1"></i>Regression Rounds
            </h6>
            <div id="regressionRoundsList">
                <div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading rounds…</div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="regressionConfirmModal" tabindex="-1" aria-labelledby="regressionConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="regressionConfirmModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="regressionConfirmModalBody">
                    Are you sure?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="regressionConfirmModalYes">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="regressionRoundDetailModal" tabindex="-1" aria-labelledby="regressionRoundDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="regressionRoundDetailModalLabel">Regression Round Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="regressionRoundDetailModalBody">
                    <div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading round details…</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<!-- ===== /Regression Testing Panel ===== -->
