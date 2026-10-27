<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

try {
    // A report GET must never delete data. Retain the original three-year cleanup as an explicit protected action.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        if (input_string($_POST, 'action') !== 'purge_expired') {
            http_response_code(422);
            exit(e(ui_t('Select a valid records action.')));
        }
        $conn->query("DELETE FROM delete_employee_logs WHERE deleted_time <= DATE_SUB(NOW(), INTERVAL 3 YEAR)");
        $cleanup_message = 'Expired deletion records were removed.';
    }

    $query = "
        SELECT employee_id, employee_name, employee_salary, employee_department,
                employee_position, employee_dob, employee_nin, deleted_by_id, deleted_by_name,
                reason, deleted_time,
                CASE
                    WHEN deleted_time <= DATE_SUB(NOW(), INTERVAL 3 YEAR) THEN 'Yes'
                    ELSE 'No'
                END AS over_three_years
        FROM delete_employee_logs
        ORDER BY deleted_time DESC
    ";
    $result = $conn->query($query);
    if (!$result) {
        throw new Exception("Error fetching deleted employee records: " . $conn->error);
    }
} catch (Exception $e) {
    error_log('Deletion record report failed: ' . $e->getMessage());
    http_response_code(500);
    $error_message = 'Deletion records are temporarily unavailable. Please try again later.';
} finally {
    $conn->close();
}
?>
<?php ui_page_start('Employee deletion records', 'Review employee deletion history and manage the three-year retention period.', ['current' => $current]); ?>
<?php if (isset($cleanup_message)): ?><?php ui_notice($cleanup_message, 'success'); ?><?php endif; ?>
<section class="panel" aria-labelledby="retention-title">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Record retention')); ?></p><h2 id="retention-title"><?php echo e(ui_t('Three-year archive cleanup')); ?></h2></div><span class="badge badge-warning"><?php echo e(ui_t('Permanent action')); ?></span></div>
    <p><?php echo e(ui_t('Deletion records dated three or more years ago are eligible for removal. Review the history below before clearing expired records.')); ?></p>
    <form method="POST" action="delete_records.php" data-confirm="<?php echo e(ui_t('Permanently remove all employee deletion records dated three or more years ago? This action cannot be undone.')); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="purge_expired">
        <div class="form-actions"><button type="submit" class="button button-danger"><?php echo e(ui_t('Remove expired records')); ?></button><span class="field-hint"><?php echo e(ui_t('This permanently removes eligible archive records.')); ?></span></div>
    </form>
</section>
<?php if (isset($error_message)): ?>
    <?php ui_notice($error_message, 'error'); ?>
<?php else: ?>
    <section class="panel" aria-labelledby="deletion-history-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Employee history')); ?></p><h2 id="deletion-history-title"><?php echo e(ui_t('Deletion records')); ?></h2><p class="muted"><?php echo e(ui_t('Most recent deletions appear first. Eligibility follows the three-year retention rule.')); ?></p></div><span class="badge badge-neutral"><?php echo e(ui_t('{count} records', ['count' => $result->num_rows])); ?></span></div>
        <?php if ($result->num_rows > 0): ?>
            <div class="table-scroll"><table class="data-table"><caption class="sr-only"><?php echo e(ui_t('Deleted employee records and retention status')); ?></caption><thead><tr><th scope="col"><?php echo e(ui_t('Employee ID')); ?></th><th scope="col"><?php echo e(ui_t('Employee')); ?></th><th scope="col"><?php echo e(ui_t('Salary')); ?></th><th scope="col"><?php echo e(ui_t('Department')); ?></th><th scope="col"><?php echo e(ui_t('Position')); ?></th><th scope="col"><?php echo e(ui_t('Date of birth')); ?></th><th scope="col"><?php echo e(ui_t('NIN')); ?></th><th scope="col"><?php echo e(ui_t('Deleted by ID')); ?></th><th scope="col"><?php echo e(ui_t('Deleted by')); ?></th><th scope="col"><?php echo e(ui_t('Reason')); ?></th><th scope="col"><?php echo e(ui_t('Deleted at')); ?></th><th scope="col"><?php echo e(ui_t('Cleanup eligible')); ?></th></tr></thead><tbody>
            <?php while ($row = $result->fetch_assoc()): ?><tr><td><?php echo e($row['employee_id']); ?></td><td><strong><?php echo e($row['employee_name']); ?></strong></td><td>£<?php echo e($row['employee_salary']); ?></td><td><?php echo e(ui_t($row['employee_department'])); ?></td><td><?php echo e(ui_t($row['employee_position'])); ?></td><td><?php echo e($row['employee_dob']); ?></td><td><?php echo e($row['employee_nin']); ?></td><td><?php echo e($row['deleted_by_id']); ?></td><td><?php echo e($row['deleted_by_name']); ?></td><td><?php echo e($row['reason']); ?></td><td><?php echo e($row['deleted_time']); ?></td><td><span class="badge <?php echo $row['over_three_years'] === 'Yes' ? 'badge-warning' : 'badge-neutral'; ?>"><?php echo e(ui_t($row['over_three_years'])); ?></span></td></tr><?php endwhile; ?>
            </tbody></table></div>
        <?php else: ?><div class="empty-state"><h3><?php echo e(ui_t('No deletion records')); ?></h3><p><?php echo e(ui_t('Employee deletion history will appear here when records are available.')); ?></p></div><?php endif; ?>
    </section>
<?php endif; ?>
<?php ui_page_end(); ?>
