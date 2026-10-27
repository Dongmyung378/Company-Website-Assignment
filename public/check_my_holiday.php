<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_login();
$employee_id = (int) $current['employee_id'];
$holiday_requests = [];
$stmt = $conn->prepare('SELECT request_id, leave_type, start_date, end_date, reason, status, updated_at FROM holiday_requests WHERE employee_id = ? ORDER BY updated_at DESC, request_id DESC');
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $holiday_requests[] = $row;
}
$stmt->close();
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($holiday_requests as $request) {
    if (isset($counts[$request['status']])) {
        $counts[$request['status']]++;
    }
}
$statusClasses = ['Pending' => 'badge-warning', 'Approved' => 'badge-success', 'Rejected' => 'badge-danger'];
ui_page_start('My leave history', 'Track your time away and the progress of each request.', ['current' => $current]);
?>
<div class="stat-grid">
    <section class="stat-card"><p class="stat-label"><?php echo e(ui_t('Awaiting approval')); ?></p><p class="stat-value"><?php echo e($counts['Pending']); ?></p><p class="stat-note"><?php echo e(ui_t('Requests with your administrator')); ?></p></section>
    <section class="stat-card"><p class="stat-label"><?php echo e(ui_t('Approved')); ?></p><p class="stat-value"><?php echo e($counts['Approved']); ?></p><p class="stat-note"><?php echo e(ui_t('Requests confirmed')); ?></p></section>
    <section class="stat-card"><p class="stat-label"><?php echo e(ui_t('All requests')); ?></p><p class="stat-value"><?php echo e(count($holiday_requests)); ?></p><p class="stat-note"><?php echo e(ui_t('Your complete request history')); ?></p></section>
</div>
<section class="panel" aria-labelledby="history-title">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('For {name}', ['name' => $current['name']])); ?></p><h2 id="history-title"><?php echo e(ui_t('Request history')); ?></h2><p class="muted"><?php echo e(ui_t('Calendar days include both dates and weekends. Your balance changes when a request is approved.')); ?></p></div><a class="button" href="holiday_requests.php"><?php echo e(ui_t('Request leave')); ?></a></div>
    <?php if ($holiday_requests): ?>
        <div class="table-scroll"><table class="data-table">
            <thead><tr><th scope="col"><?php echo e(ui_t('Request ID')); ?></th><th scope="col"><?php echo e(ui_t('Leave type')); ?></th><th scope="col"><?php echo e(ui_t('Dates')); ?></th><th scope="col"><?php echo e(ui_t('Days')); ?></th><th scope="col"><?php echo e(ui_t('Reason')); ?></th><th scope="col"><?php echo e(ui_t('Status')); ?></th><th scope="col"><?php echo e(ui_t('Last updated')); ?></th></tr></thead>
            <tbody>
                <?php foreach ($holiday_requests as $request): ?>
                    <?php $validRequestDates = valid_date($request['start_date']) && valid_date($request['end_date']) && $request['end_date'] >= $request['start_date']; ?>
                    <tr>
                        <td>#<?php echo e($request['request_id']); ?></td>
                        <td><strong><?php echo e(ui_t($request['leave_type'])); ?></strong></td>
                        <td><time datetime="<?php echo e($request['start_date']); ?>"><?php echo e($request['start_date']); ?></time><br><span class="muted"><?php echo e(ui_t('to')); ?></span> <time datetime="<?php echo e($request['end_date']); ?>"><?php echo e($request['end_date']); ?></time></td>
                        <td><?php echo $validRequestDates ? e(calendar_days($request['start_date'], $request['end_date'])) : e(ui_t('Unavailable')); ?></td>
                        <td><?php echo e($request['reason'] ?: ui_t('No reason provided')); ?></td>
                        <td><span class="badge <?php echo e($statusClasses[$request['status']] ?? 'badge-neutral'); ?>"><?php echo e(ui_t($request['status'])); ?></span></td>
                        <td><time datetime="<?php echo e(str_replace(' ', 'T', $request['updated_at'])); ?>"><?php echo e($request['updated_at']); ?></time></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <div class="empty-state"><span class="badge badge-neutral"><?php echo e(ui_t('No requests')); ?></span><h3><?php echo e(ui_t('No leave requests yet')); ?></h3><p class="muted"><?php echo e(ui_t('When you request time away, you can follow its approval status here.')); ?></p><a class="button button-secondary" href="holiday_requests.php"><?php echo e(ui_t('Make your first request')); ?></a></div>
    <?php endif; ?>
</section>
<?php ui_page_end(); ?>
