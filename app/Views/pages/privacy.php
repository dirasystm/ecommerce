<?php
use Waimart\Core\View;

ob_start(); ?>
<p>We respect your privacy and handle personal data in line with the Kenya Data Protection Act, 2019.</p>

<h2>What we collect</h2>
<ul>
    <li>Contact details you give us: name, phone number and (optionally) email address.</li>
    <li>Delivery details: county, town, location and instructions; or your chosen pickup station.</li>
    <li>Order and payment records, including M-Pesa transaction codes you submit. We never ask for or store your M-Pesa PIN.</li>
    <li>Account details if you register, such as saved addresses, wishlist and reviews.</li>
</ul>

<h2>How we use it</h2>
<ul>
    <li>To process, deliver and support your orders, and to verify payments.</li>
    <li>To send order and service notifications. Marketing messages are sent only if you opt in.</li>
    <li>To keep the store secure and prevent fraud.</li>
</ul>

<h2>Your choices and rights</h2>
<ul>
    <li>You can update your profile and notification preferences in your account.</li>
    <li>You may request access to, correction of, or deletion of your personal data, subject to records we must keep by law.</li>
</ul>

<h2>Security</h2>
<p>Passwords are stored using strong one-way hashing. Order-tracking links and collection codes are random and private to you.
    Staff access to customer data is restricted by role and logged.</p>
<?php
$body = (string) ob_get_clean();
echo View::partial('pages/_shell', compact('title', 'slug', 'body'));
