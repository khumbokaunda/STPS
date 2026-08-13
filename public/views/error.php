<?php /** @var int $code */ /** @var string $message */ ?>
<section class="card narrow">
  <h2>Error <?= $e($code) ?></h2>
  <p><?= $e($message) ?></p>
  <p><a href="/">Return to the dashboard</a></p>
</section>
