<?php
/** @var string $message */
?>
<h1>We'll be back soon</h1>
<p><?= $message !== '' ? nl2br(e($message)) : e(brand_name()) . ' is closed for a short while for maintenance. Please check back shortly.' ?></p>
<p class="small" style="color:#5f6368">Your cart and orders are safe.</p>
