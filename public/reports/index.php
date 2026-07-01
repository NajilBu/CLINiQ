<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

render_header('Reports');
?>
<h1 class="h3 mb-4">Reports</h1>
<section class="content-panel">
    <p class="text-secondary mb-0">Report generation will include clinic visits, medicine usage, emergency alerts, referrals, and APE verification status.</p>
</section>
<?php render_footer(); ?>
